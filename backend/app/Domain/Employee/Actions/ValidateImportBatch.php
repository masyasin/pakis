<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Employee\Enums\ImportRowStatus;
use App\Domain\Employee\Enums\ImportStatus;
use App\Domain\Employee\Import\ImportFile;
use App\Domain\Employee\Import\RowKey;
use App\Domain\Employee\Import\RowNormalizer;
use App\Domain\Employee\Import\RowValidator;
use App\Domain\Employee\Models\ImportBatch;
use App\Domain\Employee\Models\ImportRow;
use App\Jobs\ValidateImportBatchJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Langkah 3 impor (BR-054): validasi setiap baris berkas dengan pemetaan batch, simpan hasilnya di
 * `import_rows` (galat per sel, NIK termasking), deteksi kunci ganda dalam berkas. Berkas kecil
 * divalidasi langsung; berkas besar lewat antrean `imports` agar request tidak menunggu lama.
 */
final class ValidateImportBatch
{
    /** Batas teknis T-027: di atas ini validasi berjalan di antrean, bukan di request. */
    public const SYNC_LIMIT = 200;

    private const CHUNK = 200;

    /** Mulai validasi setelah pemetaan disimpan (status sudah `mapped`). */
    public function start(ImportBatch $batch, User $actor): void
    {
        if ($batch->total_rows > self::SYNC_LIMIT) {
            ValidateImportBatchJob::dispatch($batch->id, $actor->id)->onQueue('imports');

            return;
        }

        $this->handle($batch, $actor);
    }

    public function handle(ImportBatch $batch, User $actor): void
    {
        $columns = $batch->columnMapping();

        // Lintasan pertama: nomor pegawai di berkas, agar atasan yang ada di berkas yang sama dikenali.
        $numbers = [];
        if (in_array('supervisor_number', $columns, true) && in_array('employee_number', $columns, true)) {
            $numberColumn = (int) array_search('employee_number', $columns, true);
            foreach (ImportFile::dataRows($batch) as $cells) {
                $number = RowNormalizer::code($cells[$numberColumn] ?? null);
                if ($number !== null) {
                    $numbers[] = $number;
                }
            }
        }

        $validator = new RowValidator($actor, $numbers);
        $seen = [];
        $buffer = [];
        $valid = 0;
        $invalid = 0;

        DB::transaction(function () use ($batch, $columns, $validator, &$seen, &$buffer, &$valid, &$invalid, $actor) {
            ImportRow::query()->where('import_batch_id', $batch->id)->delete();

            foreach (ImportFile::dataRows($batch) as $number => $cells) {
                $result = $validator->validate(ImportFile::mapRow($cells, $columns));
                $errors = $result['errors'];
                $key = $result['key'];

                if ($key !== null) {
                    if (isset($seen[$key])) {
                        $errors['_row'][] = 'Duplikat dengan baris '.$seen[$key].' ('.RowKey::describe($key).'). Hanya baris pertama yang dipakai.';
                    } else {
                        $seen[$key] = $number;
                    }
                }

                $errors === [] ? $valid++ : $invalid++;
                $buffer[] = [
                    'import_batch_id' => $batch->id,
                    'row_number' => $number,
                    'row_key' => $key,
                    'data' => json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'errors' => $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'status' => ($errors === [] ? ImportRowStatus::Valid : ImportRowStatus::Invalid)->value,
                ];

                if (count($buffer) >= self::CHUNK) {
                    ImportRow::query()->insert($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                ImportRow::query()->insert($buffer);
            }

            $batch->forceFill([
                'status' => ImportStatus::Validated,
                'total_rows' => $valid + $invalid,
                'valid_rows' => $valid,
                'error_rows' => $invalid,
                'updated_by' => $actor->id,
            ])->save();
        });
    }
}
