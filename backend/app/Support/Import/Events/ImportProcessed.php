<?php

namespace App\Support\Import\Events;

use App\Support\Import\ImportBatch;
use Illuminate\Foundation\Events\Dispatchable;

/** Proses impor berakhir (`completed`/`failed`, T-028). Dipakai notifikasi "Impor selesai" (BR-070). */
final class ImportProcessed
{
    use Dispatchable;

    public function __construct(public readonly ImportBatch $batch) {}
}
