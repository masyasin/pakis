<?php

namespace App\Domain\Pricing\Enums;

/** Jenis daftar harga (`price_lists.kind`, 15 §4.7). Urutan resolusi BR-168: store → kios (tier) → area → default. */
enum PriceListKind: string
{
    case Tier = 'tier';
    case Area = 'area';
    case Store = 'store';
    case Default = 'default';

    public function label(): string
    {
        return match ($this) {
            self::Tier => 'Tingkat pembeli',
            self::Area => 'Area penjualan',
            self::Store => 'Toko (POS)',
            self::Default => 'Bawaan',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $k) => ['value' => $k->value, 'label' => $k->label()], self::cases());
    }
}
