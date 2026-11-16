<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Attendance\Notifications\FieldTripSubmitted;
use App\Domain\Attendance\Support\CorrectionAuthority;
use App\Domain\Audit\AuditLogger;
use App\Domain\Numbering\NumberGenerator;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Pegawai mengajukan tugas luar kota dari Pakis Pro (T-036A bagian 2, 06 §7.2a): status `pending`,
 * pegawai = pengaju sendiri, belum membuka lokasi tambahan (FieldTripAreas hanya membaca
 * `approved`). Tumpang tindih dengan tugas `approved` ditolak sejak awal (BR-071) dan dicek ulang
 * saat disetujui. Penyetuju diberi tahu dengan pola koreksi (BR-070).
 */
final class RequestFieldTrip
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
        private readonly CorrectionAuthority $authority,
    ) {}

    /**
     * @param  array<string, mixed>  $data  kunci = FieldTrip::AUDITED
     * @param  list<array{name: string, latitude: float, longitude: float, radius_m: int|null}>  $destinations
     */
    public function handle(User $actor, int $employeeId, array $data, array $destinations): FieldTrip
    {
        $trip = DB::transaction(function () use ($actor, $employeeId, $data, $destinations) {
            FieldTripGuard::assertNoOverlap([$employeeId], (string) $data['start_date'], (string) $data['end_date']);

            $trip = new FieldTrip($data);
            $trip->number = $this->numbers->next('field_trip', isTaken: fn (string $n) => FieldTrip::query()->where('number', $n)->exists());
            $trip->status = FieldTripStatus::Pending;
            $trip->requested_by = $actor->id;
            $trip->created_by = $actor->id;
            $trip->updated_by = $actor->id;
            $trip->save();
            $trip->employees()->sync([$employeeId]);
            $trip->syncDestinations($destinations);

            $this->audit->record('field_trip.requested', $trip, null, [
                'number' => $trip->number,
                'status' => $trip->status->value,
                ...$trip->auditValues([$employeeId], $destinations),
            ]);

            return $trip;
        });

        $trip->load(['employees', 'destinations']);
        $employee = $trip->employees->first();
        Notification::send(
            $this->authority->recipientsForEmployee($employeeId, $this->authority->approverFor($employeeId), $actor->id),
            new FieldTripSubmitted($trip->number, (string) $employee?->full_name, self::period($trip), $trip->destination),
        );

        return $trip;
    }

    /** `16-11-2026` atau `16-11-2026 s.d. 18-11-2026`. */
    public static function period(FieldTrip $trip): string
    {
        $start = $trip->start_date->format('d-m-Y');
        $end = $trip->end_date->format('d-m-Y');

        return $start === $end ? $start : "{$start} s.d. {$end}";
    }
}
