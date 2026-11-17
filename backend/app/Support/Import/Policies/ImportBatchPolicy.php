<?php

namespace App\Support\Import\Policies;

use App\Models\User;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportRegistry;

/**
 * Langkah per batch (`impor/{batch}/…`) dipakai semua tipe impor (BR-170, T-085A): boleh bila
 * batch terlihat oleh pengguna (06 §5: scope `all` atau pengunggahnya sendiri) DAN pengguna
 * memegang izin tipe batch itu (pegawai = `employees.import`; tipe master juga `master_imports.manage`,
 * T-085). Batch yang tidak terlihat sudah
 * 404 di route binding sebelum policy ini dipanggil (SEC-033).
 */
class ImportBatchPolicy
{
    public function manage(User $user, ImportBatch $batch): bool
    {
        return $batch->isVisibleTo($user)
            && ImportRegistry::has($batch->type)
            && ImportRegistry::allows($user, ImportRegistry::forBatch($batch));
    }
}
