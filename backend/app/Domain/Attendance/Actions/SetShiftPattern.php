<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\ShiftPattern;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Tetapkan pola mingguan untuk banyak pegawai sekaligus (FR-032, BR-061). Pola dengan tanggal
 * berlaku yang SAMA diperbarui; tanggal berbeda = baris baru, pola lama tetap sebagai riwayat
 * (jadwal sebelum tanggal berlaku tidak berubah). Pegawai & shift sudah divalidasi
 * ShiftPatternRequest (aktif, dalam scope). Audit `schedule.pattern_set` per pegawai yang berubah.
 */
final class SetShiftPattern
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<int>  $employeeIds
     * @param  array<string, int|null>  $days  `mon..sun` → shift id (null = libur)
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function handle(array $employeeIds, string $effectiveFrom, array $days, ?int $userId): array
    {
        $columns = [];
        foreach (ShiftPattern::DAYS as $day) {
            $columns[ShiftPattern::column($day)] = $days[$day] ?? null;
        }

        return DB::transaction(function () use ($employeeIds, $effectiveFrom, $columns, $userId) {
            $existing = ShiftPattern::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('effective_from', $effectiveFrom)
                ->lockForUpdate()
                ->get()
                ->keyBy('employee_id');

            $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

            foreach ($employeeIds as $employeeId) {
                $pattern = $existing->get($employeeId);

                if ($pattern === null) {
                    $pattern = new ShiftPattern(['employee_id' => $employeeId, 'effective_from' => $effectiveFrom, ...$columns]);
                    $pattern->created_by = $userId;
                    $pattern->updated_by = $userId;
                    $pattern->save();
                    $this->audit->record('schedule.pattern_set', $pattern, null, self::auditValues($pattern));
                    $summary['created']++;

                    continue;
                }

                $before = self::auditValues($pattern);
                $pattern->fill($columns);
                if (! $pattern->isDirty()) {
                    $summary['unchanged']++;

                    continue;
                }

                $pattern->updated_by = $userId;
                $pattern->save();
                $this->audit->record('schedule.pattern_set', $pattern, $before, self::auditValues($pattern));
                $summary['updated']++;
            }

            return $summary;
        });
    }

    /** @return array<string, mixed> */
    public static function auditValues(ShiftPattern $pattern): array
    {
        $values = [
            'employee_id' => $pattern->employee_id,
            'effective_from' => $pattern->effective_from->toDateString(),
        ];
        foreach ($pattern->days() as $day => $shiftId) {
            $values[ShiftPattern::column($day)] = $shiftId;
        }

        return $values;
    }
}
