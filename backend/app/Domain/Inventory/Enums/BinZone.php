<?php

namespace App\Domain\Inventory\Enums;

/**
 * Zona rak (`stock_bins.zone`, 15 §4.3, FR-A04). Berbeda dengan zona simpan bawaan kategori
 * (`Catalog\Enums\StorageZone`): hanya rak yang bisa berzona karantina (BR-164).
 */
enum BinZone: string
{
    case General = 'general';
    case Pesticide = 'pesticide';
    case Restricted = 'restricted';
    case Quarantine = 'quarantine';

    public function label(): string
    {
        return match ($this) {
            self::General => 'Umum',
            self::Pesticide => 'Pestisida',
            self::Restricted => 'Terbatas',
            self::Quarantine => 'Karantina',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $z) => ['value' => $z->value, 'label' => $z->label()], self::cases());
    }
}
