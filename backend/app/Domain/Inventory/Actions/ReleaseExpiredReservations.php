<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockReservation;

/**
 * Lepas reservasi `active` yang lewat `expires_at` (BR-155, 15 §11.1, tiap 15 menit). Satu transaksi
 * per reservasi agar satu batch yang sedang terkunci lama tidak menahan yang lain; `release()`
 * idempoten, jadi reservasi yang baru saja dikonsumsi dokumennya dilewati tanpa galat.
 */
final class ReleaseExpiredReservations
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function handle(): int
    {
        $released = 0;
        StockReservation::query()->where('status', ReservationStatus::Active)->where('expires_at', '<', now())
            // lazyById: baris yang sudah dilepas keluar dari filter, paging offset akan melompatinya.
            ->lazyById(200)->each(function (StockReservation $reservation) use (&$released) {
                if (StockLedger::transaction(fn () => $this->ledger->release($reservation))) {
                    $released++;
                }
            });

        return $released;
    }
}
