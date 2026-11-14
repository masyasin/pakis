<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retensi audit & log keamanan (SEC-040). Satu-satunya jalan menghapus baris append-only:
 * variabel sesi `@audit_purge = 1` dibuka hanya selama penghapusan dan SELALU ditutup lagi
 * (finally), agar koneksi yang sama (mis. worker berumur panjang) tidak tetap "terbuka".
 */
#[Signature('audit:purge')]
#[Description('Hapus audit_logs & security_events yang melewati masa simpan')]
class PurgeAuditLogs extends Command
{
    public function handle(): int
    {
        $auditCutoff = now()->subYears((int) setting('retention.audit_years'));
        $securityCutoff = now()->subDays((int) setting('retention.security_days'));

        DB::statement('SET @audit_purge = 1');

        try {
            $audit = DB::table('audit_logs')->where('occurred_at', '<', $auditCutoff)->delete();
            $security = DB::table('security_events')->where('occurred_at', '<', $securityCutoff)->delete();
        } finally {
            DB::statement('SET @audit_purge = NULL');
        }

        $this->info("Audit dihapus: {$audit}, log keamanan dihapus: {$security}.");

        return self::SUCCESS;
    }
}
