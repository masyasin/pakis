<?php

namespace App\Domain\Approval\Enums;

/**
 * Status langkah (15 §4.2 `approval_steps.status`): `waiting` belum gilirannya, `pending` langkah
 * aktif, `skipped` tidak pernah dijalankan karena permintaan ditolak/dibatalkan lebih dulu.
 */
enum StepStatus: string
{
    case Waiting = 'waiting';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Belum giliran',
            self::Pending => 'Menunggu',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Skipped => 'Dilewati',
        };
    }

    public function isDecided(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }
}
