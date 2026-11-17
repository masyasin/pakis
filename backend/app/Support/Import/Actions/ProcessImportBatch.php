<?php

namespace App\Support\Import\Actions;

use App\Domain\Accounting\Period\PeriodGuard;
use App\Domain\Audit\AuditLogger;
use App\Jobs\ProcessImportBatchJob;
use App\Models\User;
use App\Support\Import\Events\ImportProcessed;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportFile;
use App\Support\Import\ImportPhase;
use App\Support\Import\ImportRegistry;
use App\Support\Import\ImportRow;
use App\Support\Import\ImportRowStatus;
use App\Support\Import\ImportStatus;
use App\Support\Import\ImportType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Langkah 4 impor (BR-054, BR-170, T-028): tulis baris `valid` lewat `ImportType::apply()`.
 *
 * - SATU transaksi per baris (status `import_rows` ikut di transaksi itu): bila proses berhenti di
 *   tengah, baris yang sudah sukses tetap konsisten dan batch ditandai `failed` (06 §7.4).
 * - Baris divalidasi ulang di transaksinya (data bisa berubah sejak validasi); galat satu baris
 *   (ValidationException) tidak menghentikan batch, baris itu menjadi `invalid`.
 * - Urutan dari `processOrder()` (mis. atasan sebelum bawahan di impor pegawai).
 * - Berkas asli dihapus setelah proses berakhir: isinya (mis. NIK lengkap) tidak diperlukan lagi;
 *   berkas galat dibuat dari `import_rows` (keputusan Mas 15 Nov 2026).
 */
final class ProcessImportBatch
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * `validated` → `processing`, lalu proses langsung atau lewat antrean `imports`. `$passwordConfirmed`
     * = pengimpor baru saja mengonfirmasi password; wajib bila tipe memintanya (T-085, PA-70) dan
     * disimpan di opsi batch agar `apply()` (juga di job antrean) tahu syaratnya terpenuhi.
     * `$businessDate` (`Y-m-d`) = tanggal bisnis dari pengimpor bila tipe memintanya (T-086: tanggal
     * cutover saldo awal), wajib di periode terbuka (`PeriodGuard`).
     */
    public function start(User $actor, ImportBatch $batch, bool $updateExisting, bool $passwordConfirmed = false, ?string $businessDate = null): ImportBatch
    {
        $batch = DB::transaction(function () use ($actor, $batch, $updateExisting, $passwordConfirmed, $businessDate) {
            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $type = ImportRegistry::forBatch($locked);
            $type->ensureAvailable();
            $fail = fn (string $message) => throw ValidationException::withMessages(['status' => $message]);

            if (! $locked->status->canProcess()) {
                $fail('Impor yang berstatus '.mb_strtolower($locked->status->label()).' tidak bisa diproses.');
            }
            if ($locked->valid_rows === 0) {
                $fail('Tidak ada baris valid untuk diproses.');
            }
            if ($updateExisting && ! self::canUpdateExisting($actor, $type)) {
                $fail('Anda tidak punya izin mengubah data '.mb_strtolower($type->label()).'. Hapus centang "Perbarui data yang sudah ada".');
            }
            if (! $passwordConfirmed && $type->requiresPassword($locked, $actor)) {
                throw ValidationException::withMessages(['current_password' => 'Konfirmasi password Anda untuk memproses impor ini.']);
            }
            $options = ['update_existing' => $updateExisting, 'password_confirmed' => $passwordConfirmed];
            if ($type->requiresBusinessDate()) {
                if ($businessDate === null || ! self::isDate($businessDate)) {
                    throw ValidationException::withMessages(['business_date' => 'Isi tanggal dengan format yang benar.']);
                }
                PeriodGuard::assertOpen($businessDate, 'business_date');
                $options['business_date'] = $businessDate;
            }
            if (! Storage::disk(ImportFile::DISK)->exists($locked->stored_path)) {
                $fail('Berkas asli sudah tidak tersedia. Unggah ulang berkasnya.');
            }

            $locked->forceFill([
                'status' => ImportStatus::Processing,
                'mapping' => [...($locked->mapping ?? []), 'options' => $options],
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

    /** Pengimpor mengonfirmasi password saat memulai proses (T-085, PA-70). */
    public static function passwordConfirmed(ImportBatch $batch): bool
    {
        return ($batch->mapping['options']['password_confirmed'] ?? false) === true;
    }

    /** Tanggal bisnis batch (`Y-m-d`) yang diisi saat proses dimulai, bila tipe memintanya (T-086). */
    public static function businessDate(ImportBatch $batch): ?string
    {
        $date = $batch->mapping['options']['business_date'] ?? null;

        return is_string($date) ? $date : null;
    }

    private static function isDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    public static function canUpdateExisting(User $actor, ImportType $type): bool
    {
        $permission = $type->updatePermission();

        return $permission !== null && $actor->can($permission);
    }

    private function run(ImportBatch $batch, User $actor): void
    {
        $type = ImportRegistry::forBatch($batch);
        $columns = $batch->columnMapping();
        $rows = ImportRow::query()->where('import_batch_id', $batch->id)
            ->where('status', ImportRowStatus::Valid)->orderBy('row_number')->get()->keyBy('row_number');

        $raws = [];
        foreach (ImportFile::dataRows($batch) as $number => $cells) {
            if ($rows->has($number)) {
                $raws[$number] = ImportFile::mapRow($cells, $columns);
            }
        }

        $context = $type->context($actor, $batch, ImportPhase::Process);
        $order = $type->processOrder($raws);
        $updateExisting = self::updateExisting($batch);

        foreach ($order->rows as $number) {
            /** @var ImportRow $row */
            $row = $rows[$number];
            if (isset($order->rejected[$number])) {
                self::markInvalid($type, $row, $order->rejected[$number]);

                continue;
            }
            if (! isset($raws[$number])) {
                self::markInvalid($type, $row, ['_row' => ['Baris ini tidak ditemukan lagi di berkas.']]);

                continue;
            }

            try {
                $applied = DB::transaction(function () use ($actor, $type, $context, $raws, $number, $updateExisting, $row) {
                    $validated = $type->validate($type->normalize($raws[$number], $context), $context);
                    if (! $validated->isValid()) {
                        throw ValidationException::withMessages($validated->errors);
                    }

                    $applied = $type->apply($actor, $validated, $raws[$number], $updateExisting, $context);
                    $row->forceFill([
                        'status' => $applied->status,
                        'data' => [...$row->data, ...$applied->data],
                        'errors' => null,
                    ])->save();

                    return $applied;
                });
            } catch (ValidationException $e) {
                self::markInvalid($type, $row, $e->errors());

                continue;
            }

            $type->committed($context, $raws[$number], $applied);
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

            $activated = ImportRow::query()->where('import_batch_id', $batch->id)->where('data->activated', true)->count();
            $this->audit->record('import.processed', $batch, null, [
                'status' => $status->value,
                'created' => $batch->created_rows,
                'updated' => $batch->updated_rows,
                'skipped' => $batch->skipped_rows,
                'errors' => $batch->error_rows,
                'update_existing' => self::updateExisting($batch),
                // Hanya tipe yang mengaktifkan data langsung (kios cutover, PA-70 a) yang menambah kunci ini.
                ...($activated > 0 ? ['activated' => $activated, 'password_confirmed' => self::passwordConfirmed($batch)] : []),
            ]);
        });

        Storage::disk(ImportFile::DISK)->delete($batch->stored_path);

        ImportProcessed::dispatch($batch);
    }

    /**
     * Kunci galat dari Action domain dikembalikan ke nama field impor (`errorField()`) agar sel yang
     * tepat ditandai merah; kunci yang bukan field tipe itu menjadi galat baris (`_row`).
     *
     * @param  array<string, array<int, string>>  $errors
     */
    private static function markInvalid(ImportType $type, ImportRow $row, array $errors): void
    {
        $fields = $type->fields();

        $mapped = [];
        foreach ($errors as $key => $messages) {
            $field = $type->errorField($key);
            $field = $field === '_row' || $fields->has($field) ? $field : '_row';
            foreach ($messages as $message) {
                $mapped[$field][] = $message;
            }
        }

        $row->forceFill(['status' => ImportRowStatus::Invalid, 'errors' => $mapped])->save();
    }
}
