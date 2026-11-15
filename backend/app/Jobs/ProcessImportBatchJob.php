<?php

namespace App\Jobs;

use App\Domain\Employee\Actions\ProcessImportBatch;
use App\Domain\Employee\Enums\ImportStatus;
use App\Domain\Employee\Models\ImportBatch;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Proses batch impor besar (> ValidateImportBatch::SYNC_LIMIT baris valid) di antrean `imports`
 * (T-028). Hanya ID yang diserialisasi: isi berkas dan data pegawai tidak pernah masuk payload.
 */
class ProcessImportBatchJob implements ShouldQueue
{
    use Queueable;

    /** Tidak diulang otomatis: baris yang sudah diproses tidak boleh diproses dua kali dalam satu batch. */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $batchId, public readonly int $actorId) {}

    public function handle(ProcessImportBatch $action): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        $actor = User::query()->find($this->actorId);

        if ($batch === null || $batch->status !== ImportStatus::Processing) {
            return;
        }
        if ($actor === null) {
            $action->markFailed($batch, null);

            return;
        }

        // Audit (`employee.created`, dst.) membaca pelaku dari request; di antrean tidak ada sesi.
        // Worker berumur panjang: pengguna WAJIB dilepas lagi, kalau tidak job berikutnya di proses
        // yang sama mewarisi identitas ini (audit salah atribusi).
        $guard = Auth::guard();
        Auth::setUser($actor);

        try {
            $action->handle($batch, $actor);
        } finally {
            $guard->forgetUser();
        }
    }

    /** Worker mati/timeout: batch jangan tertahan `processing` selamanya (06 §7.4 → `failed`). */
    public function failed(?Throwable $e): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        if ($batch !== null) {
            app(ProcessImportBatch::class)->markFailed($batch, User::query()->find($this->actorId));
        }
    }
}
