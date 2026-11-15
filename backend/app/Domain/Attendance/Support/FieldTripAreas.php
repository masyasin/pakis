<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Enums\FieldTripRule;
use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Attendance\Models\FieldTripDestination;

/**
 * Lokasi absen tambahan dari tugas luar kota yang berlaku (BR-071) untuk satu pegawai pada satu
 * `work_date`: hanya tugas `approved` yang rentangnya memuat tanggal itu. Hasilnya diumpankan ke
 * GeoFence di samping aturan biasa BR-064.
 */
final class FieldTripAreas
{
    /**
     * @return array{areas: list<GeoArea>, anywhereTripId: int|null}
     */
    public function for(int $employeeId, string $workDate): array
    {
        $trips = FieldTrip::query()
            ->with('destinations')
            ->approvedOn($workDate)
            ->whereHas('employees', fn ($q) => $q->where('employees.id', $employeeId))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $areas = [];
        $anywhere = null;

        foreach ($trips as $trip) {
            match ($trip->attendance_rule) {
                FieldTripRule::Anywhere => $anywhere ??= $trip->id,
                FieldTripRule::AnyLocation => array_push($areas, ...GeoFence::activeLocationAreas($trip->id)),
                FieldTripRule::Destination => array_push($areas, ...$trip->destinations->map(fn (FieldTripDestination $d) => new GeoArea(
                    $d->name,
                    (float) $d->latitude,
                    (float) $d->longitude,
                    $d->radius_m ?? (int) setting('attendance.default_radius_m'),
                    null,
                    $trip->id,
                ))->values()->all()),
            };
        }

        return ['areas' => $areas, 'anywhereTripId' => $anywhere];
    }
}
