<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Access\DataScope;
use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Support\ScopeResolver;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Organization\Support\LocationScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scope data `attendance_days` (06 §5, SEC-033). Scope `location` memakai SNAPSHOT
 * `attendance_days.location_id` (lokasi penugasan utama pada tanggal itu), bukan penugasan hari
 * ini: riwayat absen di sebuah lokasi tetap milik lokasi itu walau pegawainya sudah dimutasi.
 * Scope `team`/`self` per pegawai (DataScope), `all` tanpa batas.
 */
final class AttendanceDayScope
{
    public function __construct(
        private readonly DataScope $employees,
        private readonly LocationScope $locations,
    ) {}

    /**
     * @param  Builder<AttendanceDay>  $query
     * @return Builder<AttendanceDay>
     */
    public function apply(Builder $query, User $user): Builder
    {
        if (ScopeResolver::for($user) !== Scope::Location) {
            $this->employees->apply($query, $user, 'attendance_days.employee_id');

            return $query;
        }

        $ids = $this->locations->visibleIds($user) ?? [];

        return $ids === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('attendance_days.location_id', $ids);
    }

    public function allows(User $user, AttendanceDay $day): bool
    {
        if (ScopeResolver::for($user) !== Scope::Location) {
            return $this->employees->allows($user, $day->employee_id);
        }

        return $day->location_id !== null && in_array($day->location_id, $this->locations->visibleIds($user) ?? [], true);
    }
}
