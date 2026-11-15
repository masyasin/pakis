<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Listings\AttendanceDayListing;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Time\DisplayTime;

/**
 * Isi tab Presensi di detail pegawai (T-038): 14 hari terakhir + ringkasan status 30 hari. Baris
 * mengikuti scope presensi pelaku (AttendanceDayScope: scope lokasi = snapshot), jadi kepala lokasi
 * hanya melihat hari-hari pegawai itu di lokasinya. Tanpa `attendance.view` → null (tab memberi tahu).
 */
final class EmployeeAttendance
{
    public const SHOWN_DAYS = 14;

    public const SUMMARY_DAYS = 30;

    /**
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, from: string, to: string}|null
     */
    public static function for(Employee $employee, User $actor): ?array
    {
        if (! $actor->can('attendance.view')) {
            return null;
        }

        $today = DisplayTime::toLocal(now());
        $to = $today->toDateString();
        $from = $today->subDays(self::SUMMARY_DAYS - 1)->toDateString();

        $query = app(AttendanceDayScope::class)->apply(AttendanceDay::query(), $actor)
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from, $to]);

        $summary = [];
        foreach ((clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status') as $status => $total) {
            $summary[(string) $status] = (int) $total;
        }

        $rows = (clone $query)
            ->with('shift:id,name')
            ->where('work_date', '>', $today->subDays(self::SHOWN_DAYS)->toDateString())
            ->orderByDesc('work_date')
            ->get()
            ->map(fn (AttendanceDay $d) => [
                'id' => $d->id,
                'work_date' => $d->work_date->toDateString(),
                'shift' => $d->shift?->name,
                'check_in' => AttendanceDayListing::clock($d->check_in_at, $d->work_date->toDateString()),
                'check_out' => AttendanceDayListing::clock($d->check_out_at, $d->work_date->toDateString()),
                'late_minutes' => $d->late_minutes,
                'status' => $d->status->value,
                'status_label' => $d->status->label(),
                'is_offline' => $d->is_offline,
                'is_corrected' => $d->is_corrected,
                'has_anomaly' => $d->has_anomaly,
            ]);

        return [
            'rows' => array_values($rows->all()),
            'summary' => array_merge(array_fill_keys(array_map(fn (AttendanceStatus $s) => $s->value, AttendanceStatus::cases()), 0), $summary),
            'from' => $from,
            'to' => $to,
        ];
    }
}
