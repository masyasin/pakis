<?php

namespace App\Domain\Inventory\Enums;

/** Tipe lokasi stok (`stock_locations.type`, 15 §4.3, ADR-107). Nilai identik dengan ENUM DB. */
enum StockLocationType: string
{
    case Warehouse = 'warehouse';
    case Store = 'store';
    case Vehicle = 'vehicle';
    case InTransit = 'in_transit';

    public function label(): string
    {
        return match ($this) {
            self::Warehouse => 'Gudang',
            self::Store => 'Toko',
            self::Vehicle => 'Kendaraan canvas',
            self::InTransit => 'Dalam perjalanan',
        };
    }

    /**
     * Tipe yang dibuat lewat form lokasi stok. `vehicle` hanya lewat kendaraan (centang canvas),
     * `in_transit` hanya lewat seeder (lokasi sistem TRANSIT).
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Warehouse, self::Store];
    }

    /** Gudang & toko punya rak (15 §4.8: kendaraan & TRANSIT selalu tanpa bin). */
    public function hasBins(): bool
    {
        return in_array($this, self::manual(), true);
    }

    /**
     * @param  list<self>|null  $cases
     * @return list<array{value: string, label: string}>
     */
    public static function options(?array $cases = null): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], $cases ?? self::cases());
    }
}
