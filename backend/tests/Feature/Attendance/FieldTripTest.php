<?php

use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\EmployeeWorld;

uses(AssertsIdor::class);

/*
 * Tugas luar kota di web (T-036A bagian 1): 05 §4.9, 06 §7.2a, 07 §3.4 `field_trips`, BR-071,
 * SEC-033. Hari ini = Senin 16 Nov 2026 (WIB).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 03:00:00');
    $this->world = EmployeeWorld::make($this);
    $this->hrd = $this->world->actor('hrd');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @param  list<Employee>  $employees
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function tripPayload(array $employees, array $override = []): array
{
    return [
        'start_date' => '2026-11-18',
        'end_date' => '2026-11-19',
        'destination' => '  Semarang  ',
        'purpose' => 'Kirim pupuk ke toko mitra',
        'attendance_rule' => 'destination',
        'destinations' => [
            ['name' => 'Toko Mitra A', 'latitude' => '-6.75', 'longitude' => '110.65', 'radius_m' => '300'],
            ['name' => 'Toko Mitra B', 'latitude' => '-6.98', 'longitude' => '110.42', 'radius_m' => ''],
            // Baris kosong dari form dibuang.
            ['name' => '', 'latitude' => '', 'longitude' => '', 'radius_m' => ''],
        ],
        'employee_ids' => array_map(fn (Employee $e) => $e->id, $employees),
        ...$override,
    ];
}

/** @return list<string> nomor tugas di daftar */
function tripNumbers(User $user, string $query = ''): array
{
    app()->forgetScopedInstances();
    $numbers = [];
    test()->actingAs($user)->get('/tugas-luar-kota'.$query)->assertOk()
        ->assertInertia(function (Assert $page) use (&$numbers) {
            $numbers = array_map(fn (array $r) => $r['number'], $page->toArray()['props']['rows']);
        });

    return $numbers;
}

test('BR-071 buat tugas: langsung disetujui, nomor DL/YYYY/MM/SEQ, pegawai banyak, audit field_trip.created', function () {
    $sales = $this->world->employee();
    $driver = $this->world->employee();

    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$sales, $driver]))
        ->assertRedirect(route('admin.field-trips.index'))
        ->assertInertiaFlash('toast.message', 'Tugas DL/2026/11/0001 dibuat dan langsung berlaku.');

    $trip = FieldTrip::query()->sole();
    expect($trip->number)->toBe('DL/2026/11/0001')
        ->and($trip->status)->toBe(FieldTripStatus::Approved)
        ->and($trip->destination)->toBe('Semarang')
        ->and($trip->decided_by)->toBe($this->hrd->id)
        ->and($trip->employeeIds())->toEqualCanonicalizing([$sales->id, $driver->id]);

    expect($trip->destinationValues())->toBe([
        ['name' => 'Toko Mitra A', 'latitude' => -6.75, 'longitude' => 110.65, 'radius_m' => 300],
        ['name' => 'Toko Mitra B', 'latitude' => -6.98, 'longitude' => 110.42, 'radius_m' => null],
    ]);

    $audit = AuditLog::query()->where('event', 'field_trip.created')->sole();
    expect($audit->new_values)->toMatchArray(['number' => 'DL/2026/11/0001', 'status' => 'approved', 'attendance_rule' => 'destination'])
        ->and($audit->new_values['destinations'])->toHaveCount(2);

    // Aturan selain destination: titik tujuan dibuang.
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$sales], ['attendance_rule' => 'anywhere', 'start_date' => '2026-11-25', 'end_date' => '']))->assertRedirect();
    $second = FieldTrip::query()->where('number', 'DL/2026/11/0002')->sole();
    expect($second->destinationValues())->toBe([])
        ->and($second->end_date->toDateString())->toBe('2026-11-25');
});

test('BR-071 validasi: titik tujuan wajib untuk aturan destination, lebih dari batas hari, tanggal lewat, tanpa pegawai', function () {
    $e = $this->world->employee();

    $this->actingAs($this->hrd)->post('/tugas-luar-kota', array_replace(tripPayload([$e]), ['destinations' => []]))
        ->assertSessionHasErrors(['destinations' => 'Tambahkan minimal satu titik tujuan.']);
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', array_replace(tripPayload([$e]), ['destinations' => [['name' => 'Toko', 'latitude' => '', 'longitude' => '', 'radius_m' => '']]]))
        ->assertSessionHasErrors(['destinations.0.latitude' => 'Tempel tautan Google Maps titik tujuan.']);
    $eleven = array_fill(0, 11, ['name' => 'Toko', 'latitude' => '-6.7', 'longitude' => '110.6', 'radius_m' => '']);
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', array_replace(tripPayload([$e]), ['destinations' => $eleven]))
        ->assertSessionHasErrors(['destinations' => 'Paling banyak 10 titik tujuan per tugas.']);
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$e], ['end_date' => '2026-11-25']))
        ->assertSessionHasErrors(['end_date' => 'Satu tugas paling lama 7 hari.']);
    setSetting('attendance.field_trip_max_days', 10);
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$e], ['end_date' => '2026-11-25']))->assertSessionHasNoErrors();
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$e], ['start_date' => '2026-11-10', 'end_date' => '2026-11-12']))
        ->assertSessionHasErrors(['end_date' => 'Tanggal selesai sudah lewat.']);
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([]))
        ->assertSessionHasErrors(['employee_ids' => 'Pilih minimal satu pegawai.']);
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', array_replace(tripPayload([$e]), ['destinations' => [['name' => 'NY', 'latitude' => '40.7', 'longitude' => '-74', 'radius_m' => '']]]))
        ->assertSessionHasErrors(['destinations.0.latitude', 'destinations.0.longitude']);
});

test('BR-071 tugas tumpang tindih untuk pegawai yang sama ditolak; tugas dibatalkan tidak menghalangi', function () {
    $e = $this->world->employee(attributes: ['full_name' => 'Bayu Uji']);
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$e]))->assertSessionHasNoErrors();

    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$e], ['start_date' => '2026-11-19', 'end_date' => '2026-11-20']))
        ->assertSessionHasErrors(['employee_ids' => 'Bayu Uji sudah punya tugas DL/2026/11/0001 pada tanggal yang beririsan.']);
    // Bersebelahan (mulai sehari setelah selesai) boleh.
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$e], ['start_date' => '2026-11-20', 'end_date' => '2026-11-21']))->assertSessionHasNoErrors();

    $first = FieldTrip::query()->where('number', 'DL/2026/11/0001')->sole();
    $this->actingAs($this->hrd)->post("/tugas-luar-kota/{$first->id}/batalkan")->assertRedirect();
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$e], ['start_date' => '2026-11-18', 'end_date' => '2026-11-19']))->assertSessionHasNoErrors();
});

test('06 §7.2a ubah tugas: audit hanya yang berubah; tugas yang sudah selesai atau dibatalkan tidak bisa diubah', function () {
    $a = $this->world->employee();
    $b = $this->world->employee();
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$a]));
    $trip = FieldTrip::query()->sole();

    $this->actingAs($this->hrd)->put("/tugas-luar-kota/{$trip->id}", tripPayload([$a, $b], ['destination' => 'Pati']))
        ->assertRedirect(route('admin.field-trips.index'));
    $audit = AuditLog::query()->where('event', 'field_trip.updated')->sole();
    expect(array_keys($audit->new_values))->toEqualCanonicalizing(['destination', 'employee_ids'])
        ->and($trip->fresh()?->employeeIds())->toEqualCanonicalizing([$a->id, $b->id]);

    // Tugas yang sudah selesai (dibuat langsung; form menolak tanggal lewat).
    $done = FieldTrip::query()->create(['number' => 'DL/LAMA/1', 'start_date' => '2026-11-10', 'end_date' => '2026-11-12', 'destination' => 'Kudus', 'attendance_rule' => 'anywhere', 'status' => 'approved']);
    $done->employees()->sync([$a->id]);
    $this->actingAs($this->hrd)->get("/tugas-luar-kota/{$done->id}/ubah")->assertForbidden();
    $this->actingAs($this->hrd)->put("/tugas-luar-kota/{$done->id}", tripPayload([$a]))->assertForbidden();

    $this->actingAs($this->hrd)->post("/tugas-luar-kota/{$trip->id}/batalkan")
        ->assertInertiaFlash('toast.message', "Tugas {$trip->number} dibatalkan.");
    expect($trip->fresh()?->status)->toBe(FieldTripStatus::Cancelled)
        ->and(AuditLog::query()->where('event', 'field_trip.cancelled')->sole()->new_values)->toBe(['status' => 'cancelled']);
    $this->actingAs($this->hrd)->put("/tugas-luar-kota/{$trip->id}", tripPayload([$a]))->assertForbidden();
});

test('SEC-033 Kepala Lokasi: pegawai lokasi lain → galat umum; tugas lokasi lain → 404; tugas campuran terlihat tetapi tidak bisa diubah', function () {
    $kepala = $this->world->employee($this->world->gudang, attributes: ['full_name' => 'Kepala Gudang']);
    $staf = $this->world->employee($this->world->gudang, $kepala);
    $luar = $this->world->employee($this->world->toko);
    $actor = $this->world->actor('kepala_lokasi', $kepala);

    $this->actingAs($actor)->post('/tugas-luar-kota', tripPayload([$staf, $luar]))
        ->assertSessionHasErrors(['employee_ids' => 'Ada pegawai yang tidak ditemukan, sudah nonaktif, atau di luar wewenang Anda.']);
    $this->actingAs($actor)->post('/tugas-luar-kota', tripPayload([$staf]))->assertSessionHasNoErrors();

    // Dibuat HRD: satu tugas khusus toko, satu campuran gudang + toko.
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$luar], ['start_date' => '2026-11-25', 'end_date' => '2026-11-25']));
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$kepala, $luar], ['start_date' => '2026-11-27', 'end_date' => '2026-11-27']));
    $tokoOnly = FieldTrip::query()->where('number', 'DL/2026/11/0002')->sole();
    $mixed = FieldTrip::query()->where('number', 'DL/2026/11/0003')->sole();

    $this->assertOutOfScope404($actor, 'GET', "/tugas-luar-kota/{$tokoOnly->id}/ubah");
    $this->assertOutOfScope404($actor, 'PUT', "/tugas-luar-kota/{$tokoOnly->id}", tripPayload([$staf]));
    $this->assertOutOfScope404($actor, 'POST', "/tugas-luar-kota/{$tokoOnly->id}/batalkan");

    expect(tripNumbers($actor, '?sort=number'))->toBe(['DL/2026/11/0001', 'DL/2026/11/0003']);
    app()->forgetScopedInstances();
    $this->actingAs($actor)->get("/tugas-luar-kota/{$mixed->id}/ubah")->assertForbidden();
    $this->actingAs($actor)->post("/tugas-luar-kota/{$mixed->id}/batalkan")->assertForbidden();
});

test('06 §4 izin: administrator hanya melihat; pegawai tidak punya menu', function () {
    $admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
    $this->useRealPermissions();

    $this->actingAs($admin)->get('/tugas-luar-kota')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.manage', false));
    $this->actingAs($admin)->get('/tugas-luar-kota/baru')->assertForbidden();
});

test('07 §3.4 field_trips: default bulan ini & disetujui/menunggu, cari nomor/tujuan/nama, filter tanggal/lokasi/aturan, sort', function () {
    $ani = $this->world->employee($this->world->gudang, attributes: ['full_name' => 'Ani Gudang']);
    $budi = $this->world->employee($this->world->toko, attributes: ['full_name' => 'Budi Toko']);
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$ani], ['destination' => 'Semarang', 'start_date' => '2026-11-18', 'end_date' => '2026-11-18']));
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$budi], ['destination' => 'Pati', 'attendance_rule' => 'anywhere', 'start_date' => '2026-11-30', 'end_date' => '2026-12-02']));
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$budi], ['destination' => 'Rembang', 'start_date' => '2026-12-10', 'end_date' => '2026-12-10']));
    $this->actingAs($this->hrd)->post('/tugas-luar-kota/'.FieldTrip::query()->where('destination', 'Semarang')->value('id').'/batalkan');

    // Bulan ini; Semarang dibatalkan (tidak tampil default), Pati beririsan November.
    expect(tripNumbers($this->hrd))->toBe(['DL/2026/11/0002'])
        ->and(tripNumbers($this->hrd, '?filter[status][]=cancelled'))->toBe(['DL/2026/11/0001'])
        ->and(tripNumbers($this->hrd, '?filter[date]='))->toBe(['DL/2026/11/0003', 'DL/2026/11/0002'])
        ->and(tripNumbers($this->hrd, '?filter[date][from]=2026-12-01&filter[date][to]=2026-12-31'))->toBe(['DL/2026/11/0003', 'DL/2026/11/0002'])
        ->and(tripNumbers($this->hrd, '?filter[date]=&q=rembang'))->toBe(['DL/2026/11/0003'])
        ->and(tripNumbers($this->hrd, '?filter[date]=&q=budi'))->toHaveCount(2)
        ->and(tripNumbers($this->hrd, '?filter[date]=&q=DL/2026/11/0003'))->toBe(['DL/2026/11/0003'])
        ->and(tripNumbers($this->hrd, "?filter[date]=&filter[location]={$this->world->gudang->id}&filter[status][]=cancelled"))->toBe(['DL/2026/11/0001'])
        ->and(tripNumbers($this->hrd, '?filter[date]=&filter[rule]=anywhere'))->toBe(['DL/2026/11/0002'])
        ->and(tripNumbers($this->hrd, '?filter[date]=&sort=number'))->toBe(['DL/2026/11/0002', 'DL/2026/11/0003']);

    $this->actingAs($this->hrd)->get('/tugas-luar-kota')
        ->assertInertia(fn (Assert $page) => $page->component('field-trips/index')
            ->where('rows.0.destination', 'Pati')
            ->where('rows.0.days', 3)
            ->where('rows.0.rule.label', 'Di mana saja')
            ->where('rows.0.status.label', 'Disetujui')
            ->where('rows.0.employees.0.name', 'Budi Toko')
            ->where('rows.0.can.update', true));
});

test('07 §4 detail pegawai tab Tugas Luar Kota: tugas terbaru semua status + tombol tambah sesuai izin', function () {
    $e = $this->world->employee(attributes: ['full_name' => 'Citra Sales']);
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$e]));
    $this->actingAs($this->hrd)->post('/tugas-luar-kota', tripPayload([$e], ['start_date' => '2026-11-25', 'end_date' => '2026-11-25', 'destination' => 'Pati']));
    $this->actingAs($this->hrd)->post('/tugas-luar-kota/'.FieldTrip::query()->where('destination', 'Semarang')->value('id').'/batalkan');

    $this->actingAs($this->hrd)->get("/pegawai/{$e->id}?tab=tugas")
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'tugas')
            ->has('fieldTrips.rows', 2)
            ->where('fieldTrips.rows.0.destination', 'Pati')
            ->where('fieldTrips.rows.1.status.value', 'cancelled')
            ->where('fieldTrips.total', 2)
            ->where('fieldTrips.canCreate', true));

    $this->actingAs($this->hrd)->get("/pegawai/{$e->id}")->assertInertia(fn (Assert $page) => $page->where('fieldTrips', null));
});
