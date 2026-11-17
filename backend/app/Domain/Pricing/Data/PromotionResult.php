<?php

namespace App\Domain\Pricing\Data;

/**
 * Hasil PromotionEngine: per baris (total setelah promo, potongan, promo yang mengenai) + baris
 * bonus (beli X gratis Y, harga 0) + total dokumen. Semua uang string 2 desimal.
 */
final readonly class PromotionResult
{
    /**
     * @param  list<array{key: string, unit_price: string, total: string, discount: string, applied: list<string>}>  $lines
     * @param  list<array{promotion_id: int, code: string, product_id: int, qty_base: string}>  $bonus
     * @param  list<AppliedPromotion>  $applied
     */
    public function __construct(
        public array $lines,
        public array $bonus,
        public array $applied,
        public string $subtotal,
        public string $discount,
        public string $total,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lines' => $this->lines,
            'bonus' => $this->bonus,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'total' => $this->total,
        ];
    }
}
