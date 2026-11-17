<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actions\TransferReceiveReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Pengingat mutasi belum diterima (BR-161, 15 §11.1). Tiap jam, sekali per mutasi per hari. */
#[Signature('inventory:transfer-reminders')]
#[Description('Ingatkan penerima mutasi yang belum diterima melewati batas jam')]
class SendTransferReceiveReminders extends Command
{
    public function handle(TransferReceiveReminders $reminders): int
    {
        $result = $reminders->handle();
        Log::info('inventory:transfer-reminders', $result);
        $this->info("Mutasi diperiksa: {$result['checked']} · pengingat: {$result['sent']}");

        return self::SUCCESS;
    }
}
