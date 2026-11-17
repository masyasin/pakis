<?php

namespace App\Domain\Inventory\Enums;

/** Status reservasi stok (`stock_reservations.status`, BR-155). Nilai identik dengan ENUM DB. */
enum ReservationStatus: string
{
    case Active = 'active';
    case Consumed = 'consumed';
    case Released = 'released';
}
