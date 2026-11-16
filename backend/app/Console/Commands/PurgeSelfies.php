<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Support\SelfieStore;
use App\Domain\Settings\SettingRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Retensi selfie absen (SEC-013, NFR-011, T-045): berkas foto log yang lebih tua dari
 * `attendance.selfie_retention_days` dihapus dari disk privat, `selfie_path` dikosongkan, dan
 * `selfie_purged_at` diisi. Baris log TETAP ada (bukti jam, lokasi, perangkat; 04 §2.13). Idempoten:
 * log yang sudah dipurge tidak disentuh lagi (index `att_logs_purge_idx`).
 */
#[Signature('attendance:purge-selfies')]
#[Description('Hapus selfie absen yang melewati masa simpan (baris log tetap ada)')]
class PurgeSelfies extends Command
{
    private const CHUNK = 500;

    public function handle(): int
    {
        // Batas bawah dari registri: nilai rusak/terlalu kecil tidak boleh menghapus foto baru.
        $days = max((int) SettingRegistry::get('attendance.selfie_retention_days')->min, (int) setting('attendance.selfie_retention_days'));
        $cutoff = now()->subDays($days);
        $disk = Storage::disk(SelfieStore::DISK);
        $purged = 0;

        AttendanceLog::query()
            ->whereNull('selfie_purged_at')
            ->whereNotNull('selfie_path')
            ->where('recorded_at', '<', $cutoff)
            ->select(['id', 'selfie_path'])
            ->chunkById(self::CHUNK, function (Collection $logs) use ($disk, &$purged) {
                $disk->delete($logs->pluck('selfie_path')->filter()->values()->all());
                // Kolom diubah langsung (bukan save model): log append-only, satu-satunya
                // perubahan yang sah adalah purge ini.
                AttendanceLog::query()->whereKey($logs->pluck('id'))->update([
                    'selfie_path' => null,
                    'selfie_purged_at' => now(),
                ]);
                $purged += $logs->count();
            });

        $this->info("Selfie dihapus: {$purged} (lebih tua dari {$days} hari).");

        return self::SUCCESS;
    }
}
