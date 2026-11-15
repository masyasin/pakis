<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Access\Support\AvatarStore;
use App\Domain\Audit\AuditLogger;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\NikCipher;
use App\Domain\Numbering\NumberGenerator;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tambah pegawai (FR-025, BR-050) — form gabungan: data diri + penugasan pertama (+ akun opsional)
 * dalam SATU transaksi, jadi tidak pernah ada pegawai tanpa penugasan.
 *
 * - `employee_number` selalu dari NumberGenerator (request tidak bisa mengisinya, SEC-034).
 * - NIK disimpan terenkripsi + HMAC (SEC-010); audit hanya mencatat bahwa NIK diisi (`***`).
 */
final class CreateEmployee
{
    public const PHOTO_DIRECTORY = 'employee-photos';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
        private readonly NikCipher $nik,
        private readonly CreateAssignment $assignments,
        private readonly CreateAccountForEmployee $accounts,
        private readonly AvatarStore $photos,
    ) {}

    /**
     * @param  array<string, mixed>  $data  dari EmployeeRequest::employeeData() + `assignment` + `account`
     * @return array{employee: Employee, temporary_password: string|null, account: User|null}
     */
    public function handle(User $actor, array $data, ?UploadedFile $photo = null): array
    {
        // Disimpan sebelum transaksi (berkas tidak ikut rollback), dihapus lagi bila simpan gagal.
        $photoPath = $photo !== null ? $this->photos->store($photo, 'photo', self::PHOTO_DIRECTORY) : null;

        try {
            return DB::transaction(function () use ($actor, $data, $photoPath) {
                $nik = $data['nik'] ?? null;
                $hash = $nik !== null ? $this->nik->hash($nik) : null;

                if ($hash !== null && self::nikTaken($hash)) {
                    throw self::nikDuplicate();
                }

                $employee = new Employee;
                $employee->fill([...array_intersect_key($data, array_flip(Employee::AUDITED)), 'photo_path' => $photoPath]);
                $employee->forceFill([
                    'employee_number' => $this->numbers->next('employee', isTaken: fn (string $n) => Employee::withTrashed()->where('employee_number', $n)->exists()),
                    'status' => EmployeeStatus::Active,
                    'nik_enc' => $nik !== null ? $this->nik->encrypt($nik) : null,
                    'nik_hash' => $hash,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ])->save();

                $this->audit->record('employee.created', $employee, null, self::audited($employee));

                $this->assignments->handle($employee, $data['assignment'], $actor->id);

                $account = isset($data['account']) ? $this->accounts->handle($actor, $employee, $data['account']) : null;

                return [
                    'employee' => $employee,
                    'account' => $account['user'] ?? null,
                    'temporary_password' => $account['temporary_password'] ?? null,
                ];
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->photos->delete($photoPath);
            // NIK yang sama disimpan bersamaan oleh dua permintaan: cek di atas lolos keduanya.
            throw str_contains($e->getMessage(), 'employees_nik_hash_unique') ? self::nikDuplicate() : $e;
        } catch (Throwable $e) {
            $this->photos->delete($photoPath);
            throw $e;
        }
    }

    public static function nikTaken(string $hash, ?int $exceptId = null): bool
    {
        // Termasuk pegawai terhapus: index unik `employees_nik_hash_unique` ikut mencakupnya.
        return Employee::withTrashed()->where('nik_hash', $hash)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
    }

    /** Tanpa menyebut pegawai pemilik NIK itu (data orang lain tidak bocor ke yang tidak berhak). */
    public static function nikDuplicate(): ValidationException
    {
        return ValidationException::withMessages(['nik' => 'NIK sudah terdaftar atas pegawai lain.']);
    }

    /**
     * Nilai audit: tanggal sebagai string agar diff tidak menganggap objek Carbon baru sebagai
     * perubahan; NIK sebagai hash di kunci `nik` → Redactor menulis `***`.
     *
     * @return array<string, mixed>
     */
    public static function audited(Employee $employee): array
    {
        return [
            ...$employee->only(Employee::AUDITED),
            'employee_number' => $employee->employee_number,
            'gender' => $employee->gender->value,
            'birth_date' => $employee->birth_date?->toDateString(),
            'join_date' => $employee->join_date->toDateString(),
            'nik' => $employee->nik_hash,
            'photo' => $employee->photo_path, // nama berkas acak (ULID), bukan data pribadi
        ];
    }
}
