<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Pricing\Enums\PromotionFunding;
use App\Domain\Pricing\Enums\PromotionStatus;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Models\PromotionScope;
use App\Domain\Pricing\Models\PromotionTarget;
use App\Domain\Pricing\Models\PromotionTier;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tambah/ubah promo (FR-A20). Hanya `draft` yang bisa diubah: promo yang sudah diajukan/aktif
 * terkunci (`STATE_CONFLICT` 409); yang aktif hanya bisa diakhiri (EndPromotion). Target, cakupan,
 * dan tier diganti utuh. Periode diisi waktu lokal kantor lalu disimpan UTC.
 */
final class SavePromotion
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  sudah divalidasi PromotionRequest */
    public function handle(?Promotion $promotion, array $data, ?int $userId): Promotion
    {
        return DB::transaction(function () use ($promotion, $data, $userId) {
            $funding = PromotionFunding::from((string) $data['funded_by']);
            $values = [
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $data['type'],
                'starts_at' => self::utc((string) $data['starts_at']),
                'ends_at' => self::utc((string) $data['ends_at']),
                'priority' => (int) $data['priority'],
                'is_stackable' => (bool) $data['is_stackable'],
                'channels' => array_values(array_unique($data['channels'])),
                'budget_amount' => ($data['budget_amount'] ?? '') === '' ? null : $data['budget_amount'],
                'funded_by' => $funding,
                'principal_id' => $funding === PromotionFunding::Company ? null : $data['principal_id'],
                'principal_share_pct' => $funding === PromotionFunding::Shared ? $data['principal_share_pct'] : null,
                'description' => $data['description'] ?? null,
            ];

            if ($promotion === null) {
                $saved = (new Promotion)->forceFill([...$values, 'status' => PromotionStatus::Draft, 'created_by' => $userId, 'updated_by' => $userId]);
                $saved->save();
                $before = null;
            } else {
                $saved = Promotion::query()->lockForUpdate()->findOrFail($promotion->id);
                if ($saved->status !== PromotionStatus::Draft) {
                    throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $saved->status->value],
                        'Promo yang sudah diajukan atau aktif tidak bisa diubah. Promo aktif hanya bisa diakhiri.');
                }
                $before = $saved->audited();
                $saved->forceFill([...$values, 'updated_by' => $userId])->save();
            }

            PromotionTarget::query()->where('promotion_id', $saved->id)->delete();
            PromotionScope::query()->where('promotion_id', $saved->id)->delete();
            PromotionTier::query()->where('promotion_id', $saved->id)->delete();
            foreach ($data['targets'] as $t) {
                (new PromotionTarget)->forceFill(['promotion_id' => $saved->id, 'target_type' => $t['type'], 'target_id' => (int) $t['id']])->save();
            }
            foreach ($data['scopes'] as $s) {
                (new PromotionScope)->forceFill(['promotion_id' => $saved->id, 'scope_type' => $s['type'], 'scope_id' => $s['type'] === 'all' ? null : (int) $s['id']])->save();
            }
            foreach ($data['tiers'] as $t) {
                (new PromotionTier)->forceFill([
                    'promotion_id' => $saved->id,
                    'min_qty_base' => self::blank($t['min_qty_base'] ?? null),
                    'min_amount' => self::blank($t['min_amount'] ?? null),
                    'discount_pct' => self::blank($t['discount_pct'] ?? null),
                    'discount_amount' => self::blank($t['discount_amount'] ?? null),
                    'bundle_price' => self::blank($t['bundle_price'] ?? null),
                    'free_product_id' => self::blank($t['free_product_id'] ?? null),
                    'free_qty_base' => self::blank($t['free_qty_base'] ?? null),
                ])->save();
            }

            $this->audit->record($before === null ? 'promotion.created' : 'promotion.updated', $saved, $before, [
                ...$saved->audited(), 'targets' => count($data['targets']), 'scopes' => count($data['scopes']), 'tiers' => count($data['tiers']),
            ]);

            return $saved;
        });
    }

    private static function utc(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, DisplayTime::timezone())->utc();
    }

    private static function blank(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
