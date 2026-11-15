<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Access\Actions\ToggleUser;
use App\Domain\Audit\AuditLogger;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Aktifkan kembali pegawai (06 §7.3, BR-053): wajib penugasan utama baru. Keputusan Mas 15 Nov 2026:
 * tanggal masuk diganti tanggal aktif kembali (masa kerja dihitung ulang; tanggal masuk/keluar lama
 * tetap di audit & riwayat penugasan), dan akun login ikut diaktifkan bila dicentang SERTA pelaku
 * berwenang atas akun itu.
 */
final class RehireEmployee
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CreateAssignment $assignments,
        private readonly ToggleUser $accounts,
    ) {}

    /**
     * @param  array{rehire_date: string, position_id: int, location_id: int, supervisor_employee_id: int|null, attendance_mode: string|null, note: string|null}  $data
     * @return array{employee: Employee, account: 'activated'|'unchanged'|'not_allowed'|'none'}
     */
    public function handle(User $actor, Employee $employee, array $data, bool $activateAccount): array
    {
        return DB::transaction(function () use ($actor, $employee, $data, $activateAccount) {
            $fail = fn (string $key, string $message) => throw ValidationException::withMessages([$key => $message]);

            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $date = $data['rehire_date'];
            $today = DisplayTime::toLocal(now());

            if ($locked->isActive()) {
                $fail('rehire_date', 'Pegawai ini masih aktif.');
            }
            if ($locked->exit_date !== null && $date <= $locked->exit_date->toDateString()) {
                $fail('rehire_date', 'Tanggal aktif kembali harus setelah tanggal keluar ('.$locked->exit_date->translatedFormat('j F Y').').');
            }
            if ($date > $today->toDateString()) {
                $fail('rehire_date', 'Tanggal aktif kembali tidak boleh melewati hari ini.');
            }
            if ($date < $today->copy()->subDays(MutateEmployee::MAX_BACKDATE_DAYS)->toDateString()) {
                $fail('rehire_date', 'Tanggal aktif kembali paling jauh '.MutateEmployee::MAX_BACKDATE_DAYS.' hari ke belakang.');
            }

            $before = [
                ...CreateEmployee::audited($locked),
                'status' => $locked->status->value,
                'exit_date' => $locked->exit_date?->toDateString(),
                'exit_reason_id' => $locked->exit_reason_id,
                'exit_note' => $locked->exit_note,
            ];

            $locked->forceFill([
                'status' => EmployeeStatus::Active,
                'join_date' => $date,
                'exit_date' => null,
                'exit_reason_id' => null,
                'exit_note' => null,
                'updated_by' => $actor->id,
            ])->save();

            $this->assignments->handle($locked, [
                'position_id' => $data['position_id'],
                'location_id' => $data['location_id'],
                'supervisor_employee_id' => $data['supervisor_employee_id'],
                'attendance_mode' => $data['attendance_mode'],
                'note' => $data['note'],
                'start_date' => $date,
                'is_primary' => true,
            ], $actor->id, '');

            $account = $this->account($actor, $locked, $activateAccount);

            $this->audit->record('employee.rehired', $locked, $before, [
                ...CreateEmployee::audited($locked),
                'status' => EmployeeStatus::Active->value,
                'exit_date' => null,
                'exit_reason_id' => null,
                'exit_note' => null,
                'account' => $account,
            ]);

            return ['employee' => $locked, 'account' => $account];
        });
    }

    /** @return 'activated'|'unchanged'|'not_allowed'|'none' */
    private function account(User $actor, Employee $employee, bool $activate): string
    {
        $user = User::query()->where('employee_id', $employee->id)->first();
        if ($user === null) {
            return 'none';
        }
        if (! $activate || $user->is_active) {
            return 'unchanged';
        }
        // Sama dengan tombol Aktifkan di halaman Pengguna (UserPolicy: hak target ⊆ pelaku).
        if (! $actor->can('users.deactivate') || ! Gate::forUser($actor)->allows('users.manage-account', $user)) {
            return 'not_allowed';
        }

        $this->accounts->handle($actor, $user, true);

        return 'activated';
    }
}
