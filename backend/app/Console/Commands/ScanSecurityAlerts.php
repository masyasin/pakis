<?php

namespace App\Console\Commands;

use App\Domain\Audit\Support\SecurityAlertScanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Kirim alert SEC-041 dari log keamanan (aturan di SecurityAlertScanner), dijadwalkan tiap 5 menit. */
#[Signature('security:scan-alerts')]
#[Description('Pindai log keamanan dan kirim alert SEC-041 ke monitoring.alert_emails')]
class ScanSecurityAlerts extends Command
{
    public function handle(SecurityAlertScanner $scanner): int
    {
        $this->info('Alert dikirim: '.$scanner->scan().'.');

        return self::SUCCESS;
    }
}
