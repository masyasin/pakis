<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Access\DataScope;
use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Support\ScopeResolver;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Siapa boleh memutuskan koreksi (06 §6, BR-069) — satu pintu untuk Policy, binding API, listing
 * `approval_corrections`, dan presensi manual. Syarat (selain status `pending`):
 *   2. punya `attendance_corrections.approve`;
 *   3. bukan milik sendiri (SEC-031; dicek terpisah agar galatnya 403 `CORRECTION_SELF_APPROVAL`);
 *   4. a) atasan langsung saat diajukan (`approver_employee_id`), atau
 *      b) pegawai dalam scope pengguna DAN scope-nya `all`/`location` (fallback kepala lokasi/HRD).
 * Supervisor (scope `team`) karenanya hanya memutuskan bawahan langsung, bukan bawahan tidak langsung.
 */
final class CorrectionAuthority
{
    public function __construct(private readonly DataScope $scope) {}

    /**
     * Atasan langsung pada penugasan utama HARI INI (saat diajukan, BR-069). Atasan nonaktif = tidak
     * ada (06 §6): koreksi lalu diputuskan kepala lokasi/HRD.
     */
    public function approverFor(int $employeeId): ?int
    {
        $today = DisplayTime::toLocal(now())->toDateString();
        $supervisorId = DaySnapshot::primaryAssignmentOn($employeeId, $today)?->supervisor_employee_id;
        if ($supervisorId === null || $supervisorId === $employeeId) {
            return null;
        }

        $active = Employee::query()->whereKey($supervisorId)->where('status', EmployeeStatus::Active)->exists();

        return $active ? $supervisorId : null;
    }

    public static function isOwn(User $user, int $employeeId): bool
    {
        return $user->employee_id !== null && (int) $user->employee_id === $employeeId;
    }

    /** Syarat 2 + 4 terhadap pegawai (tanpa syarat 3 & status, lihat kelas). */
    public function mayDecideFor(User $user, int $employeeId, ?int $approverEmployeeId): bool
    {
        if (! $user->can('attendance_corrections.approve')) {
            return false;
        }

        if ($approverEmployeeId !== null && $user->employee_id !== null && (int) $user->employee_id === $approverEmployeeId) {
            return true;
        }

        return $this->hasBroadScope($user) && $this->scope->allows($user, $employeeId);
    }

    /**
     * Batasi query koreksi ke yang boleh diputuskan pengguna (syarat 2 + 4) dan BUKAN miliknya
     * sendiri: daftar persetujuan & binding `{id}` API (di luar ini → 404).
     *
     * @param  Builder<AttendanceCorrection>  $query
     * @return Builder<AttendanceCorrection>
     */
    public function constrainDecidable(Builder $query, User $user): Builder
    {
        if (! $user->can('attendance_corrections.approve')) {
            return $query->whereRaw('1 = 0');
        }

        $self = $user->employee_id === null ? null : (int) $user->employee_id;
        if ($self !== null) {
            $query->where('attendance_corrections.employee_id', '!=', $self);
        }

        $broad = $this->hasBroadScope($user);
        $ids = $broad ? $this->scope->visibleEmployeeIds($user) : [];

        return $query->where(function (Builder $q) use ($self, $broad, $ids) {
            if ($self !== null) {
                $q->where('attendance_corrections.approver_employee_id', $self);
            } else {
                $q->whereRaw('1 = 0');
            }
            if ($broad) {
                // null = scope `all`: semua pegawai.
                $ids === null ? $q->orWhereRaw('1 = 1') : $q->orWhereIn('attendance_corrections.employee_id', $ids);
            }
        });
    }

    /**
     * Penerima notifikasi koreksi diajukan (BR-070): akun penyetuju; tanpa atasan aktif → kepala
     * lokasi pegawai + HRD.
     *
     * @return Collection<int, User>
     */
    public function recipientsFor(AttendanceCorrection $correction): Collection
    {
        if ($correction->approver_employee_id !== null) {
            $users = User::query()->where('employee_id', $correction->approver_employee_id)->where('is_active', true)->get();
            if ($users->isNotEmpty()) {
                return $users;
            }
        }

        $today = DisplayTime::toLocal(now())->toDateString();
        $location = DaySnapshot::primaryAssignmentOn($correction->employee_id, $today)?->location;

        return LocationOverseers::for($location, $correction->submitted_by)
            ->filter(fn (User $u) => ! self::isOwn($u, $correction->employee_id))
            ->values();
    }

    private function hasBroadScope(User $user): bool
    {
        return in_array(ScopeResolver::for($user), [Scope::All, Scope::Location], true);
    }
}
