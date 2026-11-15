<?php

namespace App\Domain\Organization\Enums;

/**
 * Mode absen (FR-024, BR-042); nilai identik dengan ENUM `positions.attendance_mode`
 * (dan `assignments.attendance_mode` di T-025). Mode efektif = penugasan ?? jabatan.
 */
enum AttendanceMode: string
{
    case Location = 'location';
    case AnyLocation = 'any_location';
    case Anywhere = 'anywhere';

    public function label(): string
    {
        return match ($this) {
            self::Location => 'Di lokasi tugas',
            self::AnyLocation => 'Di lokasi Pakis mana pun',
            self::Anywhere => 'Di mana saja',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Location => 'Presensi hanya diterima di dalam radius lokasi penugasan pegawai. Cocok untuk staf kantor, gudang, dan toko.',
            self::AnyLocation => 'Presensi diterima di dalam radius lokasi Pakis mana saja. Cocok untuk driver atau staf yang berpindah antar-lokasi.',
            self::Anywhere => 'Presensi diterima di mana saja selama GPS aktif (titik tetap dicatat). Cocok untuk sales lapangan.',
        };
    }

    /** @return list<array{value: string, label: string, help: string}> */
    public static function options(): array
    {
        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label(), 'help' => $m->help()], self::cases());
    }
}
