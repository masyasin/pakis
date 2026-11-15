<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Employee\Models\Employee;
use Illuminate\Validation\ValidationException;

/**
 * BR-071: satu pegawai tidak boleh punya dua tugas `approved` yang tanggalnya tumpang tindih.
 * Dipanggil DI DALAM transaksi; baris pegawai dikunci lebih dulu agar dua tugas untuk pegawai
 * yang sama tidak bisa lolos bersamaan.
 */
final class FieldTripGuard
{
    /** @param list<int> $employeeIds */
    public static function assertNoOverlap(array $employeeIds, string $start, string $end, ?int $exceptTripId = null): void
    {
        Employee::withTrashed()->whereKey($employeeIds)->orderBy('id')->lockForUpdate()->get(['id']);

        $clash = FieldTrip::query()
            ->with('employees:id,full_name')
            ->where('status', FieldTripStatus::Approved->value)
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start)
            ->when($exceptTripId !== null, fn ($q) => $q->whereKeyNot($exceptTripId))
            ->whereHas('employees', fn ($q) => $q->whereIn('employees.id', $employeeIds))
            ->orderBy('start_date')
            ->first();

        if ($clash !== null) {
            $names = $clash->employees->whereIn('id', $employeeIds)->pluck('full_name')->sort()->implode(', ');

            throw ValidationException::withMessages([
                'employee_ids' => "{$names} sudah punya tugas {$clash->number} pada tanggal yang beririsan.",
            ]);
        }
    }
}
