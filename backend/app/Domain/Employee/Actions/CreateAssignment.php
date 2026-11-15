<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Employee\Events\AssignmentChanged;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\SupervisorCycleGuard;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use App\Support\Time\DisplayTime;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penugasan baru (BR-051): penugasan pertama pegawai baru (T-024), penugasan utama baru saat
 * mutasi (dipanggil MutateEmployee setelah menutup yang lama), atau penugasan TAMBAHAN (rangkap).
 *
 * Status aktif jabatan/lokasi/atasan dicek ulang di sini (bukan hanya di Form Request): data bisa
 * dinonaktifkan orang lain di antara validasi dan simpan.
 */
final class CreateAssignment
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{position_id: int, location_id: int, supervisor_employee_id?: int|null, attendance_mode?: string|null, start_date?: string|null, note?: string|null, is_primary?: bool}  $data
     * @param  string  $field  awalan nama field untuk pesan galat (form gabungan memakai `assignment.`)
     * @param  'created'|'mutated'  $reason  alasan pada event AssignmentChanged (mutasi = satu event saja)
     */
    public function handle(Employee $employee, array $data, ?int $userId, string $field = 'assignment.', string $reason = 'created'): Assignment
    {
        return DB::transaction(function () use ($employee, $data, $userId, $field, $reason) {
            $fail = fn (string $key, string $message) => throw ValidationException::withMessages([$field.$key => $message]);
            $primary = $data['is_primary'] ?? true;
            $start = $data['start_date'] ?? $employee->join_date->toDateString();

            // Penugasan tambahan memakai aturan tanggal & status yang sama dengan mutasi (BR-052):
            // tanpa ini jalur "tambahan" menjadi celah memberi penugasan ke pegawai nonaktif atau
            // bertanggal mundur tanpa batas. Penugasan utama pertama (pegawai baru) & mutasi sudah dicek
            // pemanggilnya.
            if (! $primary) {
                $this->guardExtra($employee, $start, $fail);
            }

            if (! Position::query()->whereKey($data['position_id'])->where('is_active', true)->exists()) {
                $fail('position_id', 'Jabatan tidak ditemukan atau sudah nonaktif.');
            }

            if (! Location::query()->whereKey($data['location_id'])->where('is_active', true)->exists()) {
                $fail('location_id', 'Lokasi tidak ditemukan atau sudah nonaktif.');
            }

            SupervisorCycleGuard::lock();

            $supervisorId = $data['supervisor_employee_id'] ?? null;
            if ($supervisorId !== null) {
                if (! Employee::query()->whereKey($supervisorId)->where('status', 'active')->exists()) {
                    $fail('supervisor_employee_id', 'Atasan harus pegawai yang masih aktif.');
                }
                $violation = SupervisorCycleGuard::violation($employee->id, $supervisorId);
                if ($violation !== null) {
                    $fail('supervisor_employee_id', $violation);
                }
            }

            $open = Assignment::query()->where('employee_id', $employee->id)->whereNull('end_date');

            // Satu penugasan utama aktif per pegawai (BR-051; juga dijaga index unik DB).
            if ($primary && (clone $open)->where('is_primary', true)->exists()) {
                $fail('position_id', 'Pegawai ini sudah punya penugasan utama aktif. Gunakan Mutasi untuk memindahkannya.');
            }

            // Rangkap di lokasi yang sama tidak bermakna (scope & absen sudah tercakup).
            if (! $primary && self::stillRunning($employee->id)->where('location_id', $data['location_id'])->exists()) {
                $fail('location_id', 'Pegawai ini sudah punya penugasan aktif di lokasi tersebut.');
            }

            $assignment = new Assignment;
            $assignment->forceFill([
                'employee_id' => $employee->id,
                'position_id' => $data['position_id'],
                'location_id' => $data['location_id'],
                'supervisor_employee_id' => $supervisorId,
                'attendance_mode' => $data['attendance_mode'] ?? null,
                'is_primary' => $primary,
                'start_date' => $start,
                'note' => $data['note'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ])->save();

            $this->audit->record('assignment.created', $assignment, null, self::audited($assignment));

            AssignmentChanged::dispatch($employee->id, $start, $reason);

            return $assignment;
        });
    }

    /**
     * @param  Closure(string, string): never  $fail
     */
    private function guardExtra(Employee $employee, string $start, Closure $fail): void
    {
        if (! Employee::query()->whereKey($employee->id)->where('status', 'active')->exists()) {
            $fail('start_date', 'Pegawai nonaktif tidak bisa diberi penugasan. Aktifkan kembali dulu.');
        }

        if ($start < $employee->join_date->toDateString()) {
            $fail('start_date', 'Tanggal mulai tidak boleh sebelum tanggal masuk pegawai ('.$employee->join_date->translatedFormat('j F Y').').');
        }

        $earliest = DisplayTime::toLocal(now())->subDays(MutateEmployee::MAX_BACKDATE_DAYS)->toDateString();
        if ($start < $earliest) {
            $fail('start_date', 'Tanggal mulai paling jauh '.MutateEmployee::MAX_BACKDATE_DAYS.' hari ke belakang.');
        }
    }

    /**
     * Penugasan pegawai yang masih berlaku atau terjadwal (`end_date` kosong / ≥ hari ini).
     *
     * @return Builder<Assignment>
     */
    public static function stillRunning(int $employeeId): Builder
    {
        $today = DisplayTime::toLocal(now())->toDateString();

        return Assignment::query()->where('employee_id', $employeeId)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today));
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
