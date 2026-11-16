<?php

namespace App\Domain\Catalog\Enums;

/**
 * Zona simpan bawaan kategori produk (`product_categories.default_bin_zone`, 15 §4.4). Sengaja
 * terpisah dari zona RAK gudang (`Inventory\Enums\BinZone`, T-068) yang juga punya karantina:
 * kategori tidak pernah berzona bawaan karantina.
 */
enum StorageZone: string
{
    case General = 'general';
    case Pesticide = 'pesticide';
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::General => 'Umum',
            self::Pesticide => 'Pestisida',
            self::Restricted => 'Terbatas',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $z) => ['value' => $z->value, 'label' => $z->label()], self::cases());
    }
}
