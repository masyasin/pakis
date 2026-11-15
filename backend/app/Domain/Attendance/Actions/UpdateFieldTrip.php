<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Ubah tugas `approved` yang belum selesai (06 §7.2a; izin & scope dicek FieldTripPolicy). Audit
 * `field_trip.updated` hanya kunci yang berubah (termasuk daftar pegawai).
 */
final class UpdateFieldTrip
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  kunci = FieldTrip::AUDITED
     * @param  list<int>  $employeeIds
     * @param  list<array{name: string, latitude: float, longitude: float, radius_m: int|null}>  $destinations
     */
    public function handle(FieldTrip $trip, array $data, array $employeeIds, array $destinations, ?int $userId): FieldTrip
    {
        return DB::transaction(function () use ($trip, $data, $employeeIds, $destinations, $userId) {
            $locked = FieldTrip::query()->lockForUpdate()->findOrFail($trip->id);
            $before = $locked->auditValues($locked->employeeIds(), $locked->destinationValues());

            FieldTripGuard::assertNoOverlap($employeeIds, (string) $data['start_date'], (string) $data['end_date'], $locked->id);

            $locked->fill($data);
            $locked->updated_by = $userId;
            $locked->save();
            $locked->employees()->sync($employeeIds);
            $locked->syncDestinations($destinations);

            $after = $locked->auditValues($employeeIds, $destinations);
            if ($after !== $before) {
                $this->audit->record('field_trip.updated', $locked, $before, $after);
            }

            return $locked;
        });
    }
}
