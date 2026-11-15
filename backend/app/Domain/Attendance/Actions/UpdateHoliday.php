<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Events\ScheduleChanged;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Ubah hari libur (FR-033). Status harian yang sudah dihitung diperbarui job harian/rekalkulasi
 * absensi (T-036), bukan di sini.
 */
final class UpdateHoliday
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  kunci = Holiday::AUDITED
     * @param  list<int>  $locationIds  kosong bila `applies_to_all`
     */
    public function handle(Holiday $holiday, array $data, array $locationIds, ?int $userId): Holiday
    {
        return DB::transaction(function () use ($holiday, $data, $locationIds, $userId) {
            $locked = Holiday::query()->lockForUpdate()->findOrFail($holiday->id);
            $before = $locked->auditValues($locked->locationIds());
            $oldFrom = $locked->start_date->toDateString();
            $oldTo = $locked->end_date->toDateString();

            $locked->fill($data);
            $locationIds = $locked->applies_to_all ? [] : $locationIds;
            $after = $locked->auditValues($locationIds);

            if ($before === $after) {
                return $locked;
            }

            $locked->updated_by = $userId;
            $locked->save();
            $locked->locations()->sync($locationIds);

            $this->audit->record('holiday.updated', $locked, $before, $after);

            // Rentang lama ∪ baru: tanggal yang tidak lagi libur juga harus dihitung ulang.
            ScheduleChanged::dispatch(null, min($oldFrom, $locked->start_date->toDateString()), max($oldTo, $locked->end_date->toDateString()));

            return $locked;
        });
    }
}
