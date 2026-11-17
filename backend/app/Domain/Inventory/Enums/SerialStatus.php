<?php

namespace App\Domain\Inventory\Enums;

/** Status nomor seri (`serial_numbers.status`, 15 §4.9, BR-144). Nilai identik dengan ENUM DB. */
enum SerialStatus: string
{
    case InStock = 'in_stock';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Returned = 'returned';
    case InService = 'in_service';
    case ReturnedToPrincipal = 'returned_to_principal';
    case Scrapped = 'scrapped';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'Di stok',
            self::Reserved => 'Dipesan',
            self::Sold => 'Terjual',
            self::Returned => 'Retur pelanggan',
            self::InService => 'Servis',
            self::ReturnedToPrincipal => 'Retur ke prinsipal',
            self::Scrapped => 'Dimusnahkan',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
