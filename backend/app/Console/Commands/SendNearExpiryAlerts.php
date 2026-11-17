<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actions\NearExpiryAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Alert batch mendekati ED per ambang (BR-157, 15 §11.1). Harian 06.00 waktu lokal, idempoten. */
#[Signature('inventory:near-expiry-alerts')]
#[Description('Kirim peringatan batch mendekati ED, sekali per batch per ambang')]
class SendNearExpiryAlerts extends Command
{
    public function handle(NearExpiryAlerts $alerts): int
    {
        $result = $alerts->handle();
        Log::info('inventory:near-expiry-alerts', $result);
        $this->info("Batch diperiksa: {$result['checked']} · notifikasi: {$result['sent']}");

        return self::SUCCESS;
    }
}
