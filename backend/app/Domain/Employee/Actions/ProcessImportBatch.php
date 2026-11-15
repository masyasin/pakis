<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Employee\Enums\ImportRowStatus;
use App\Domain\Employee\Enums\ImportStatus;
use App\Domain\Employee\Events\ImportProcessed;
use App\Domain\Employee\Import\EmployeeImportFields;
use App\Domain\Employee\Import\ImportFile;
use App\Domain\Employee\Import\RowNormalizer;
use App\Domain\Employee\Import\RowValidator;
use App\Domain\Employee\Models\ImportBatch;
use App\Domain\Employee\Models\ImportRow;
use App\Jobs\ProcessImportBatchJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Langkah 4 impor (BR-054, T-028): tulis baris `valid` ke data pegawai.
 *
 * - SATU transaksi per baris (status `import_rows` ikut di transaksi itu): bila proses berhenti di
 *   tengah, baris yang sudah sukses tetap konsisten dan batch ditandai `failed` (06 §7.4).
 * - Galat satu baris (ValidationException) tidak menghentikan batch; baris itu menjadi `invalid`.
 * - Atasan yang ada di berkas yang sama diproses lebih dulu (urutan topologis).
 * - Berkas asli dihapus setelah proses berakhir: isinya (termasuk NIK lengkap) tidak diperlukan
 *   lagi; berkas galat dibuat dari `import_rows` (keputusan Mas 15 Nov 2026).
 */
final class ProcessImportBatch
{
    public function __construct(
        private readonly ApplyImportRow $apply,
        private readonly AuditLogger $audit,
    ) {}

    /** `validated` → `processing`, lalu proses langsung atau lewat antrean `imports`. */
    public function start(User $actor, ImportBatch $batch, bool $updateExisting): ImportBatch
    {
        $batch = DB::transaction(function () use ($actor, $batch, $updateExisting) {
            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $fail = fn (string $message) => throw ValidationException::withMessages(['status' => $message]);

            if (! $locked->status->canProcess()) {
                $fail('Impor yang berstatus '.mb_strtolower($locked->status->label()).' tidak bisa diproses.');
            }
            if ($locked->valid_rows === 0) {
                $fail('Tidak ada baris valid untuk diproses.');
            }
            if ($updateExisting && ! $actor->can('employees.update')) {
                $fail('Anda tidak punya izin mengubah data pegawai. Hapus centang "Perbarui data yang sudah ada".');
            }
            if (! Storage::disk(ImportFile::DISK)->exists($locked->stored_path)) {
                $fail('Berkas asli sudah tidak tersedia. Unggah ulang berkasnya.');
            }

            $locked->forceFill([
                'status' => ImportStatus::Processing,
                'mapping' => [...($locked->mapping ?? []), 'options' => ['update_existing' => $updateExisting]],
                'updated_by' => $actor->id,
            ])->save();

            return $locked;
        });

        if ($batch->valid_rows > ValidateImportBatch::SYNC_LIMIT) {
            ProcessImportBatchJob::dispatch($batch->id, $actor->id)->onQueue('imports');
        } else {
            $this->handle($batch, $actor);
        }

        return $batch->refresh();
    }

    public function handle(ImportBatch $batch, User $actor): void
    {
        try {
            AuditLogger::actingAs('import#'.$batch->id, fn () => $this->run($batch, $actor));
        } catch (Throwable $e) {
            // Galat tak terduga (DB putus, berkas rusak, bug): hentikan, tandai gagal, laporkan.
            report($e);
            $this->finish($batch, $actor, ImportStatus::Failed);

            return;
        }

        $this->finish($batch, $actor, ImportStatus::Completed);
    }

    /** Dipanggil job yang mati (timeout/worker berhenti) agar batch tidak tertahan `processing`. */
    public function markFailed(ImportBatch $batch, ?User $actor): void
    {
        if ($batch->status === ImportStatus::Processing) {
            $this->finish($batch, $actor, ImportStatus::Failed);
        }
    }

    public static function updateExisting(ImportBatch $batch): bool
    {
        return ($batch->mapping['options']['update_existing'] ?? false) === true;
    }

    private function run(ImportBatch $batch, User $actor): void
    {
        $columns = $batch->columnMapping();
        $rows = ImportRow::query()->where('import_batch_id', $batch->id)
            ->where('status', ImportRowStatus::Valid)->orderBy('row_number')->get()->keyBy('row_number');

        // Satu lintasan berkas: sel mentah baris valid + semua nomor pegawai di berkas (atasan dari
        // berkas yang sama sah walau barisnya galat; pesannya diberikan ApplyImportRow).
        $raws = [];
        $allNumbers = [];
        foreach (ImportFile::dataRows($batch) as $number => $cells) {
            $raw = ImportFile::mapRow($cells, $columns);
            $code = RowNormalizer::code($raw['employee_number'] ?? null);
            if ($code !== null) {
                $allNumbers[] = $code;
            }
            if ($rows->has($number)) {
                $raws[$number] = $raw;
            }
        }

        $validator = new RowValidator($actor, $allNumbers);
        [$order, $cyclic] = self::order($raws);
        $updateExisting = self::updateExisting($batch);
        $resolved = [];

        foreach ($order as $number) {
            /** @var ImportRow $row */
            $row = $rows[$number];
            if (isset($cyclic[$number])) {
                self::markInvalid($row, ['supervisor_number' => ['Rantai atasan di berkas ini melingkar (mis. A atasan B dan B atasan A).']]);

                continue;
            }
            if (! isset($raws[$number])) {
                self::markInvalid($row, ['_row' => ['Baris ini tidak ditemukan lagi di berkas.']]);

                continue;
            }

            try {
                $result = DB::transaction(function () use ($actor, $validator, $raws, $number, $updateExisting, $resolved, $row) {
                    $result = $this->apply->handle($actor, $validator, $raws[$number], $updateExisting, $resolved);
                    $row->forceFill([
                        'status' => $result['status'],
                        'data' => [...$row->data, 'employee_id' => $result['employee_id'], 'warnings' => $result['warnings']],
                        'errors' => null,
                    ])->save();

                    return $result;
                });
            } catch (ValidationException $e) {
                self::markInvalid($row, $e->errors());

                continue;
            }

            $code = RowNormalizer::code($raws[$number]['employee_number'] ?? null);
            if ($code !== null) {
                $resolved[mb_strtolower($code)] = $result['employee_id'];
            }
        }
    }

    private function finish(ImportBatch $batch, ?User $actor, ImportStatus $status): void
    {
        $counts = ImportRow::query()->where('import_batch_id', $batch->id)
            ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')
            ->map(fn (mixed $n) => (int) $n);
        $count = fn (ImportRowStatus $s) => $counts->get($s->value, 0);

        DB::transaction(function () use ($batch, $actor, $status, $count) {
            $batch->forceFill([
                'status' => $status,
                'valid_rows' => $count(ImportRowStatus::Created) + $count(ImportRowStatus::Updated) + $count(ImportRowStatus::Skipped) + $count(ImportRowStatus::Valid),
                'error_rows' => $count(ImportRowStatus::Invalid),
                'created_rows' => $count(ImportRowStatus::Created),
                'updated_rows' => $count(ImportRowStatus::Updated),
                'skipped_rows' => $count(ImportRowStatus::Skipped),
                'completed_at' => now(),
                'updated_by' => $actor->id ?? $batch->updated_by,
            ])->save();

            $this->audit->record('import.processed', $batch, null, [
                'status' => $status->value,
                'created' => $batch->created_rows,
                'updated' => $batch->updated_rows,
                'skipped' => $batch->skipped_rows,
                'errors' => $batch->error_rows,
                'update_existing' => self::updateExisting($batch),
            ]);
        });

        Storage::disk(ImportFile::DISK)->delete($batch->stored_path);

        ImportProcessed::dispatch($batch);
    }

    /**
     * Urutan proses: atasan (nomor pegawai di berkas) sebelum bawahannya. Tiap baris punya paling
     * banyak satu atasan, jadi graf berupa rantai/pohon; sisa setelah Kahn = baris pada lingkaran
     * atau yang menggantung pada lingkaran.
     *
     * @param  array<int, array<string, mixed>>  $raws  nomor baris → sel mentah
     * @return array{0: list<int>, 1: array<int, true>} urutan nomor baris, baris pada lingkaran
     */
    private static function order(array $raws): array
    {
        $byNumber = [];
        foreach ($raws as $row => $raw) {
            $code = RowNormalizer::code($raw['employee_number'] ?? null);
            if ($code !== null) {
                $byNumber[mb_strtolower($code)] ??= $row;
            }
        }

        $parent = [];
        $children = [];
        foreach ($raws as $row => $raw) {
            $supervisor = RowNormalizer::code($raw['supervisor_number'] ?? null);
            $boss = $supervisor !== null ? ($byNumber[mb_strtolower($supervisor)] ?? null) : null;
            if ($boss !== null && $boss !== $row) {
                $parent[$row] = $boss;
                $children[$boss][] = $row;
            }
        }

        $order = [];
        $queue = array_values(array_filter(array_keys($raws), fn (int $row) => ! isset($parent[$row])));
        while ($queue !== []) {
            $row = array_shift($queue);
            $order[] = $row;
            foreach ($children[$row] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        $cyclic = [];
        $left = array_values(array_diff(array_keys($raws), $order));
        foreach ($left as $row) {
            // Ikuti rantai atasan; bila kembali ke baris ini, baris ini bagian lingkaran.
            $seen = [];
            for ($at = $parent[$row] ?? null; $at !== null && ! isset($seen[$at]); $at = $parent[$at] ?? null) {
                if ($at === $row) {
                    $cyclic[$row] = true;
                    break;
                }
                $seen[$at] = true;
            }
        }

        // Baris lingkaran dulu (ditandai galat), lalu yang menggantung padanya: atasannya sudah galat.
        usort($left, fn (int $a, int $b) => [! isset($cyclic[$a]), $a] <=> [! isset($cyclic[$b]), $b]);

        return [[...$order, ...$left], $cyclic];
    }

    /**
     * Kunci galat dari Action pegawai (`assignment.position_id`, `nik`, …) dikembalikan ke nama field
     * impor agar sel yang tepat ditandai merah.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    private static function markInvalid(ImportRow $row, array $errors): void
    {
        $map = [
            'position_id' => 'position', 'location_id' => 'location', 'supervisor_employee_id' => 'supervisor_number',
            'employment_status_id' => 'employment_status', 'start_date' => 'join_date',
        ];
        $fields = [...array_keys(EmployeeImportFields::FIELDS), '_row'];

        $mapped = [];
        foreach ($errors as $key => $messages) {
            $field = str_starts_with($key, 'assignment.') ? substr($key, strlen('assignment.')) : $key;
            $field = $map[$field] ?? $field;
            $field = in_array($field, $fields, true) ? $field : '_row';
            foreach ($messages as $message) {
                $mapped[$field][] = $message;
            }
        }

        $row->forceFill(['status' => ImportRowStatus::Invalid, 'errors' => $mapped])->save();
    }
}
