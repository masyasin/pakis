<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penugasan baru (BR-051). Versi minimal T-024 untuk penugasan pertama pegawai baru; mutasi
 * (tutup lama + buat baru, BR-052) dan cek siklus atasan lengkap dilengkapi di T-025.
 *
 * Status aktif jabatan/lokasi/atasan dicek ulang di sini (bukan hanya di Form Request): data bisa
 * dinonaktifkan orang lain di antara validasi dan simpan.
 */
final class CreateAssignment
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{position_id: int, location_id: int, supervisor_employee_id?: int|null, attendance_mode?: string|null, start_date?: string|null, note?: string|null}  $data
     * @param  string  $field  awalan nama field untuk pesan galat (form gabungan memakai `assignment.`)
     */
    public function handle(Employee $employee, array $data, ?int $userId, string $field = 'assignment.'): Assignment
    {
        return DB::transaction(function () use ($employee, $data, $userId, $field) {
            $fail = fn (string $key, string $message) => throw ValidationException::withMessages([$field.$key => $message]);

            if (! Position::query()->whereKey($data['position_id'])->where('is_active', true)->exists()) {
                $fail('position_id', 'Jabatan tidak ditemukan atau sudah nonaktif.');
            }

            if (! Location::query()->whereKey($data['location_id'])->where('is_active', true)->exists()) {
                $fail('location_id', 'Lokasi tidak ditemukan atau sudah nonaktif.');
            }

            $supervisorId = $data['supervisor_employee_id'] ?? null;
            if ($supervisorId !== null) {
                if ($supervisorId === $employee->id) {
                    $fail('supervisor_employee_id', 'Pegawai tidak bisa menjadi atasan dirinya sendiri.');
                }
                if (! Employee::query()->whereKey($supervisorId)->where('status', 'active')->exists()) {
                    $fail('supervisor_employee_id', 'Atasan harus pegawai yang masih aktif.');
                }
            }

            $assignment = new Assignment;
            $assignment->forceFill([
                'employee_id' => $employee->id,
                'position_id' => $data['position_id'],
                'location_id' => $data['location_id'],
                'supervisor_employee_id' => $supervisorId,
                'attendance_mode' => $data['attendance_mode'] ?? null,
                'is_primary' => true,
                'start_date' => $data['start_date'] ?? $employee->join_date->toDateString(),
                'note' => $data['note'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ])->save();

            $this->audit->record('assignment.created', $assignment, null, self::audited($assignment));

            return $assignment;
        });
    }

    /** @return array<string, mixed> */
    public static function audited(Assignment $assignment): array
    {
        return [
            ...$assignment->only(Assignment::AUDITED),
            'attendance_mode' => $assignment->attendance_mode?->value,
            'start_date' => $assignment->start_date->toDateString(),
            'end_date' => $assignment->end_date?->toDateString(),
        ];
    }
}
