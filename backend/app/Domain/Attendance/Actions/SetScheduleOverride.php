<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\ScheduleOverride;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Pengecualian jadwal satu tanggal untuk banyak pegawai (FR-032, BR-061/BR-062): shift pengganti,
 * atau libur bila `$shiftId` null. Satu baris per (pegawai, tanggal): yang sudah ada ditimpa
 * (upsert). Pegawai & shift sudah divalidasi ScheduleOverrideRequest. Audit `schedule.override_set`
 * per pegawai yang berubah.
 */
final class SetScheduleOverride
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<int>  $employeeIds
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function handle(array $employeeIds, string $date, ?int $shiftId, ?string $note, ?int $userId): array
    {
        return DB::transaction(function () use ($employeeIds, $date, $shiftId, $note, $userId) {
            $existing = ScheduleOverride::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('date', $date)
                ->lockForUpdate()
                ->get()
                ->keyBy('employee_id');

            $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

            foreach ($employeeIds as $employeeId) {
                $override = $existing->get($employeeId);

                if ($override === null) {
                    $override = new ScheduleOverride(['employee_id' => $employeeId, 'date' => $date, 'shift_id' => $shiftId, 'note' => $note]);
                    $override->created_by = $userId;
                    $override->updated_by = $userId;
                    $override->save();
                    $this->audit->record('schedule.override_set', $override, null, $override->auditValues());
                    $summary['created']++;

                    continue;
                }

                $before = $override->auditValues();
                $override->fill(['shift_id' => $shiftId, 'note' => $note]);
                if (! $override->isDirty()) {
                    $summary['unchanged']++;

                    continue;
                }

                $override->updated_by = $userId;
                $override->save();
                $this->audit->record('schedule.override_set', $override, $before, $override->auditValues());
                $summary['updated']++;
            }

            return $summary;
        });
    }
}
