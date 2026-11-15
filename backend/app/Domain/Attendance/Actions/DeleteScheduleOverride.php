<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\ScheduleOverride;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Hapus pengecualian jadwal (05 §4.9): tanggal itu kembali mengikuti pola & hari libur. Hard
 * delete (04: tanpa [soft]); nilai lama tersimpan di audit `schedule.override_deleted`.
 */
final class DeleteScheduleOverride
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ScheduleOverride $override): void
    {
        DB::transaction(function () use ($override) {
            $locked = ScheduleOverride::query()->lockForUpdate()->findOrFail($override->id);
            $before = $locked->auditValues();

            $locked->delete();

            $this->audit->record('schedule.override_deleted', $locked, $before, null);
        });
    }
}
