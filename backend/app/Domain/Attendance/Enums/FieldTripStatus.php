<?php

namespace App\Domain\Attendance\Enums;

/** Status tugas luar kota (04 §2.13 `field_trips.status`, transisi 06 §7.2a, label 07 §6). */
enum FieldTripStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** @return array<string, string> nilai => label, untuk filter. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
