<?php

use App\Domain\Attendance\Enums\FieldTripRule;
use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Employee\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AttendanceWorld;

/*
 * Absen selama tugas luar kota (T-036A, BR-071). Pegawai uji mode `location` di Gudang Uji
 * (radius 100 m). Tugas 16–17 Nov 2026 (Senin–Selasa); absen jam 08.00 WIB.
 */

/** Titik tujuan fiktif ± 30 km dari gudang uji, dan titik kedua ± 20 km darinya. */
const TRIP_DEST = [-6.75, 110.65];

const TRIP_DEST_2 = [-6.9, 110.8];

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->useRealPermissions();
    $this->w = AttendanceWorld::make($this);
    $this->phone = $this->w->phone();
    $this->w->s->weekdays($this->phone['employee'], '2026-01-01');
});

afterEach(fn () => Carbon::setTestNow());

function tripFor(Employee $employee, FieldTripRule $rule, FieldTripStatus $status = FieldTripStatus::Approved, string $from = '2026-11-16', string $to = '2026-11-17'): FieldTrip
{
    $trip = FieldTrip::query()->create([
        'number' => 'DL/UJI/'.fake()->unique()->numberBetween(1000, 9999),
        'start_date' => $from,
        'end_date' => $to,
        'destination' => 'Semarang',
        'attendance_rule' => $rule,
        'status' => $status,
    ]);
    $trip->employees()->sync([$employee->id]);
    if ($rule === FieldTripRule::Destination) {
        $trip->syncDestinations([
            ['name' => 'Toko Mitra A', 'latitude' => TRIP_DEST[0], 'longitude' => TRIP_DEST[1], 'radius_m' => 200],
            ['name' => 'Toko Mitra B', 'latitude' => TRIP_DEST_2[0], 'longitude' => TRIP_DEST_2[1], 'radius_m' => null],
        ]);
    }

    return $trip;
}

function absenAt(string $localTime): void
{
    Carbon::setTestNow(Carbon::parse($localTime, 'Asia/Jakarta')->utc());
}

/** @param array{0: float, 1: float} $point */
function pointPayload(array $point, array $extra = []): array
{
    return ['latitude' => (string) $point[0], 'longitude' => (string) $point[1], ...$extra];
}

test('BR-071 aturan destination: absen di titik tujuan pada tanggal tugas diterima + penanda tugas, bukan anomali', function () {
    $trip = tripFor($this->phone['employee'], FieldTripRule::Destination);

    absenAt('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone, pointPayload(AttendanceWorld::north(TRIP_DEST, 150)))
        ->assertCreated()
        ->assertJsonPath('data.location.name', "Tugas {$trip->number}: Toko Mitra A")
        ->assertJsonPath('data.location.distance_m', 150);

    $log = AttendanceLog::query()->sole();
    expect($log->flags)->toBe(['field_trip_id' => $trip->id, 'field_trip_rule' => 'destination', 'field_trip_place' => 'Toko Mitra A'])
        ->and($log->within_radius)->toBeTrue()
        ->and($log->matched_location_id)->toBeNull()
        ->and($log->isAnomalous())->toBeFalse()
        ->and(AttendanceDay::query()->sole()->has_anomaly)->toBeFalse();
});

test('BR-071 di luar tanggal tugas lokasi tujuan tidak sah → OUT_OF_RADIUS (lokasi biasa)', function () {
    tripFor($this->phone['employee'], FieldTripRule::Destination);

    absenAt('2026-11-18 08:00:00');
    AttendanceWorld::post($this, $this->phone, pointPayload(TRIP_DEST))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ATTENDANCE_OUT_OF_RADIUS')
        ->assertJsonPath('error.details.location_name', 'Gudang Uji');
});

test('BR-071 di luar radius tujuan tetap ditolak; galat memakai area terdekat', function () {
    tripFor($this->phone['employee'], FieldTripRule::Destination);

    absenAt('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone, pointPayload(AttendanceWorld::north(TRIP_DEST, 400)))
        ->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OUT_OF_RADIUS');
});

test('BR-071 aturan any_location: pegawai mode location boleh absen di lokasi Pakis lain selama tugas', function () {
    $trip = tripFor($this->phone['employee'], FieldTripRule::AnyLocation);

    absenAt('2026-11-17 08:00:00');
    AttendanceWorld::post($this, $this->phone, pointPayload(AttendanceWorld::TOKO))
        ->assertCreated()->assertJsonPath('data.location.name', 'Toko Uji');
    expect(AttendanceLog::query()->sole()->flags)->toBe(['field_trip_id' => $trip->id, 'field_trip_rule' => 'any_location']);

    absenAt('2026-11-18 08:00:00');
    AttendanceWorld::post($this, $this->phone, pointPayload(AttendanceWorld::TOKO))
        ->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OUT_OF_RADIUS');
});

test('BR-071 aturan anywhere: di mana saja diterima, lokasi terdekat dicatat, within_radius false tetapi bukan anomali', function () {
    $trip = tripFor($this->phone['employee'], FieldTripRule::Anywhere);

    absenAt('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone, pointPayload(TRIP_DEST))->assertCreated();

    $log = AttendanceLog::query()->sole();
    expect($log->within_radius)->toBeFalse()
        ->and($log->matched_location_id)->not->toBeNull()
        ->and($log->flags)->toBe(['field_trip_id' => $trip->id, 'field_trip_rule' => 'anywhere'])
        ->and(AttendanceDay::query()->sole()->has_anomaly)->toBeFalse();
});

test('BR-071 absen di kantor saat tanggal tugas tetap sah TANPA penanda tugas', function () {
    tripFor($this->phone['employee'], FieldTripRule::AnyLocation);

    absenAt('2026-11-16 07:50:00');
    AttendanceWorld::post($this, $this->phone, pointPayload(AttendanceWorld::GUDANG))->assertCreated();

    expect(AttendanceLog::query()->sole()->flags)->toBeNull();
});

test('BR-071 tugas pending/rejected/cancelled atau milik pegawai lain tidak membuka lokasi tambahan', function (FieldTripStatus $status) {
    tripFor($this->phone['employee'], FieldTripRule::Anywhere, $status);
    tripFor($this->w->phone()['employee'], FieldTripRule::Anywhere);

    absenAt('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone, pointPayload(TRIP_DEST))
        ->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OUT_OF_RADIUS');
})->with([
    'pending' => FieldTripStatus::Pending,
    'rejected' => FieldTripStatus::Rejected,
    'cancelled' => FieldTripStatus::Cancelled,
]);

test('BR-068 BR-071 penanda tugas bukan anomali, tetapi penanda lain (jam HP meleset) tetap anomali', function () {
    tripFor($this->phone['employee'], FieldTripRule::Destination);

    absenAt('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone, pointPayload(TRIP_DEST, ['device_clock_offset_s' => '900']))->assertCreated();

    expect(AttendanceLog::query()->sole()->flags)->toHaveKeys(['clock_skew_s', 'field_trip_id'])
        ->and(AttendanceDay::query()->sole()->has_anomaly)->toBeTrue();
});

test('BR-071 beberapa titik tujuan: absen di titik mana pun (radius per titik, default bila kosong) diterima pada tanggal tugas', function () {
    $trip = tripFor($this->phone['employee'], FieldTripRule::Destination);

    // Titik B tanpa radius → attendance.default_radius_m (100 m) + akurasi 10 m.
    absenAt('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone, pointPayload(AttendanceWorld::north(TRIP_DEST_2, 105)))->assertCreated();
    expect(AttendanceLog::query()->sole()->flags)->toBe(['field_trip_id' => $trip->id, 'field_trip_rule' => 'destination', 'field_trip_place' => 'Toko Mitra B']);

    $other = $this->w->phone();
    $this->w->s->weekdays($other['employee'], '2026-01-01');
    tripFor($other['employee'], FieldTripRule::Destination);
    AttendanceWorld::post($this, $other, pointPayload(AttendanceWorld::north(TRIP_DEST_2, 130)))
        ->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OUT_OF_RADIUS');
});
