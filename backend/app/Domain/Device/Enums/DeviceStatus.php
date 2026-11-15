<?php

namespace App\Domain\Device\Enums;

/** Status perangkat (04 §2.12, transisi 06 §7.2). Nilai identik dengan ENUM DB. */
enum DeviceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Revoked = 'revoked';
    case Replaced = 'replaced';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu',
            self::Active => 'Aktif',
            self::Revoked => 'Dicabut',
            self::Replaced => 'Diganti',
            self::Rejected => 'Ditolak',
        };
    }

    /** @return array<string, string> nilai => label, untuk filter status. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
