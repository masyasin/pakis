<?php

namespace App\Domain\Pricing\Data;

/**
 * Baris dokumen yang sudah berharga (hasil PriceResolver) sebagai masukan PromotionEngine. `qty`
 * dalam satuan baris, `factor` = isi satuan itu dalam satuan dasar, `unitPrice` per satuan baris.
 */
final readonly class PricedLine
{
    public function __construct(
        public string $key,
        public int $productId,
        public int $categoryId,
        public ?int $principalId,
        public string $qty,
        public string $factor,
        public string $unitPrice,
    ) {}
}
