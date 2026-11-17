<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actions\ReleaseExpiredReservations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Lepas reservasi stok yang lewat masa berlaku (BR-155, 15 §11.1). Tiap 15 menit, idempoten. */
#[Signature('inventory:release-reservations')]
#[Description('Lepas reservasi stok aktif yang sudah lewat masa berlakunya')]
class ReleaseReservations extends Command
{
    public function handle(ReleaseExpiredReservations $release): int
    {
        $count = $release->handle();
        Log::info('inventory:release-reservations', ['released' => $count]);
        $this->info("Reservasi dilepas: {$count}");

        return self::SUCCESS;
    }
}
