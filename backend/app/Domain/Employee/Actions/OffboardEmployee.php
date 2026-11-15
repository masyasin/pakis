<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Access\Actions\RevokeUserSessions;
use App\Domain\Access\Actions\ToggleUser;
use App\Domain\Audit\AuditLogger;
use App\Domain\Device\Actions\RevokeDevice;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Events\AssignmentChanged;
use App\Domain\Employee\Events\EmployeeOffboarded;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\OffboardImpact;
use App\Domain\Organization\Models\Location;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Nonaktifkan pegawai (FR-027, BR-053, SEC-021, diagram 03 §7.2). SATU transaksi: penugasan
 * ditutup, status nonaktif, akun nonaktif + sesi web dihapus + token dicabut, HP aktif dicabut,
 * HP menunggu ditolak (keputusan Mas 15 Nov 2026), kepala lokasi dikosongkan. Gagal di mana pun →
 * rollback penuh, tidak ada perubahan parsial. Data pegawai TIDAK dihapus.
 */
final class OffboardEmployee
{
    public const PENDING_DEVICE_REASON = 'Pegawai dinonaktifkan';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ToggleUser $accounts,
        private readonly RevokeUserSessions $sessions,
        private readonly RevokeDevice $devices,
    ) {}

    /**
     * @param  array{exit_date: string, exit_reason_id: int, exit_note: string|null}  $data
     */
    public function handle(User $actor, Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($actor, $employee, $data) {
            $fail = fn (string $key, string $message) => throw ValidationException::withMessages([$key => $message]);

            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $exit = $data['exit_date'];
            $today = DisplayTime::toLocal(now())->toDateString();

            if (! $locked->isActive()) {
                $fail('exit_date', 'Pegawai ini sudah nonaktif.');
            }
            if ($exit < $locked->join_date->toDateString()) {
                $fail('exit_date', 'Tanggal keluar tidak boleh sebelum tanggal masuk ('.$locked->join_date->translatedFormat('j F Y').').');
            }
            if ($exit > $today) {
                $fail('exit_date', 'Tanggal keluar tidak boleh melewati hari ini.');
            }
            if (! ReferenceItem::query()->forGroup(ReferenceGroup::OffboardingReason)->whereKey($data['exit_reason_id'])->where('is_active', true)->exists()) {
                $fail('exit_reason_id', 'Pilih alasan keluar yang aktif.');
            }

            $impact = OffboardImpact::for($locked);
            $before = CreateEmployee::audited($locked);

            // 1. Penugasan: yang berjalan ditutup per tanggal keluar; yang terjadwal setelahnya belum
            //    pernah berlaku, jadi dihapus (tetap tercatat di audit).
            foreach (CreateAssignment::stillRunning($locked->id)->lockForUpdate()->get() as $assignment) {
                $old = CreateAssignment::audited($assignment);
                if ($assignment->start_date->toDateString() > $exit) {
                    $assignment->delete();
                    $this->audit->record('assignment.ended', $assignment, $old, ['deleted' => true, 'reason' => 'nonaktif sebelum mulai']);

                    continue;
                }
                $assignment->forceFill(['end_date' => $exit, 'updated_by' => $actor->id])->save();
                $this->audit->record('assignment.ended', $assignment, $old, [...CreateAssignment::audited($assignment), 'reason' => 'nonaktif']);
            }

            // 2. Status pegawai.
            $locked->forceFill([
                'status' => EmployeeStatus::Inactive,
                'exit_date' => $exit,
                'exit_reason_id' => $data['exit_reason_id'],
                'exit_note' => $data['exit_note'],
                'updated_by' => $actor->id,
            ])->save();

            // 3. Akun: nonaktif + semua sesi web dihapus + token API dicabut (ToggleUser → RevokeUserSessions).
            $user = User::query()->where('employee_id', $locked->id)->lockForUpdate()->first();
            if ($user !== null) {
                // ToggleUser berhenti bila akun sudah nonaktif; sisa sesi/token tetap harus dicabut.
                $user->is_active
                    ? $this->accounts->handle($actor, $user, false)
                    : $this->sessions->handle($user);

                // 4. Perangkat: aktif → dicabut; menunggu → ditolak.
                foreach (Device::query()->where('user_id', $user->id)->where('status', DeviceStatus::Active)->get() as $device) {
                    $this->devices->handle($device, $actor, 'offboarded');
                }
                foreach (Device::query()->where('user_id', $user->id)->where('status', DeviceStatus::Pending)->lockForUpdate()->get() as $device) {
                    $device->forceFill([
                        'status' => DeviceStatus::Rejected,
                        'request_reason' => self::PENDING_DEVICE_REASON,
                        'revoked_by' => $actor->id,
                        'revoked_at' => now(),
                    ])->save();
                    $this->audit->record('device.rejected', $device, ['status' => DeviceStatus::Pending->value], [
                        'status' => DeviceStatus::Rejected->value,
                        'request_reason' => self::PENDING_DEVICE_REASON,
                    ]);
                }
            }

            // 5. Kepala lokasi dikosongkan.
            foreach (Location::query()->where('head_employee_id', $locked->id)->lockForUpdate()->get() as $location) {
                $location->forceFill(['head_employee_id' => null, 'updated_by' => $actor->id])->save();
                $this->audit->record('location.updated', $location, ['head_employee_id' => $locked->id], ['head_employee_id' => null]);
            }

            $this->audit->record('employee.offboarded', $locked, $before, [
                ...CreateEmployee::audited($locked),
                'status' => EmployeeStatus::Inactive->value,
                'exit_date' => $exit,
                'exit_reason_id' => $data['exit_reason_id'],
                'exit_note' => $data['exit_note'],
                'impact' => [
                    'sessions' => $impact['sessions'],
                    'tokens' => $impact['tokens'],
                    'devices_revoked' => $impact['devices_active'],
                    'devices_rejected' => $impact['devices_pending'],
                    'assignments_closed' => $impact['assignments'],
                    'locations_unheaded' => count($impact['headed_locations']),
                ],
            ]);

            AssignmentChanged::dispatch($locked->id, $exit, 'ended');
            EmployeeOffboarded::dispatch($locked->id, $exit);

            return $locked;
        });
    }
}
