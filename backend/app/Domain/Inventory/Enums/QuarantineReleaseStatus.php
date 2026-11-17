<?php

namespace App\Domain\Inventory\Enums;

/** Status permintaan lepas karantina (`stock_quarantine_releases.status`, BR-164, PA-69). */
enum QuarantineReleaseStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu persetujuan',
            self::Approved => 'Dilepas',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Ditarik',
        };
    }
}
