<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actions\ReconcileStockLedger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rekonsiliasi buku stok (BR-154, 15 §11.1). Harian 01:00 waktu lokal; hanya membaca dan memberi
 * tahu Owner & super_admin bila ada selisih (tidak pernah mengoreksi otomatis).
 */
#[Signature('inventory:reconcile')]
#[Description('Cocokkan saldo batch dengan kartu stok dan reservasi aktif; laporkan selisih')]
class ReconcileStock extends Command
{
    public function handle(ReconcileStockLedger $reconcile): int
    {
        $result = $reconcile->handle();
        Log::info('inventory:reconcile', ['batches' => $result['batches'], 'mismatches' => count($result['mismatches']), 'notified' => $result['notified']]);
        $this->info("Batch diperiksa: {$result['batches']} · selisih: ".count($result['mismatches']));

        return self::SUCCESS;
    }
}
