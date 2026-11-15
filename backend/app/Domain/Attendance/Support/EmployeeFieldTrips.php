<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Employee\Models\Employee;
use App\Models\User;

/**
 * Isi tab Tugas Luar Kota di detail pegawai (T-036A): tugas terbaru pegawai ini (semua status),
 * jumlah total, dan apakah pelaku boleh membuat tugas untuknya. Detail pegawai sendiri sudah
 * diperiksa scope-nya oleh EmployeePolicy.
 */
final class EmployeeFieldTrips
{
    /** Jumlah tugas yang ditampilkan di tab (sisanya lewat daftar Tugas Luar Kota). */
    public const SHOWN = 10;

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, canCreate: bool}
     */
    public static function for(Employee $employee, User $actor): array
    {
        $query = FieldTrip::query()->whereHas('employees', fn ($q) => $q->where('employees.id', $employee->id));

        $rows = (clone $query)
            ->with('employees:id,full_name,employee_number')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->limit(self::SHOWN)
            ->get();

        return [
            'rows' => array_values($rows->map(fn (FieldTrip $t) => $t->summary())->all()),
            'total' => $query->count(),
            'canCreate' => $employee->isActive()
                && $actor->can('field_trips.manage')
                && app(DataScope::class)->allows($actor, $employee->id),
        ];
    }
}
