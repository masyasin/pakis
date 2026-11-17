<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actions\ExpireBatches;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Kedaluwarsa otomatis batch (BR-157, 15 §11.1). Harian 00.15 waktu lokal, idempoten. */
#[Signature('inventory:expire-batches')]
#[Description('Tandai batch tersedia yang ED-nya hari ini atau sudah lewat sebagai kedaluwarsa')]
class ExpireStockBatches extends Command
{
    public function handle(ExpireBatches $expire): int
    {
        $result = $expire->handle();
        Log::info('inventory:expire-batches', $result);
        $this->info("Batch kedaluwarsa: {$result['expired']} · lokasi: {$result['locations']}");

        return self::SUCCESS;
    }
}
