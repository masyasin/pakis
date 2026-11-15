<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Employee\Events\AssignmentChanged;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\SupervisorCycleGuard;
use App\Support\Time\DisplayTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ubah penugasan yang masih berlaku (05 `admin.assignments.update`): hanya atasan, mode absen, dan
 * catatan. Jabatan/lokasi/tanggal tidak diubah di sini karena itu mutasi (riwayat harus utuh).
 */
final class UpdateAssignment
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{supervisor_employee_id: int|null, attendance_mode: string|null, note: string|null}  $data
     */
    public function handle(Assignment $assignment, array $data, ?int $userId): Assignment
    {
        return DB::transaction(function () use ($assignment, $data, $userId) {
            $fail = fn (string $key, string $message) => throw ValidationException::withMessages([$key => $message]);

            SupervisorCycleGuard::lock();
            $locked = Assignment::query()->lockForUpdate()->findOrFail($assignment->id);
            $today = DisplayTime::toLocal(now())->toDateString();

            if ($locked->end_date !== null && $locked->end_date->toDateString() < $today) {
                $fail('supervisor_employee_id', 'Penugasan yang sudah berakhir tidak bisa diubah.');
            }

            $supervisorId = $data['supervisor_employee_id'];
            if ($supervisorId !== null && $supervisorId !== $locked->supervisor_employee_id) {
                if (! Employee::query()->whereKey($supervisorId)->where('status', 'active')->exists()) {
                    $fail('supervisor_employee_id', 'Atasan harus pegawai yang masih aktif.');
                }
                $violation = SupervisorCycleGuard::violation($locked->employee_id, $supervisorId);
                if ($violation !== null) {
                    $fail('supervisor_employee_id', $violation);
                }
            }

            $before = CreateAssignment::audited($locked);
            $locked->fill([
                'supervisor_employee_id' => $supervisorId,
                'attendance_mode' => $data['attendance_mode'],
                'note' => $data['note'],
            ]);

            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->updated_by = $userId;
            $locked->save();

            $this->audit->record('assignment.updated', $locked, $before, CreateAssignment::audited($locked));

            // Mode absen/atasan baru berlaku mulai hari ini (atau mulai penugasan bila terjadwal).
            $from = max($today, $locked->start_date->toDateString());
            AssignmentChanged::dispatch($locked->employee_id, $from, 'updated');

            return $locked;
        });
    }
}
