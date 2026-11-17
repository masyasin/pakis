<?php

namespace App\Domain\Inventory\Enums;

/** Cakupan opname (`stock_opnames.scope`, 15 §4.10). `scope_ids` = id rak/kategori/produk. */
enum StockOpnameScope: string
{
    case Full = 'full';
    case Bin = 'bin';
    case Category = 'category';
    case Product = 'product';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Seluruh lokasi',
            self::Bin => 'Per rak',
            self::Category => 'Per kategori',
            self::Product => 'Per produk',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
