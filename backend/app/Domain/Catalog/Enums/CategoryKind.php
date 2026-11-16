<?php

namespace App\Domain\Catalog\Enums;

/**
 * Jenis kategori produk (`product_categories.kind`, 15 §4.4). Nilai identik dengan ENUM DB.
 * Moluksida & rodentisida ditambah sesuai kategori katalog Pakis (keputusan Mas 16 Nov 2026, PA-45).
 */
enum CategoryKind: string
{
    case Insecticide = 'insecticide';
    case Fungicide = 'fungicide';
    case Herbicide = 'herbicide';
    case Molluscicide = 'molluscicide';
    case Rodenticide = 'rodenticide';
    case Pgr = 'pgr';
    case Seed = 'seed';
    case Fertilizer = 'fertilizer';
    case Adjuvant = 'adjuvant';
    case Tool = 'tool';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Insecticide => 'Insektisida',
            self::Fungicide => 'Fungisida',
            self::Herbicide => 'Herbisida',
            self::Molluscicide => 'Moluksida',
            self::Rodenticide => 'Rodentisida',
            self::Pgr => 'Zat pengatur tumbuh',
            self::Seed => 'Benih',
            self::Fertilizer => 'Pupuk',
            self::Adjuvant => 'Perekat/perata',
            self::Tool => 'Alat pertanian',
            self::Other => 'Lainnya',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $k) => ['value' => $k->value, 'label' => $k->label()], self::cases());
    }
}
