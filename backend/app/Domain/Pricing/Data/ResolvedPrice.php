<?php

namespace App\Domain\Pricing\Data;

use App\Domain\Pricing\Enums\PriceListKind;
use App\Support\Money\Money;
use App\Support\Money\Qty;

/**
 * Hasil PriceResolver (BR-168, ADR-108). `priceListVersionId` disnapshot di baris dokumen jual
 * (Paket B) agar harga dokumen bisa ditelusuri ke versi yang dipakai walau harga kemudian berubah.
 */
final readonly class ResolvedPrice
{
    public function __construct(
        public Money $price,
        public int $priceListVersionId,
        public PriceListKind $sourceListKind,
        public Qty $minQty,
    ) {}

    /** @return array{price: string, price_list_version_id: int, source_list_kind: string, min_qty: string} */
    public function toArray(): array
    {
        return [
            'price' => (string) $this->price,
            'price_list_version_id' => $this->priceListVersionId,
            'source_list_kind' => $this->sourceListKind->value,
            'min_qty' => (string) $this->minQty,
        ];
    }
}
