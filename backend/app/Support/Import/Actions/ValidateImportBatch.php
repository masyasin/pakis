<?php

namespace App\Support\Import\Actions;

use App\Jobs\ValidateImportBatchJob;
use App\Models\User;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportFile;
use App\Support\Import\ImportPhase;
use App\Support\Import\ImportRegistry;
use App\Support\Import\ImportRow;
use App\Support\Import\ImportRowStatus;
use App\Support\Import\ImportStatus;
use Illuminate\Support\Facades\DB;

/**
 * Langkah 3 impor (BR-054, BR-170): validasi setiap baris berkas dengan pemetaan batch lewat
 * kontrak tipe (`normalize` → `validate` → `rowKey`), simpan hasilnya di `import_rows` (galat per
 * sel, data tanpa rahasia), deteksi kunci ganda dalam berkas. Berkas kecil divalidasi langsung;
 * berkas besar lewat antrean `imports` agar request tidak menunggu lama.
 *
 * `row_key` = sha256 kunci gabungan mentah: kunci tipe lain (mis. lokasi+SKU+batch+bin saldo awal)
 * bisa lebih dari 100 karakter, dan kunci pegawai mengandung hash NIK yang tidak perlu disimpan
 * dalam bentuk yang bisa dibaca. Kunci mentah hanya hidup di memori untuk pesan duplikat.
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
        $type = ImportRegistry::forBatch($batch);
        $columns = $batch->columnMapping();
        $context = $type->context($actor, $batch, ImportPhase::Validate);

        $seen = [];
        $buffer = [];
        $valid = 0;
        $invalid = 0;

        DB::transaction(function () use ($batch, $columns, $type, $context, &$seen, &$buffer, &$valid, &$invalid, $actor) {
            ImportRow::query()->where('import_batch_id', $batch->id)->delete();

            foreach (ImportFile::dataRows($batch) as $number => $cells) {
                $row = $type->validate($type->normalize(ImportFile::mapRow($cells, $columns), $context), $context);
                $errors = $row->errors;
                $key = $type->rowKey($row);

                if ($key !== null) {
                    if (isset($seen[$key])) {
                        $errors['_row'][] = 'Duplikat dengan baris '.$seen[$key].' ('.$type->describeKey($key).'). Hanya baris pertama yang dipakai.';
                    } else {
                        $seen[$key] = $number;
                    }
                }

                $errors === [] ? $valid++ : $invalid++;
                $buffer[] = [
                    'import_batch_id' => $batch->id,
                    'row_number' => $number,
                    'row_key' => $key === null ? null : hash('sha256', $key),
                    'data' => json_encode($row->data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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
