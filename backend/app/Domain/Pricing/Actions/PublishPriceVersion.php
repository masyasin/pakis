<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Pricing\Enums\PriceVersionStatus;
use App\Domain\Pricing\Models\PriceListVersion;
use Illuminate\Support\Facades\DB;

/**
 * Terbitkan versi (ADR-108, BR-168). Dipanggil SubmitPriceVersion (pelaku `prices.publish`) atau
 * PriceListPublish::onApproved (mesin approval, di dalam transaksi keputusan). Versi terbit
 * sebelumnya mendapat `effective_to` = `effective_from` versi ini, sehingga resolusi pada waktu
 * mana pun memilih tepat satu versi; status `superseded` ditandai segera bila waktunya sudah tiba,
 * atau oleh `pricing:activate-versions` saat tiba.
 */
final class PublishPriceVersion
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PriceListVersion $version, ?int $publisherId): PriceListVersion
    {
        return DB::transaction(function () use ($version, $publisherId) {
            $locked = PriceListVersion::query()->lockForUpdate()->findOrFail($version->id);
            $before = $locked->audited();

            $previous = PriceListVersion::query()
                ->where('price_list_id', $locked->price_list_id)
                ->where('id', '!=', $locked->id)
                ->whereIn('status', [PriceVersionStatus::Published, PriceVersionStatus::Superseded])
                ->where('effective_from', '<', $locked->effective_from)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $locked->effective_from))
                ->lockForUpdate()->get();
            foreach ($previous as $old) {
                $oldBefore = $old->audited();
                $old->forceFill([
                    'effective_to' => $locked->effective_from,
                    'status' => $locked->effective_from->lessThanOrEqualTo(now()) ? PriceVersionStatus::Superseded : $old->status,
                ])->save();
                $this->audit->record('price_version.superseded', $old, $oldBefore, $old->audited());
            }

            $locked->forceFill([
                'status' => PriceVersionStatus::Published,
                'published_by' => $publisherId,
                'published_at' => now(),
            ])->save();
            $this->audit->record('price_version.published', $locked, $before, $locked->audited());

            return $locked;
        });
    }
}
