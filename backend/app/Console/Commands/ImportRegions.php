<?php

namespace App\Console\Commands;

use App\Domain\Region\Import\RegionImporter;
use App\Domain\Region\Import\SintechRegionParser;
use Illuminate\Console\Command;

/**
 * Impor master wilayah dari dump SQL (FR-021, OQ-003). Idempoten: aman dijalankan ulang saat
 * data wilayah diperbarui. Berkas dibaca sebagai teks, TIDAK dijalankan ke database.
 *
 *     php artisan regions:import konsep/region.sql
 */
class ImportRegions extends Command
{
    protected $signature = 'regions:import
        {path : Berkas SQL wilayah}
        {--format=sintech : Format sumber (saat ini: sintech)}';

    protected $description = 'Impor/perbarui master wilayah (provinsi, kab/kota, kecamatan, desa) dari dump SQL';

    public function handle(RegionImporter $importer): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Berkas tidak ditemukan atau tidak bisa dibaca: {$path}");

            return self::FAILURE;
        }

        $parser = match ($this->option('format')) {
            'sintech' => new SintechRegionParser,
            default => null,
        };
        if ($parser === null) {
            $this->error('Format tidak dikenal: '.$this->option('format').'. Pilihan: sintech.');

            return self::FAILURE;
        }

        $started = microtime(true);
        $result = $importer->import($parser->parse($path));
        $c = $result['counts'];

        $this->info(sprintf(
            'Impor wilayah selesai dalam %.1f detik: %s provinsi, %s kab/kota, %s kecamatan, %s desa/kelurahan.',
            microtime(true) - $started,
            number_format($c['province'], 0, ',', '.'),
            number_format($c['regency'], 0, ',', '.'),
            number_format($c['district'], 0, ',', '.'),
            number_format($c['village'], 0, ',', '.'),
        ));

        if ($result['skipped'] > 0) {
            // Dilewati tidak menggagalkan impor: sisanya tetap tersimpan, tetapi wajib terlihat.
            $this->warn("{$result['skipped']} baris dilewati:");
            foreach ($result['samples'] as $sample) {
                $this->line("  - {$sample}");
            }
            if ($result['skipped'] > count($result['samples'])) {
                $this->line('  - … dan '.($result['skipped'] - count($result['samples'])).' lainnya');
            }
        }

        return self::SUCCESS;
    }
}
