<?php

namespace App\Domain\Attendance\Enums;

/** Jam yang dikoreksi (04 §2.13 `attendance_corrections.type`, 05 §5.7). */
enum CorrectionType: string
{
    case CheckIn = 'check_in';
    case CheckOut = 'check_out';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::CheckIn => 'Masuk',
            self::CheckOut => 'Pulang',
            self::Both => 'Masuk & pulang',
        };
    }

    public function hasCheckIn(): bool
    {
        return $this !== self::CheckOut;
    }

    public function hasCheckOut(): bool
    {
        return $this !== self::CheckIn;
    }
}
