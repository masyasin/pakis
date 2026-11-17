<?php

namespace App\Domain\Pricing\Data;

/** Satu tier promo (15 §4.7 `promotion_tiers`), string desimal. Ambang: qty satuan dasar dan/atau nominal. */
final readonly class PromotionTierData
{
    public function __construct(
        public ?string $minQtyBase,
        public ?string $minAmount,
        public ?string $discountPct,
        public ?string $discountAmount,
        public ?string $bundlePrice,
        public ?int $freeProductId,
        public ?string $freeQtyBase,
    ) {}
}
