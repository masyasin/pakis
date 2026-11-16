<?php

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CorrectionWorld;

/*
 * T-044 — Dasbor web (FR-040, 07 §4 "Dasbor"): angka dalam scope pengguna, kartu "perlu tindakan"
 * menaut ke daftar dengan filter yang sama (URL state). Hari uji: Senin 16 Nov 2026 10:00 WIB,
 * shift PAGI 08:00–16:00. Data fiktif.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 03:00:00');
    Storage::fake('local');
    Notification::fake();
    $this->c = CorrectionWorld::make($this);
    $this->org = $this->c->w->s->org;
});

afterEach(fn () => Carbon::setTestNow());

/** Baris presensi seperti hasil RecordAttendance (snapshot lokasi = penugasan saat itu). */
function dbDay(Employee $employee, string $date, ?int $lateMinutes, int $locationId): AttendanceDay
{
    $checkIn = $lateMinutes === null ? null : CarbonImmutable::parse("{$date} 08:00", 'Asia/Jakarta')->addMinutes($lateMinutes)->utc();

    return AttendanceDay::query()->create([
        'employee_id' => $employee->id,
        'work_date' => $date,
        'location_id' => $locationId,
        'check_in_at' => $checkIn,
        'late_minutes' => max(0, (int) $lateMinutes),
        'status' => $lateMinutes === null ? AttendanceStatus::Absent : ($lateMinutes > 0 ? AttendanceStatus::Late : AttendanceStatus::Present),
    ]);
}

function dbAs(object $test, User $user): object
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    app()->forgetScopedInstances();

    return $test->actingAs($user, 'web');
}

test('FR-040 Dashboard: angka hari ini & 7 hari mengikuti scope kepala_lokasi', function () {
    $gudang = $this->org->gudang->id;
    $toko = $this->org->toko->id;
    // Gudang: staf hadir tepat waktu, satu pegawai belum presensi, satu libur (override off).
    dbDay($this->c->staff['employee'], '2026-11-16', 0, $gudang);
    $this->c->member();
    $offToday = $this->c->member();
    $this->c->w->s->override($offToday['employee'], '2026-11-16', null, 'Tukar libur');
    // Toko: satu pegawai telat 25 menit.
    $tokoStaff = $this->c->member(location: $this->org->toko);
    dbDay($tokoStaff['employee'], '2026-11-16', 25, $toko);
    // Minggu lalu (Jumat): staf gudang telat.
    dbDay($this->c->staff['employee'], '2026-11-13', 10, $gudang);

    $hrd = $this->org->actor('hrd');
    dbAs($this, $hrd)->get('/dasbor')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('attendance.date', '2026-11-16')
        ->where('attendance.present', 2)
        ->where('attendance.late', 1)
        ->where('attendance.not_yet', 1)
        ->where('attendance.off', 1)
        ->has('attendance.week', 7)
        ->where('attendance.week.0.date', '2026-11-10')
        ->where('attendance.week.3.present', 1)
        ->where('attendance.week.3.late', 1)
        ->where('attendance.week.6.present', 2));

    $kepala = $this->org->actor('kepala_lokasi', $this->org->employee($this->org->gudang));
    dbAs($this, $kepala)->get('/dasbor')->assertInertia(fn (Assert $page) => $page
        ->where('attendance.present', 1)
        ->where('attendance.late', 0)
        ->where('attendance.not_yet', 1)
        ->where('attendance.off', 1)
        ->where('attendance.week.6.present', 1));

    $this->useRealPermissions();
    // Punya menu web lain tetapi tanpa dashboard.view → 403 (keputusan Mas 16 Nov 2026).
    $role = Role::query()->create(['name' => 'lihat_pegawai', 'label' => 'Lihat pegawai', 'guard_name' => 'web', 'data_scope' => DataScope::All]);
    $role->givePermissionTo('employees.view');
    dbAs($this, User::factory()->withTwoFactor()->create()->assignRole('lihat_pegawai'))->get('/dasbor')->assertForbidden();
});

test('FR-040 Dashboard: grafik tren, per lokasi & jam masuk ber-scope, hadir = ada presensi masuk (BR-072)', function () {
    $gudang = $this->org->gudang->id;
    $toko = $this->org->toko->id;
    dbDay($this->c->staff['employee'], '2026-11-16', 0, $gudang);          // 08:00 tepat waktu
    $tokoStaff = $this->c->member(location: $this->org->toko);
    dbDay($tokoStaff['employee'], '2026-11-16', 25, $toko);                // 08:25 telat
    dbDay($this->c->staff['employee'], '2026-11-13', 10, $gudang);         // Jumat telat
    dbDay($this->c->member()['employee'], '2026-11-12', null, $gudang);    // Kamis tidak hadir
    // Di luar rentang 7 hari, masuk rentang 30 hari.
    dbDay($this->c->staff['employee'], '2026-10-20', 0, $gudang);

    $hrd = $this->org->actor('hrd');
    dbAs($this, $hrd)->get('/dasbor')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('charts.from', '2026-11-10')
        ->where('charts.to', '2026-11-16')
        ->where('charts.max_days', 92)
        ->has('charts.trend', 7)
        ->where('charts.trend.0.date', '2026-11-10')
        ->where('charts.trend.2', ['date' => '2026-11-12', 'on_time' => 0, 'late' => 0, 'absent' => 1])
        ->where('charts.trend.3', ['date' => '2026-11-13', 'on_time' => 0, 'late' => 1, 'absent' => 0])
        ->where('charts.trend.6', ['date' => '2026-11-16', 'on_time' => 1, 'late' => 1, 'absent' => 0])
        ->has('charts.locations', 2)
        // Jam lokal WIB per 15 menit dalam rentang: hari ini 08:00 & 08:25, Jumat 08:10.
        ->where('charts.check_ins', [['time' => '08:00', 'count' => 2], ['time' => '08:15', 'count' => 1]])
        ->where('refresh_seconds', 60));

    $locations = collect(dbAs($this, $hrd)->get('/dasbor')->viewData('page')['props']['charts']['locations'])->keyBy('name');
    expect($locations[$this->org->toko->name])->toBe(['name' => $this->org->toko->name, 'present' => 1, 'late' => 1])
        ->and($locations[$this->org->gudang->name]['late'])->toBe(1);  // Jumat telat 10 menit

    // Rentang dari URL berlaku untuk ketiga grafik.
    dbAs($this, $hrd)->get('/dasbor?dari=2026-10-18&sampai=2026-11-16')->assertInertia(fn (Assert $page) => $page
        ->has('charts.trend', 30)
        ->where('charts.trend.0.date', '2026-10-18')
        ->where('charts.trend.2.on_time', 1));
    dbAs($this, $hrd)->get('/dasbor?dari=2026-11-12&sampai=2026-11-13')->assertInertia(fn (Assert $page) => $page
        ->has('charts.trend', 2)
        ->where('charts.locations', [])  // hanya gudang yang hadir di rentang itu
        ->where('charts.check_ins', [['time' => '08:00', 'count' => 1]]));  // Jumat 08:10 WIB

    // Nilai dirapikan, bukan diteruskan mentah: rusak → bawaan, terbalik → ditukar, masa depan →
    // dipotong ke hari ini, terlalu panjang → dipotong ke ui.dashboard_max_range_days.
    $range = fn (string $query) => dbAs($this, $hrd)->get('/dasbor?'.$query)->viewData('page')['props']['charts'];
    expect($range('dari=kemarin&sampai=2026-02-31'))->toMatchArray(['from' => '2026-11-10', 'to' => '2026-11-16'])
        ->and($range('dari=2026-11-16&sampai=2026-11-12'))->toMatchArray(['from' => '2026-11-12', 'to' => '2026-11-16'])
        ->and($range('dari=2026-11-14&sampai=2027-01-01'))->toMatchArray(['from' => '2026-11-14', 'to' => '2026-11-16'])
        ->and($range('dari=2025-01-01&sampai=2026-11-16'))->toMatchArray(['from' => '2026-08-17', 'to' => '2026-11-16']);
    setSetting('ui.dashboard_max_range_days', 7);
    expect($range('dari=2026-10-01&sampai=2026-10-31'))->toMatchArray(['from' => '2026-10-25', 'to' => '2026-10-31']);

    // Kepala lokasi gudang: data toko tidak ikut; satu lokasi saja → grafik per lokasi kosong.
    $kepala = $this->org->actor('kepala_lokasi', $this->org->employee($this->org->gudang));
    dbAs($this, $kepala)->get('/dasbor')->assertInertia(fn (Assert $page) => $page
        ->where('charts.trend.6', ['date' => '2026-11-16', 'on_time' => 1, 'late' => 0, 'absent' => 0])
        ->where('charts.trend.3.late', 1)
        ->where('charts.locations', [])
        ->where('charts.check_ins', [['time' => '08:00', 'count' => 2]]));

    // Tanpa attendance.view: data grafik tidak dikirim sama sekali.
    $this->useRealPermissions();
    $role = Role::query()->create(['name' => 'dasbor_saja', 'label' => 'Dasbor saja', 'guard_name' => 'web', 'data_scope' => DataScope::All]);
    $role->givePermissionTo('dashboard.view');
    dbAs($this, User::factory()->withTwoFactor()->create()->assignRole('dasbor_saja'))->get('/dasbor')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('charts', null)->where('attendance', null));
});

test('FR-040 Dashboard: kartu perlu tindakan = jumlah daftar tujuan dengan filter di URL, ber-scope', function () {
    // Koreksi menunggu: satu di gudang, satu di toko.
    CorrectionWorld::submit($this, $this->c->staff)->assertCreated();
    $tokoStaff = $this->c->member(location: $this->org->toko);
    CorrectionWorld::submit($this, $tokoStaff)->assertCreated();
    // Perangkat menunggu milik pegawai gudang.
    $pendingOwner = User::factory()->create(['employee_id' => $this->org->employee($this->org->gudang)->id])->assignRole('pegawai');
    Device::query()->create(['user_id' => $pendingOwner->id, 'device_uid' => hash('sha256', 'dasbor-hp'), 'status' => DeviceStatus::Pending, 'model' => 'Uji Android']);

    $kepala = $this->org->actor('kepala_lokasi', $this->org->employee($this->org->gudang));
    $page = dbAs($this, $kepala)->get('/dasbor')->assertOk()->viewData('page')['props'];
    $pending = collect($page['pending'])->keyBy('key');

    expect($pending->keys()->all())->toContain('corrections', 'devices', 'no_account')
        ->and($pending['corrections']['count'])->toBe(1)
        ->and($pending['devices']['count'])->toBe(1);

    // Setiap tautan membuka daftar dengan jumlah baris yang sama.
    foreach ($pending as $item) {
        $path = parse_url($item['href'], PHP_URL_PATH).'?'.parse_url($item['href'], PHP_URL_QUERY);
        expect($path)->toContain('filter');
        dbAs($this, $kepala)->get($path)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('listing.pagination.total', $item['count']));
    }

    expect(urldecode($pending['corrections']['href']))->toContain('/koreksi-absen?filter[status][0]=pending')
        ->and(urldecode($pending['devices']['href']))->toContain('/perangkat?filter[status][0]=pending')
        ->and(urldecode($pending['no_account']['href']))->toContain('filter[has_account]=0');

    // HRD (scope semua) melihat dua koreksi.
    $hrd = $this->org->actor('hrd');
    $all = collect(dbAs($this, $hrd)->get('/dasbor')->viewData('page')['props']['pending'])->keyBy('key');
    expect($all['corrections']['count'])->toBe(2);
});

test('FR-040 Dashboard: sebaran jam masuk memangkas pencilan ≤ 2% dan menyebut jumlahnya', function () {
    $gudang = $this->org->gudang->id;
    $employee = $this->c->staff['employee'];
    // 50 hari masuk 08:00 WIB + satu presensi 23:05 WIB (pencilan, 1 dari 51 ≤ 2%).
    for ($d = 1; $d <= 50; $d++) {
        dbDay($employee, CarbonImmutable::parse('2026-11-16')->subDays($d)->toDateString(), 0, $gudang);
    }
    dbDay($employee, '2026-11-16', 905, $gudang);

    $hrd = $this->org->actor('hrd');
    $charts = dbAs($this, $hrd)->get('/dasbor?dari=2026-09-01&sampai=2026-11-16')->viewData('page')['props']['charts'];

    expect($charts['check_ins'])->toBe([['time' => '08:00', 'count' => 50]])
        ->and($charts['check_ins_outside'])->toBe(1);
});
