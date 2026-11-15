<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Tambah hari libur (FR-033). Lokasi sudah divalidasi HolidayRequest (aktif, ≥ 1 bila khusus). */
final class CreateHoliday
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  kunci = Holiday::AUDITED
     * @param  list<int>  $locationIds  kosong bila `applies_to_all`
     */
    public function handle(array $data, array $locationIds, ?int $userId): Holiday
    {
        return DB::transaction(function () use ($data, $locationIds, $userId) {
            $holiday = new Holiday($data);
            $holiday->created_by = $userId;
            $holiday->updated_by = $userId;
            $holiday->save();

            $locationIds = $holiday->applies_to_all ? [] : $locationIds;
            $holiday->locations()->sync($locationIds);

            $this->audit->record('holiday.created', $holiday, null, $holiday->auditValues($locationIds));

            return $holiday;
        });
    }
}
