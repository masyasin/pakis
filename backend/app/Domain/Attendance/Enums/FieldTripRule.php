<?php

namespace App\Domain\Attendance\Enums;

/**
 * Aturan lokasi absen selama tugas luar kota (BR-071), dipilih per tugas (keputusan Mas
 * 15 Nov 2026). Berlaku DI SAMPING aturan biasa BR-064, bukan menggantikannya.
 */
enum FieldTripRule: string
{
    case Anywhere = 'anywhere';
    case Destination = 'destination';
    case AnyLocation = 'any_location';

    public function label(): string
    {
        return match ($this) {
            self::Anywhere => 'Di mana saja',
            self::Destination => 'Radius titik tujuan',
            self::AnyLocation => 'Lokasi Pakis mana pun',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $r) => [$r->value => $r->label()])->all();
    }
}
