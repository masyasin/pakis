<?php

namespace App\Support\Import\Actions;

use App\Models\User;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportFile;
use App\Support\Import\ImportStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Batalkan impor (06 §7.4: dari `uploaded`/`mapped`/`validated`). Berkas asli dihapus dari disk
 * karena berisi data pegawai lengkap (termasuk NIK) yang tidak lagi diperlukan; ringkasan baris
 * (NIK termasking) tetap ada sebagai riwayat.
 */
final class CancelImport
{
    public function handle(User $actor, ImportBatch $batch): ImportBatch
    {
        $batch = DB::transaction(function () use ($actor, $batch) {
            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => 'Impor yang sudah '.mb_strtolower($locked->status->label()).' tidak bisa dibatalkan.',
                ]);
            }

            $locked->forceFill(['status' => ImportStatus::Cancelled, 'updated_by' => $actor->id])->save();

            return $locked;
        });

        Storage::disk(ImportFile::DISK)->delete($batch->stored_path);

        return $batch;
    }
}
