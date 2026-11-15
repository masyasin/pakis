<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Audit\AuditLogger;
use App\Domain\Numbering\NumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Buat tugas luar kota yang LANGSUNG berlaku (T-036A bagian 1, 06 §7.2a): dibuat pemegang
 * `field_trips.manage` untuk pegawai dalam scope-nya (divalidasi FieldTripRequest). Nomor dari
 * NumberGenerator di transaksi yang sama (CLAUDE.md §3). Audit `field_trip.created`.
 */
final class CreateFieldTrip
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data  kunci = FieldTrip::AUDITED
     * @param  list<int>  $employeeIds
     * @param  list<array{name: string, latitude: float, longitude: float, radius_m: int|null}>  $destinations
     */
    public function handle(array $data, array $employeeIds, array $destinations, ?int $userId): FieldTrip
    {
        return DB::transaction(function () use ($data, $employeeIds, $destinations, $userId) {
            FieldTripGuard::assertNoOverlap($employeeIds, (string) $data['start_date'], (string) $data['end_date']);

            $trip = new FieldTrip($data);
            $trip->number = $this->numbers->next('field_trip', isTaken: fn (string $n) => FieldTrip::query()->where('number', $n)->exists());
            $trip->status = FieldTripStatus::Approved;
            $trip->decided_by = $userId;
            $trip->decided_at = now();
            $trip->created_by = $userId;
            $trip->updated_by = $userId;
            $trip->save();
            $trip->employees()->sync($employeeIds);
            $trip->syncDestinations($destinations);

            $this->audit->record('field_trip.created', $trip, null, [
                'number' => $trip->number,
                'status' => $trip->status->value,
                ...$trip->auditValues($employeeIds, $destinations),
            ]);

            return $trip;
        });
    }
}
