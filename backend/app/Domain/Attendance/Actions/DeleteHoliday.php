<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Hapus hari libur (05 §4.9). Hard delete (04: tanpa [soft]); `holiday_locations` ikut terhapus
 * lewat CASCADE. Nilai lama tersimpan di audit agar penghapusan tetap bisa ditelusuri.
 */
final class DeleteHoliday
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Holiday $holiday): void
    {
        DB::transaction(function () use ($holiday) {
            $locked = Holiday::query()->lockForUpdate()->findOrFail($holiday->id);
            $before = $locked->auditValues($locked->locationIds());

            $locked->delete();

            $this->audit->record('holiday.deleted', $locked, $before, null);
        });
    }
}
