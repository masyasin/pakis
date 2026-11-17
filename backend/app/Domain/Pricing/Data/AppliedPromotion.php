<?php

namespace App\Domain\Pricing\Data;

/** Satu promo yang mengenai satu baris beserta potongan nominalnya (0 untuk beli X gratis Y). */
final readonly class AppliedPromotion
{
    public function __construct(
        public int $promotionId,
        public string $code,
        public string $lineKey,
        public string $discount,
    ) {}
}
