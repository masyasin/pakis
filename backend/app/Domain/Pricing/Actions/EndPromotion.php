<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Pricing\Enums\PromotionStatus;
use App\Domain\Pricing\Models\Promotion;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Akhiri promo aktif (15 §6.6): manual oleh `promotions.manage`, atau sistem (EndPromotions) saat
 * lewat `ends_at`/anggaran habis. Satu-satunya perubahan yang boleh pada promo aktif.
 */
final class EndPromotion
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Promotion $promotion, ?int $userId, string $reason = 'manual'): Promotion
    {
        return DB::transaction(function () use ($promotion, $userId, $reason) {
            $locked = Promotion::query()->lockForUpdate()->findOrFail($promotion->id);
            if ($locked->status !== PromotionStatus::Active) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Hanya promo aktif yang bisa diakhiri.');
            }
            $before = $locked->audited();
            $locked->forceFill(['status' => PromotionStatus::Ended, 'updated_by' => $userId ?? $locked->updated_by])->save();
            $this->audit->record('promotion.ended', $locked, $before, [...$locked->audited(), 'reason' => $reason]);

            return $locked;
        });
    }
}
