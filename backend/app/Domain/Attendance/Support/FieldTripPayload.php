<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Attendance\Models\FieldTripDestination;
use App\Domain\Employee\Models\Employee;
use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;

/**
 * Objek tugas luar kota untuk API (05 §5.10, T-036A bagian 2). Titik tujuan hanya nama + radius:
 * koordinat tidak dibutuhkan HP untuk menampilkan tugas. Relasi dimuat pemanggil (RELATIONS).
 */
final class FieldTripPayload
{
    public const RELATIONS = ['employees', 'destinations', 'requester', 'decider'];

    /** @return array<string, mixed> */
    public static function toArray(FieldTrip $t): array
    {
        return [
            'id' => $t->id,
            'number' => $t->number,
            'status' => $t->status->value,
            'start_date' => $t->start_date->toDateString(),
            'end_date' => $t->end_date->toDateString(),
            'days' => $t->dayCount(),
            'destination' => $t->destination,
            'purpose' => $t->purpose,
            'attendance_rule' => $t->attendance_rule->value,
            'destinations' => $t->destinations->map(fn (FieldTripDestination $d) => ['name' => $d->name, 'radius_m' => $d->radius_m])->values()->all(),
            'employees' => $t->employees->sortBy('full_name')
                ->map(fn (Employee $e) => ['id' => $e->id, 'employee_number' => $e->employee_number, 'name' => $e->full_name])->values()->all(),
            'requested_by' => $t->requester === null ? null : ['name' => $t->requester->name],
            'is_request' => $t->requested_by !== null,
            'submitted_at' => self::time($t->created_at),
            'decided_at' => self::time($t->decided_at),
            'decided_by' => $t->decider === null ? null : ['name' => $t->decider->name],
            'decision_note' => $t->decision_note,
        ];
    }

    private static function time(?CarbonInterface $moment): ?string
    {
        return $moment === null ? null : DisplayTime::toLocal($moment)->toIso8601String();
    }
}
