<?php

use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Support\Haversine;
use App\Domain\Organization\Models\Position;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AttendanceWorld;

/*
 * Validasi lokasi absen (T-036): BR-041, BR-042, BR-064, SEC-023. Radius lokasi uji 100 m.
 * Toleransi = radius + min(akurasi, 50 m).
 */

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->useRealPermissions();
    $this->w = AttendanceWorld::make($this);
    Carbon::setTestNow(Carbon::parse('2026-11-16 08:00:00', 'Asia/Jakarta')->utc());
});

afterEach(fn () => Carbon::setTestNow());

/** HP pegawai dengan mode absen tertentu (BR-042: dari jabatan). */
function phoneWithMode(AttendanceWorld $w, string $mode): array
{
    $position = Position::query()->firstOrCreate(['code' => 'M-'.$mode], ['name' => 'Jabatan '.$mode, 'department_id' => $w->s->org->department->id, 'attendance_mode' => $mode]);
    $phone = $w->phone();
    $phone['employee']->assignments()->update(['position_id' => $position->id]);
    $w->s->weekdays($phone['employee'], '2026-01-01');

    return $phone;
}

/** @param array{0: float, 1: float} $point */
function atPoint(array $point, string $accuracy = '10'): array
{
    return ['latitude' => (string) $point[0], 'longitude' => (string) $point[1], 'accuracy_m' => $accuracy];
}

test('BR-064 Haversine: 0,001° lintang ≈ 111 m; titik sama = 0', function () {
    expect(Haversine::meters(-6.5, 110.5, -6.5, 110.5))->toBe(0.0)
        ->and(round(Haversine::meters(-6.5, 110.5, -6.501, 110.5)))->toBe(111.0);
});

test('BR-064 mode location: dalam toleransi diterima, di luar → OUT_OF_RADIUS dengan details', function () {
    $phone = phoneWithMode($this->w, 'location');

    // 105 m dengan akurasi 10 m: toleransi 110 m → diterima.
    AttendanceWorld::post($this, $phone, atPoint(AttendanceWorld::north(AttendanceWorld::GUDANG, 105)))
        ->assertCreated()->assertJsonPath('data.location.name', 'Gudang Uji')->assertJsonPath('data.location.distance_m', 105);

    $other = phoneWithMode($this->w, 'location');
    AttendanceWorld::post($this, $other, atPoint(AttendanceWorld::north(AttendanceWorld::GUDANG, 115)))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ATTENDANCE_OUT_OF_RADIUS')
        ->assertJsonPath('error.details.distance_m', 115)
        ->assertJsonPath('error.details.radius_m', 100)
        ->assertJsonPath('error.details.location_name', 'Gudang Uji');
});

test('BR-064 toleransi akurasi dibatasi 50 m: akurasi 90 m hanya menambah 50 m', function () {
    $phone = phoneWithMode($this->w, 'location');
    AttendanceWorld::post($this, $phone, atPoint(AttendanceWorld::north(AttendanceWorld::GUDANG, 149), '90'))->assertCreated();

    $other = phoneWithMode($this->w, 'location');
    AttendanceWorld::post($this, $other, atPoint(AttendanceWorld::north(AttendanceWorld::GUDANG, 152), '90'))
        ->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OUT_OF_RADIUS');
});

test('BR-064 SEC-023 akurasi 250 m → LOW_ACCURACY (di atas attendance.max_accuracy_m)', function () {
    $phone = phoneWithMode($this->w, 'anywhere');

    AttendanceWorld::post($this, $phone, atPoint(AttendanceWorld::GUDANG, '250'))
        ->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_LOW_ACCURACY');
    expect(AttendanceLog::query()->count())->toBe(0);
});

test('BR-064 mode any_location: lokasi aktif ber-koordinat mana pun yang memuat titik; di luar semua → OUT_OF_RADIUS lokasi terdekat', function () {
    $phone = phoneWithMode($this->w, 'any_location');

    // Pegawai gudang absen di toko → sah, tercatat lokasi toko.
    AttendanceWorld::post($this, $phone, atPoint(AttendanceWorld::north(AttendanceWorld::TOKO, 30)))
        ->assertCreated()->assertJsonPath('data.location.name', 'Toko Uji');
    expect(AttendanceLog::query()->sole()->matched_location_id)->toBe($this->w->s->org->toko->id);

    // Lokasi nonaktif tidak dihitung.
    $this->w->s->org->toko->forceFill(['is_active' => false])->save();
    $other = phoneWithMode($this->w, 'any_location');
    AttendanceWorld::post($this, $other, atPoint(AttendanceWorld::north(AttendanceWorld::TOKO, 30)))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ATTENDANCE_OUT_OF_RADIUS')
        ->assertJsonPath('error.details.location_name', 'Gudang Uji');
});

test('BR-064 mode anywhere: tidak ditolak di luar radius; lokasi terdekat & jarak dicatat, within_radius false → anomali', function () {
    $phone = phoneWithMode($this->w, 'anywhere');

    AttendanceWorld::post($this, $phone, atPoint(AttendanceWorld::north(AttendanceWorld::GUDANG, 2000)))
        ->assertCreated()
        ->assertJsonPath('data.location.name', 'Gudang Uji')
        ->assertJsonPath('data.location.distance_m', 2000);

    $log = AttendanceLog::query()->sole();
    expect($log->within_radius)->toBeFalse()
        ->and($log->matched_location_id)->toBe($this->w->s->org->gudang->id)
        ->and(AttendanceDay::query()->where('employee_id', $phone['employee']->id)->sole()->has_anomaly)->toBeTrue();

    $inside = phoneWithMode($this->w, 'anywhere');
    AttendanceWorld::post($this, $inside, atPoint(AttendanceWorld::GUDANG))->assertCreated();
    expect(AttendanceLog::query()->latest('id')->first()?->within_radius)->toBeTrue();
});

test('BR-041 mode location di lokasi tanpa koordinat → OUT_OF_RADIUS "Koordinat lokasi belum diatur"', function () {
    $phone = phoneWithMode($this->w, 'location');
    $this->w->s->org->gudang->forceFill(['latitude' => null, 'longitude' => null])->save();

    AttendanceWorld::post($this, $phone, atPoint(AttendanceWorld::GUDANG))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ATTENDANCE_OUT_OF_RADIUS')
        ->assertJsonPath('error.message', 'Koordinat lokasi belum diatur. Hubungi HRD.');
});

test('BR-042 mode dari penugasan menimpa mode jabatan', function () {
    $phone = phoneWithMode($this->w, 'location');
    $phone['employee']->assignments()->update(['attendance_mode' => 'anywhere']);

    AttendanceWorld::post($this, $phone, atPoint(AttendanceWorld::north(AttendanceWorld::GUDANG, 2000)))->assertCreated();
});
