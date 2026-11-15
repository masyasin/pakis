<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Access\DataScope;
use App\Domain\Employee\Enums\ImportRowStatus;
use App\Domain\Employee\Import\RowNormalizer;
use App\Domain\Employee\Import\RowValidator;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Terapkan SATU baris impor ke data pegawai (BR-054, T-028). Dipanggil ProcessImportBatch di dalam
 * transaksi per baris; galat baris dilempar sebagai ValidationException (kunci = field impor).
 *
 * - Baris divalidasi ULANG dari isi berkas: lokasi/jabatan/atasan bisa dinonaktifkan sejak langkah
 *   validasi, dan NIK lengkap hanya ada di berkas (`import_rows.data` termasking, SEC-010).
 * - Kunci baris (`nik:`/`no:`/`nm:`) dicocokkan dengan pegawai yang sudah ada → `updated` (opsi
 *   "Perbarui data yang sudah ada", hanya field terpetakan yang tidak kosong) atau `skipped`.
 *   Itu sebabnya memproses ulang berkas yang sama tidak menggandakan data.
 * - Pegawai lama yang lokasi/jabatannya berbeda TIDAK dimutasi; hanya diberi peringatan.
 */
final class ApplyImportRow
{
    /** Field berkas → kolom pegawai yang boleh diperbarui impor (selain NIK). */
    private const UPDATABLE = ['full_name', 'gender', 'birth_date', 'phone', 'email', 'address', 'join_date'];

    public function __construct(
        private readonly CreateEmployee $create,
        private readonly UpdateEmployee $update,
        private readonly DataScope $scope,
    ) {}

    /**
     * @param  array<string, mixed>  $raw  sel mentah terpetakan (field → nilai)
     * @param  array<string, int>  $resolved  nomor pegawai (huruf kecil) → id, untuk baris di berkas ini yang sudah diproses
     * @return array{status: ImportRowStatus, employee_id: int, warnings: list<string>}
     *
     * @throws ValidationException
     */
    public function handle(User $actor, RowValidator $validator, array $raw, bool $updateExisting, array $resolved): array
    {
        $result = $validator->validate($raw);
        if ($result['errors'] !== []) {
            throw ValidationException::withMessages($result['errors']);
        }
        if ($result['key'] === null) {
            throw ValidationException::withMessages(['_row' => 'Baris tidak punya NIK, no. pegawai, maupun nama.']);
        }

        $data = $result['data'];
        // NIK lengkap dari berkas (sudah lolos normalisasi di validate()); `data.nik` hanya mask.
        $nik = array_key_exists('nik', $raw) ? RowNormalizer::nik($raw['nik']) : null;

        $existing = $this->existing($result['key'], $data);
        if ($existing !== null) {
            return $this->existingRow($actor, $existing, $raw, $data, $nik, $updateExisting);
        }

        return $this->newRow($actor, $raw, $data, $nik, $resolved);
    }

    /** @param  array<string, mixed>  $data */
    private function existing(string $key, array $data): ?Employee
    {
        [$type, $value] = explode(':', $key, 2);

        $matches = match ($type) {
            // Termasuk pegawai terhapus: index unik NIK & nomor ikut mencakupnya.
            'nik' => Employee::withTrashed()->where('nik_hash', $value),
            'no' => Employee::withTrashed()->where('employee_number', $data['employee_number']),
            default => Employee::query()->where('full_name', $data['full_name'])
                ->when(
                    is_string($data['birth_date'] ?? null),
                    fn ($q) => $q->whereDate('birth_date', $data['birth_date']),
                    fn ($q) => $q->whereNull('birth_date'),
                ),
        };

        $found = $matches->lockForUpdate()->limit(2)->get();
        if ($found->count() > 1) {
            throw ValidationException::withMessages(['_row' => 'Ada lebih dari satu pegawai dengan nama dan tanggal lahir ini. Tambahkan NIK atau no. pegawai di berkas.']);
        }

        return $found->first();
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $data
     * @return array{status: ImportRowStatus, employee_id: int, warnings: list<string>}
     */
    private function existingRow(User $actor, Employee $employee, array $raw, array $data, ?string $nik, bool $updateExisting): array
    {
        // Pegawai terhapus atau di luar scope pengunggah tidak boleh disentuh. Pesannya sama dengan
        // NIK ganda di form, tanpa menyebut siapa pemiliknya (SEC-033).
        if ($employee->trashed() || ! $this->scope->allows($actor, $employee->id)) {
            throw ValidationException::withMessages(['_row' => 'Sudah terdaftar atas pegawai lain yang tidak bisa Anda ubah.']);
        }

        $warnings = $this->warnings($employee, $data);

        if (! $updateExisting) {
            return ['status' => ImportRowStatus::Skipped, 'employee_id' => $employee->id, 'warnings' => ['Pegawai sudah terdaftar; data tidak diubah (opsi "Perbarui data yang sudah ada" tidak dicentang).', ...$warnings]];
        }

        // `employees.import` tidak otomatis berarti boleh mengubah pegawai: role bisa disusun bebas
        // (06 §4). Policy yang sama dengan form Ubah, dicek per baris (juga di antrean, karena izin
        // bisa dicabut setelah proses dimulai).
        if (! Gate::forUser($actor)->allows('update', $employee)) {
            throw ValidationException::withMessages(['_row' => 'Anda tidak punya izin mengubah data pegawai; baris ini tidak diperbarui.']);
        }

        $payload = [];
        foreach (self::UPDATABLE as $field) {
            if (array_key_exists($field, $raw) && $data[$field] !== null) {
                $payload[$field] = $data[$field];
            }
        }
        if (array_key_exists('employment_status', $raw) && isset($data['ids']['employment_status_id'])) {
            $payload['employment_status_id'] = $data['ids']['employment_status_id'];
        }
        if ($nik !== null) {
            $payload['nik'] = $nik;
        }

        $before = CreateEmployee::audited($employee);
        $updated = $this->update->handle($actor, $employee, $payload);

        if (CreateEmployee::audited($updated) === $before) {
            return ['status' => ImportRowStatus::Skipped, 'employee_id' => $employee->id, 'warnings' => ['Pegawai sudah terdaftar dan datanya sama; tidak ada perubahan.', ...$warnings]];
        }

        return ['status' => ImportRowStatus::Updated, 'employee_id' => $employee->id, 'warnings' => $warnings];
    }

    /**
     * BR-054: beda lokasi/jabatan hanya peringatan; mutasi tetap lewat menu Penugasan (BR-052).
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function warnings(Employee $employee, array $data): array
    {
        $warnings = [];
        $number = $data['employee_number'] ?? null;
        if (is_string($number) && mb_strtolower($number) !== mb_strtolower($employee->employee_number)) {
            $warnings[] = "No. pegawai di berkas ({$number}) berbeda dengan data ({$employee->employee_number}); nomor tidak diubah.";
        }

        if (! $employee->isActive()) {
            return [...$warnings, 'Pegawai ini nonaktif; penugasan tidak diubah.'];
        }

        $assignment = $employee->primaryAssignment()->with(['location:id,name', 'position:id,name'])->first();
        if ($assignment === null) {
            return $warnings;
        }

        $ids = $data['ids'] ?? [];
        if (isset($ids['location_id']) && $ids['location_id'] !== $assignment->location_id) {
            $warnings[] = "Lokasi di berkas ({$data['location']}) berbeda dengan penugasan utama ({$assignment->location?->name}); tidak dimutasi otomatis. Gunakan Mutasi bila perlu.";
        }
        if (isset($ids['position_id']) && $ids['position_id'] !== $assignment->position_id) {
            $warnings[] = "Jabatan di berkas ({$data['position']}) berbeda dengan penugasan utama ({$assignment->position?->name}); tidak dimutasi otomatis. Gunakan Mutasi bila perlu.";
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $data
     * @param  array<string, int>  $resolved
     * @return array{status: ImportRowStatus, employee_id: int, warnings: list<string>}
     */
    private function newRow(User $actor, array $raw, array $data, ?string $nik, array $resolved): array
    {
        if (! Gate::forUser($actor)->allows('create', Employee::class)) {
            throw ValidationException::withMessages(['_row' => 'Anda tidak punya izin menambah pegawai; baris ini tidak dibuat.']);
        }

        $ids = $data['ids'];

        $supervisorId = $ids['supervisor_employee_id'] ?? null;
        $supervisor = $data['supervisor_number'] ?? null;
        if ($supervisorId === null && is_string($supervisor)) {
            $supervisorId = $resolved[mb_strtolower($supervisor)] ?? null;
            if ($supervisorId === null) {
                throw ValidationException::withMessages(['supervisor_number' => "Atasan \"{$supervisor}\" di berkas ini tidak berhasil diproses (baris atasannya galat)."]);
            }
        }

        $number = $data['employee_number'] ?? null;
        if (is_string($number) && Employee::withTrashed()->where('employee_number', $number)->exists()) {
            throw ValidationException::withMessages(['employee_number' => 'No. pegawai sudah dipakai pegawai lain.']);
        }

        $payload = [
            'full_name' => $data['full_name'],
            'gender' => $data['gender'],
            'join_date' => $data['join_date'],
            'employment_status_id' => $ids['employment_status_id'],
            'assignment' => [
                'position_id' => $ids['position_id'],
                'location_id' => $ids['location_id'],
                'supervisor_employee_id' => $supervisorId,
                'start_date' => $data['join_date'],
            ],
        ];
        foreach (['birth_date', 'phone', 'email', 'address'] as $field) {
            if (array_key_exists($field, $raw) && $data[$field] !== null) {
                $payload[$field] = $data[$field];
            }
        }
        if ($nik !== null) {
            $payload['nik'] = $nik;
        }

        try {
            // Savepoint: bila nomor ternyata dipakai bersamaan oleh proses lain, hanya baris ini gagal.
            $employee = DB::transaction(fn () => $this->create->handle($actor, $payload, null, is_string($number) ? $number : null)['employee']);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['employee_number' => 'No. pegawai sudah dipakai pegawai lain.']);
        }

        return ['status' => ImportRowStatus::Created, 'employee_id' => $employee->id, 'warnings' => []];
    }
}
