<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Pricing\Approvables\PriceListPublish;
use App\Domain\Pricing\Enums\PriceVersionStatus;
use App\Domain\Pricing\Models\PriceListVersion;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ajukan versi draf (15 §6.6, BR-168, BR-153):
 * - pelaku pemegang `prices.publish` → langsung terbit;
 * - selain itu → `pending_approval` lewat mesin approval (`price_list_publish`).
 * Syarat: versi berisi ≥ 1 harga (versi kosong yang terbit akan mengosongkan harga list ini),
 * `effective_from` setelah versi terbit/menunggu terakhir list yang sama, dan tidak berlaku surut
 * kecuali `pricing.allow_backdated_publish` + pelaku `prices.publish` + alasan tertulis.
 */
final class SubmitPriceVersion
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly PublishPriceVersion $publish,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(PriceListVersion $version, User $actor, ?string $backdateReason): PriceListVersion
    {
        return DB::transaction(function () use ($version, $actor, $backdateReason) {
            $locked = PriceListPublish::query()->with('priceList')->lockForUpdate()->findOrFail($version->id);
            if (! $locked->isDraft()) {
                throw new ApiError(ErrorCode::PRICE_VERSION_LOCKED, ['status' => $locked->status->value]);
            }
            if (! $locked->items()->exists()) {
                throw ValidationException::withMessages(['version' => 'Isi minimal satu harga sebelum versi diajukan.']);
            }

            $later = PriceListVersion::query()->where('price_list_id', $locked->price_list_id)->where('id', '!=', $locked->id)
                ->whereIn('status', [PriceVersionStatus::Published, PriceVersionStatus::PendingApproval, PriceVersionStatus::Superseded])
                ->where('effective_from', '>=', $locked->effective_from)->exists();
            if ($later) {
                throw ValidationException::withMessages(['effective_from' => 'Sudah ada versi terbit atau menunggu dengan tanggal berlaku yang sama atau lebih akhir. Ubah tanggal berlaku versi ini.']);
            }

            $canPublish = $actor->can('prices.publish');
            if ($locked->effective_from->lessThan(now()->subMinute())) {
                $reason = trim((string) $backdateReason);
                if (! (bool) setting('pricing.allow_backdated_publish') || ! $canPublish || $reason === '') {
                    throw ValidationException::withMessages(['effective_from' => 'Tanggal berlaku sudah lewat. Versi harga tidak boleh berlaku surut; ubah tanggal berlaku ke waktu yang akan datang.']);
                }
                $locked->note = trim(($locked->note ?? '').' [Berlaku surut: '.$reason.']');
            }

            $before = $locked->audited();
            $locked->forceFill(['status' => PriceVersionStatus::PendingApproval, 'updated_by' => $actor->id])->save();
            $this->audit->record('price_version.submitted', $locked, $before, $locked->audited());

            if ($canPublish) {
                // Pemegang izin terbit tidak perlu meminta persetujuan dirinya sendiri (15 §6.6).
                return $this->publish->handle($locked, $actor->id);
            }

            $request = $this->engine->request($locked, $actor);
            if ($request !== null) {
                $locked->forceFill(['approval_request_id' => $request->id])->save();
            }

            return $locked->refresh();
        });
    }
}
