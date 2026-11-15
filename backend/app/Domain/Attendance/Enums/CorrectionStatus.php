<?php

namespace App\Domain\Attendance\Enums;

/** Status koreksi presensi (04 §2.13 `attendance_corrections.status`, transisi 06 §7.1, label 07 §6). */
enum CorrectionStatus: string
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

    /** @return list<array{value: string, label: string}> untuk filter listing */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
