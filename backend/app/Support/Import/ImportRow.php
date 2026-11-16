<?php

namespace App\Support\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris berkas impor setelah dipetakan & divalidasi (04 §2.10). `data` hanya memuat nilai
 * terpetakan; NIK selalu termasking (SEC-010), jadi proses T-028 membaca ulang berkas per
 * `row_number` untuk NIK lengkap.
 *
 * @property int $id
 * @property int $import_batch_id
 * @property int $row_number
 * @property string|null $row_key
 * @property array<string, mixed> $data
 * @property array<string, list<string>>|null $errors
 * @property ImportRowStatus $status
 * @property-read ImportBatch $batch
 */
class ImportRow extends Model
{
    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'errors' => 'array',
            'status' => ImportRowStatus::class,
        ];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
