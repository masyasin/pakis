<?php

namespace App\Domain\Access\Support;

use App\Support\Time\DisplayTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * OrgStructure dari tabel `assignments` & `locations` (04 §2.8–2.9). Penugasan aktif =
 * `start_date ≤ hari ini` dan (`end_date` kosong atau ≥ hari ini), memakai tanggal LOKAL
 * (`general.timezone`) karena tanggal penugasan adalah tanggal kalender kantor.
 *
 * Penugasan tambahan (`is_primary = false`) ikut scope `location` dua arah (BR-051): pegawai yang
 * merangkap di lokasi X terlihat oleh scope X, dan pemegang scope lokasi yang merangkap di X ikut
 * melihat pegawai X. Diuji dengan tabel nyata di tests/Feature/Employee (T-024, T-025).
 */
final class DatabaseOrgStructure implements OrgStructure
{
    public function primaryLocationOf(int $employeeId): ?int
    {
        $location = $this->active()->where('employee_id', $employeeId)->where('is_primary', true)->value('location_id');

        return $location === null ? null : (int) $location;
    }

    public function assignedLocationsOf(int $employeeId): array
    {
        return array_values($this->active()->where('employee_id', $employeeId)
            ->distinct()->pluck('location_id')->map(fn ($id): int => (int) $id)->values()->all());
    }

    public function locationsHeadedBy(int $employeeId): array
    {
        return array_values(DB::table('locations')
            ->where('head_employee_id', $employeeId)
            ->whereNull('deleted_at')
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all());
    }

    public function employeesAtLocations(array $locationIds): array
    {
        return array_values($this->active()
            ->whereIn('location_id', $locationIds)
            ->distinct()
            ->pluck('employee_id')->map(fn ($id): int => (int) $id)->values()->all());
    }

    public function directReportsOf(array $employeeIds): array
    {
        return array_values($this->active()
            ->whereIn('supervisor_employee_id', $employeeIds)
            ->distinct()
            ->pluck('employee_id')->map(fn ($id): int => (int) $id)->values()->all());
    }

    private function active(): Builder
    {
        $today = DisplayTime::toLocal(now())->toDateString();

        return DB::table('assignments')
            ->where('start_date', '<=', $today)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today));
    }
}
