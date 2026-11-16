<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\Import\Actions\ValidateImportBatch;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Validasi batch impor besar (> ValidateImportBatch::SYNC_LIMIT baris) di antrean `imports`
 * (T-027). Hanya ID yang diserialisasi: isi berkas dan data pegawai tidak pernah masuk payload.
 */
class ValidateImportBatchJob implements ShouldQueue
{
    use Queueable;

    /** Tidak diulang otomatis: kegagalan ditandai dan pengguna menyimpan pemetaan lagi untuk mencoba. */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $batchId, public readonly int $actorId) {}

    public function handle(ValidateImportBatch $action): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        $actor = User::query()->find($this->actorId);

        // Batch dibatalkan/diproses sejak job diantrekan: job lama tidak berlaku.
        if ($batch === null || $actor === null || $batch->status !== ImportStatus::Mapped) {
            return;
        }

        $action->handle($batch, $actor);
    }

    /**
     * 06 §7.4 tidak punya transisi `mapped → failed` (`failed` hanya dari `processing`), jadi batch
     * tetap `mapped` dengan penanda galat; menyimpan pemetaan lagi akan memvalidasi ulang.
     */
    public function failed(?Throwable $e): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        if ($batch === null || $batch->status !== ImportStatus::Mapped) {
            return;
        }

        $batch->forceFill([
            'mapping' => [...($batch->mapping ?? []), 'failure' => 'Validasi gagal di tengah jalan. Simpan pemetaan lagi untuk mencoba ulang.'],
        ])->save();
    }
}
