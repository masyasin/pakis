<?php

namespace App\Domain\Fleet\Enums;

/** Jenis kendaraan (`vehicles.vehicle_type`, 15 §4.3). Nilai identik dengan ENUM DB. */
enum VehicleType: string
{
    case Truck = 'truck';
    case Pickup = 'pickup';
    case Van = 'van';
    case Motorcycle = 'motorcycle';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Truck => 'Truk',
            self::Pickup => 'Pikap',
            self::Van => 'Van',
            self::Motorcycle => 'Sepeda motor',
            self::Other => 'Lainnya',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
