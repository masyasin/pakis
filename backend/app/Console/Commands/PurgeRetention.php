<?php

namespace App\Console\Commands;

use App\Domain\Settings\SettingRegistry;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportFile;
use App\Support\Import\ImportStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retensi data operasional (08 §2.5, NFR-011, T-045) di luar audit (`audit:purge`), selfie
 * (`attendance:purge-selfies`), dan ekspor (`exports:expire`):
 *   - `notification_deliveries` lebih tua dari `retention.security_days` (04 §2.12);
 *   - batch impor lebih tua dari `retention.import_days` beserta baris (`import_rows`, cascade) dan
 *     berkas aslinya. Batch yang sedang `processing` tidak disentuh.
 * Batas bawah dari registri agar nilai rusak tidak menghapus data baru.
 */
#[Signature('retention:purge')]
#[Description('Hapus riwayat kirim notifikasi & batch impor yang melewati masa simpan')]
class PurgeRetention extends Command
{
    private const CHUNK = 200;

    public function handle(): int
    {
        $securityDays = self::days('retention.security_days');
        $deliveries = DB::table('notification_deliveries')->where('created_at', '<', now()->subDays($securityDays))->delete();

        $importDays = self::days('retention.import_days');
        $disk = Storage::disk(ImportFile::DISK);
        $batches = 0;
        ImportBatch::query()
            ->where('created_at', '<', now()->subDays($importDays))
            ->where('status', '!=', ImportStatus::Processing)
            ->select(['id', 'stored_path'])
            ->chunkById(self::CHUNK, function (Collection $chunk) use ($disk, &$batches) {
                $disk->delete($chunk->pluck('stored_path')->filter()->values()->all());
                $batches += ImportBatch::query()->whereKey($chunk->pluck('id'))->delete();
            });

        $this->info("Riwayat kirim notifikasi dihapus: {$deliveries}, batch impor dihapus: {$batches}.");

        return self::SUCCESS;
    }

    private static function days(string $key): int
    {
        return max((int) SettingRegistry::get($key)->min, (int) setting($key));
    }
}
