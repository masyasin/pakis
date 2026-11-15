<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Access\Support\AvatarStore;
use App\Domain\Audit\AuditLogger;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\NikCipher;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ubah data pegawai (FR-025, BR-050). Nomor pegawai, status, dan penugasan TIDAK diubah di sini
 * (SEC-034; status lewat offboarding T-026, penugasan lewat mutasi T-025).
 *
 * NIK: field kosong = tetap (form tidak pernah memuat NIK lengkap), `remove_nik` = hapus,
 * isi baru = enkripsi & hash ulang.
 */
final class UpdateEmployee
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NikCipher $nik,
        private readonly AvatarStore $photos,
    ) {}

    /**
     * @param  array<string, mixed>  $data  dari EmployeeRequest::employeeData() (+ `nik`, `remove_nik`, `remove_photo`)
     */
    public function handle(User $actor, Employee $employee, array $data, ?UploadedFile $photo = null): Employee
    {
        $newPhoto = $photo !== null ? $this->photos->store($photo, 'photo', CreateEmployee::PHOTO_DIRECTORY) : null;
        $oldPhoto = null;

        try {
            $updated = DB::transaction(function () use ($actor, $employee, $data, $newPhoto, &$oldPhoto) {
                $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);
                $before = CreateEmployee::audited($locked);

                $locked->fill(array_intersect_key($data, array_flip(Employee::AUDITED)));

                $nik = $data['nik'] ?? null;
                if ($nik !== null) {
                    $hash = $this->nik->hash($nik);
                    if ($hash !== $locked->nik_hash) {
                        if (CreateEmployee::nikTaken($hash, $locked->id)) {
                            throw CreateEmployee::nikDuplicate();
                        }
                        $locked->forceFill(['nik_enc' => $this->nik->encrypt($nik), 'nik_hash' => $hash]);
                    }
                } elseif (($data['remove_nik'] ?? false) === true) {
                    $locked->forceFill(['nik_enc' => null, 'nik_hash' => null]);
                }

                if ($newPhoto !== null || ($data['remove_photo'] ?? false) === true) {
                    $oldPhoto = $locked->photo_path;
                    $locked->photo_path = $newPhoto;
                }

                if (! $locked->isDirty()) {
                    return $locked;
                }

                $locked->updated_by = $actor->id;
                $locked->save();

                $this->audit->record('employee.updated', $locked, $before, CreateEmployee::audited($locked));

                return $locked;
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->photos->delete($newPhoto);
            throw str_contains($e->getMessage(), 'employees_nik_hash_unique') ? CreateEmployee::nikDuplicate() : $e;
        } catch (Throwable $e) {
            $this->photos->delete($newPhoto);
            throw $e;
        }

        // Foto lama dihapus SETELAH commit: bila transaksi gagal, foto lama masih dipakai.
        if ($oldPhoto !== null && $oldPhoto !== $updated->photo_path) {
            $this->photos->delete($oldPhoto);
        }

        return $updated;
    }
}
