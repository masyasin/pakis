<?php

namespace App\Domain\Inventory\Enums;

/** Status batch (`stock_batches.status`, 15 §4.8, BR-157/163/164). Nilai identik dengan ENUM DB. */
enum StockBatchStatus: string
{
    case Available = 'available';
    case Quarantine = 'quarantine';
    case Expired = 'expired';
    case Disposed = 'disposed';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Tersedia',
            self::Quarantine => 'Karantina',
            self::Expired => 'Kedaluwarsa',
            self::Disposed => 'Dimusnahkan',
        };
    }
}
