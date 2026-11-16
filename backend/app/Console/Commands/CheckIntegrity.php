<?php

namespace App\Console\Commands;

use App\Domain\Audit\Support\MonitoringAlert;
use App\Support\Integrity\FileIntegrity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Cek integritas berkas harian (SEC-070, T-045). `--write-manifest` dijalankan skrip deploy setelah
 * kode rilis terpasang (T-046). Setiap temuan → alert `monitoring.alert_emails` + kode keluar 1
 * agar terlihat di log scheduler/cron.
 */
#[Signature('integrity:check {--write-manifest : Tulis manifest hash rilis (saat deploy)}')]
#[Description('Bandingkan hash app/ & public/ dengan manifest rilis dan cari berkas .php asing')]
class CheckIntegrity extends Command
{
    /** Batas jalur per kategori di email agar tetap terbaca; jumlah lengkap tetap disebut. */
    private const MAX_LISTED = 20;

    public function handle(FileIntegrity $integrity, MonitoringAlert $alert): int
    {
        if ($this->option('write-manifest')) {
            $this->info('Manifest ditulis: '.$integrity->writeManifest().' berkas.');

            return self::SUCCESS;
        }

        $result = $integrity->check();
        $lines = [];
        foreach ([
            'php' => 'Berkas PHP asing (public/ atau storage/)',
            'changed' => 'Berkas berubah dari rilis',
            'added' => 'Berkas baru (tidak ada di rilis)',
            'missing' => 'Berkas rilis hilang',
        ] as $key => $label) {
            $paths = $result[$key];
            if ($paths === []) {
                continue;
            }
            $shown = array_slice($paths, 0, self::MAX_LISTED);
            $more = count($paths) > self::MAX_LISTED ? ' (+'.(count($paths) - self::MAX_LISTED).' lainnya)' : '';
            $lines[] = $label.' — '.count($paths).': '.implode(', ', $shown).$more;
        }

        if (! $result['manifest']) {
            // Tanpa manifest hanya cek .php asing yang bisa dilakukan; tetap diberi tahu agar
            // langkah deploy yang terlewat tidak diam-diam mematikan kontrol ini.
            $this->warn('Manifest rilis tidak ditemukan: jalankan `integrity:check --write-manifest` saat deploy.');
        }

        if ($lines === []) {
            $this->info('Integritas berkas: tidak ada temuan.');

            return self::SUCCESS;
        }

        $alert->send('Integritas berkas', $lines);
        foreach ($lines as $line) {
            $this->error($line);
        }

        return self::FAILURE;
    }
}
