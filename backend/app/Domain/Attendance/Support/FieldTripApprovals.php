<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Employee\Models\Assignment;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Siapa memutuskan pengajuan tugas luar kota dari Pakis Pro (T-036A bagian 2, 06 §6 & §7.2a).
 * Aturan sama dengan koreksi (CorrectionAuthority, izin `field_trips.approve`): atasan langsung
 * pengaju, atau pemegang scope `all`/`location` yang pegawainya dalam scope; bukan milik sendiri
 * (SEC-031). Pengajuan HP selalu berisi satu pegawai (pengaju) dan `requested_by` terisi.
 * Atasan dihitung saat diputuskan (tabel `field_trips` tidak menyimpan penyetuju; tanpa
 * perubahan skema), jadi mutasi atasan di antaranya ikut berlaku.
 */
final class FieldTripApprovals
{
    public const PERMISSION = 'field_trips.approve';

    public function __construct(
        private readonly CorrectionAuthority $authority,
        private readonly DataScope $scope,
    ) {}

    /** Pegawai pengaju (satu-satunya pegawai tugas yang diajukan dari HP). */
    public static function requesterEmployeeId(FieldTrip $trip): ?int
    {
        $ids = $trip->employeeIds();

        return count($ids) === 1 ? $ids[0] : null;
    }

    public static function isOwn(User $user, FieldTrip $trip): bool
    {
        return $user->employee_id !== null && in_array((int) $user->employee_id, $trip->employeeIds(), true);
    }

    /** Syarat wewenang (tanpa status & milik sendiri, dicek pemanggil agar galatnya tepat). */
    public function mayDecide(User $user, FieldTrip $trip): bool
    {
        $employeeId = self::requesterEmployeeId($trip);
        if ($trip->requested_by === null || $employeeId === null) {
            return false;
        }

        return $this->authority->mayDecideFor($user, $employeeId, $this->authority->approverFor($employeeId), self::PERMISSION);
    }

    /**
     * Pengajuan yang boleh diputuskan pengguna (daftar `approval_field_trips` & tombol web), tanpa
     * milik sendiri.
     *
     * @param  Builder<FieldTrip>  $query
     * @return Builder<FieldTrip>
     */
    public function constrainDecidable(Builder $query, User $user): Builder
    {
        if (! $user->can(self::PERMISSION)) {
            return $query->whereRaw('1 = 0');
        }

        $self = $user->employee_id === null ? null : (int) $user->employee_id;
        $broad = $this->authority->hasBroadScope($user);
        $ids = $broad ? $this->scope->visibleEmployeeIds($user) : [];
        $today = DisplayTime::toLocal(now())->toDateString();

        $query->whereNotNull('field_trips.requested_by');
        if ($self !== null) {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('field_trip_employees')
                ->whereColumn('field_trip_employees.field_trip_id', 'field_trips.id')
                ->where('field_trip_employees.employee_id', $self));
        }

        return $query->whereExists(function ($q) use ($self, $broad, $ids, $today) {
            $q->selectRaw('1')->from('field_trip_employees')
                ->whereColumn('field_trip_employees.field_trip_id', 'field_trips.id')
                ->where(function ($w) use ($self, $broad, $ids, $today) {
                    // a) atasan langsung pada penugasan utama hari ini (06 §6 syarat 4a).
                    if ($self !== null) {
                        $w->whereIn('field_trip_employees.employee_id', Assignment::query()->select('employee_id')
                            ->where('is_primary', true)->where('supervisor_employee_id', $self)
                            ->where('start_date', '<=', $today)
                            ->where(fn ($a) => $a->whereNull('end_date')->orWhere('end_date', '>=', $today)));
                    } else {
                        $w->whereRaw('1 = 0');
                    }
                    // b) scope all/location: pegawai dalam scope (null = semua).
                    if ($broad) {
                        $ids === null ? $w->orWhereRaw('1 = 1') : $w->orWhereIn('field_trip_employees.employee_id', $ids === [] ? [0] : $ids);
                    }
                });
        });
    }

    /** Status yang bisa diputuskan. */
    public static function decidable(FieldTrip $trip): bool
    {
        return $trip->status === FieldTripStatus::Pending;
    }
}
