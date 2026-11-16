<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Actions\RefreshRegistrationStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Status & pengingat izin edar (BR-152, 15 §11.1). Harian 00:30 waktu lokal; idempoten, aman
 * dijalankan ulang manual (`reminder_logs` mencegah notifikasi ganda).
 */
#[Signature('catalog:refresh-registrations')]
#[Description('Tandai izin edar yang lewat masa berlaku sebagai habis dan kirim pengingat H-60/30/7/0')]
class RefreshRegistrations extends Command
{
    public function handle(RefreshRegistrationStatus $refresh): int
    {
        $result = $refresh->handle();
        Log::info('catalog:refresh-registrations', $result);
        $this->info("Izin habis: {$result['expired']} · pengingat terkirim: {$result['reminded']}");

        return self::SUCCESS;
    }
}
