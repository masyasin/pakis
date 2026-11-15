<?php

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Support\ShiftUsage;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOrgStructure;

/*
 * Shift (T-033): FR-031, BR-060, 05 §4.9, 07 §3.4 `shifts`, scope 06 §5.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->useRealPermissions();
    $this->hrd = User::factory()->withTwoFactor()->create()->assignRole('hrd');
});

/** @param array<string, mixed> $overrides */
function shiftPayload(array $overrides = []): array
{
    return [
        'code' => 'pagi',
        'name' => 'Shift Pagi',
        'start_time' => '07:30',
        'end_time' => '16:30',
        'crosses_midnight' => false,
        'late_tolerance_min' => 10,
        'checkin_open_min' => 120,
        'checkout_close_min' => 360,
        'color' => '#2f6b3b',
        'is_active' => true,
        ...$overrides,
    ];
}

/** @param array<string, mixed> $attributes */
function makeShift(array $attributes = []): Shift
{
    return Shift::query()->create([
        'code' => 'S'.fake()->unique()->numberBetween(100, 999),
        'name' => 'Shift Uji',
        'start_time' => '08:00:00',
        'end_time' => '16:00:00',
        ...$attributes,
    ]);
}

test('FR-031 tambah shift: kode huruf besar, jam tersimpan, audit shift.created', function () {
    $this->actingAs($this->hrd)->post('/shift', shiftPayload())->assertRedirect(route('admin.shifts.index'));

    $shift = Shift::query()->where('code', 'PAGI')->firstOrFail();
    expect($shift->start_time)->toBe('07:30:00')
        ->and($shift->end_time)->toBe('16:30:00')
        ->and($shift->color)->toBe('#2F6B3B')
        ->and($shift->late_tolerance_min)->toBe(10)
        ->and($shift->created_by)->toBe($this->hrd->id);

    $audit = AuditLog::query()->where('event', 'shift.created')->where('auditable_id', $shift->id)->firstOrFail();
    expect($audit->new_values)->toMatchArray(['code' => 'PAGI', 'start_time' => '07:30:00']);
});

test('BR-060 shift biasa dengan jam pulang ≤ jam masuk ditolak', function () {
    $this->actingAs($this->hrd)->post('/shift', shiftPayload(['start_time' => '16:00', 'end_time' => '08:00']))
        ->assertSessionHasErrors('end_time');
    $this->actingAs($this->hrd)->post('/shift', shiftPayload(['start_time' => '08:00', 'end_time' => '08:00']))
        ->assertSessionHasErrors('end_time');

    expect(Shift::query()->count())->toBe(0);
});

test('BR-060 shift lintas hari 22.00 → 06.00 sah; lintas hari dengan pulang > masuk ditolak', function () {
    $this->actingAs($this->hrd)->post('/shift', shiftPayload(['code' => 'MLM', 'start_time' => '22:00', 'end_time' => '06:00', 'crosses_midnight' => true]))
        ->assertRedirect(route('admin.shifts.index'));
    expect(Shift::query()->where('code', 'MLM')->value('crosses_midnight'))->toBeTrue();

    $this->actingAs($this->hrd)->post('/shift', shiftPayload(['code' => 'X', 'start_time' => '08:00', 'end_time' => '16:00', 'crosses_midnight' => true]))
        ->assertSessionHasErrors('end_time');
});

test('BR-060 CHECK DB menolak shift biasa yang berakhir sebelum mulai (pengaman selain form)', function () {
    expect(fn () => DB::table('shifts')->insert([
        'code' => 'RUSAK', 'name' => 'Rusak', 'start_time' => '16:00:00', 'end_time' => '08:00:00', 'crosses_midnight' => false,
    ]))->toThrow(QueryException::class);
});

test('FR-031 validasi kode unik, format jam, warna, dan batas menit', function () {
    makeShift(['code' => 'PAGI']);

    $this->actingAs($this->hrd)->post('/shift', shiftPayload())->assertSessionHasErrors('code');
    $this->actingAs($this->hrd)->post('/shift', shiftPayload([
        'code' => 'SORE', 'start_time' => '7.30', 'color' => 'hijau', 'late_tolerance_min' => 2000,
    ]))->assertSessionHasErrors(['start_time', 'color', 'late_tolerance_min']);
});

test('FR-031 ubah shift: audit hanya kolom yang berubah; jam sama tidak dianggap berubah', function () {
    $shift = makeShift(['code' => 'PAGI', 'name' => 'Pagi', 'start_time' => '07:30:00', 'end_time' => '16:30:00']);

    $this->actingAs($this->hrd)->put("/shift/{$shift->id}", shiftPayload(['name' => 'Pagi Gudang', 'late_tolerance_min' => 5]))
        ->assertRedirect(route('admin.shifts.index'));

    $audit = AuditLog::query()->where('event', 'shift.updated')->where('auditable_id', $shift->id)->firstOrFail();
    expect(array_keys($audit->new_values))->toEqualCanonicalizing(['name', 'late_tolerance_min'])
        ->and($shift->fresh()->name)->toBe('Pagi Gudang');
});

test('05 §4.9 shift yang sudah dipakai jadwal tidak bisa dihapus, diarahkan untuk dinonaktifkan', function () {
    $shift = makeShift(['name' => 'Shift Pagi']);
    // DDL biasa memutus transaksi RefreshDatabase; TEMPORARY TABLE tidak (pola T-010).
    DB::statement('CREATE TEMPORARY TABLE tmp_patterns (id INT PRIMARY KEY, mon_shift_id BIGINT UNSIGNED)');
    DB::table('tmp_patterns')->insert(['id' => 1, 'mon_shift_id' => $shift->id]);
    app()->instance(ShiftUsage::class, new ShiftUsage([['tmp_patterns', 'mon_shift_id']]));

    $this->actingAs($this->hrd)->from('/shift')->delete("/shift/{$shift->id}")
        ->assertSessionHasErrors(['shift' => 'Shift "Shift Pagi" sudah dipakai jadwal atau absensi sehingga tidak bisa dihapus. Nonaktifkan saja.']);

    expect($shift->fresh())->not->toBeNull();
});

test('05 §4.9 shift yang belum dipakai terhapus (soft delete) dan tercatat audit', function () {
    $shift = makeShift();

    $this->actingAs($this->hrd)->delete("/shift/{$shift->id}")->assertRedirect(route('admin.shifts.index'));

    expect(Shift::query()->find($shift->id))->toBeNull()
        ->and(Shift::withTrashed()->find($shift->id))->not->toBeNull()
        ->and(AuditLog::query()->where('event', 'shift.deleted')->where('auditable_id', $shift->id)->exists())->toBeTrue();
});

test('07 §3.4 shifts: cari kode/nama, filter aktif & lintas hari, sort jam masuk/nama', function () {
    makeShift(['code' => 'PAGI', 'name' => 'Pagi Gudang', 'start_time' => '07:00:00', 'end_time' => '15:00:00']);
    makeShift(['code' => 'SIANG', 'name' => 'Siang Toko', 'start_time' => '12:00:00', 'end_time' => '20:00:00', 'is_active' => false]);
    makeShift(['code' => 'MLM', 'name' => 'Malam Gudang', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'crosses_midnight' => true]);

    $codes = fn (string $uri) => collect($this->actingAs($this->hrd)->get($uri)->assertOk()->viewData('page')['props']['rows'])->pluck('code')->all();

    expect($codes('/shift'))->toBe(['PAGI', 'SIANG', 'MLM'])
        ->and($codes('/shift?q=gudang'))->toBe(['PAGI', 'MLM'])
        ->and($codes('/shift?q=sia'))->toBe(['SIANG'])
        ->and($codes('/shift?filter[is_active]=0'))->toBe(['SIANG'])
        ->and($codes('/shift?filter[crosses_midnight]=1'))->toBe(['MLM'])
        ->and($codes('/shift?sort=name'))->toBe(['MLM', 'PAGI', 'SIANG'])
        ->and($codes('/shift?sort=-start_time'))->toBe(['MLM', 'SIANG', 'PAGI']);
});

test('06 §5 scope shift: Kepala Lokasi tidak melihat shift sebelum ada jadwal pegawainya; HRD melihat semua', function () {
    $org = FakeOrgStructure::bind();
    $org->employee(1)->at(10);
    $shift = makeShift(['code' => 'PAGI']);
    $kepala = User::factory()->create(['employee_id' => employeeRow(1)])->assignRole('kepala_lokasi');

    // TODO(T-035): dengan pola jadwal nyata, shift yang dipakai pegawai lokasinya terlihat.
    $this->actingAs($kepala)->get('/shift')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('shifts/index')->where('rows', [])->where('can.manage', false));
    $this->actingAs($kepala)->getJson('/lookup/shifts')->assertOk()->assertExactJson([]);
    // SEC-033: shift di luar scope = tidak ada.
    $this->actingAs($kepala)->getJson("/shift/{$shift->id}/ubah")->assertNotFound();

    auth()->logout();
    $this->actingAs($this->hrd)->getJson('/lookup/shifts')->assertOk()
        ->assertJsonPath('0.label', 'PAGI · Shift Uji')
        ->assertJsonPath('0.hint', '08:00–16:00');
});

test('05 §4.11 lookup shifts hanya shift aktif dan butuh izin shift/jadwal', function () {
    makeShift(['code' => 'AKTIF']);
    makeShift(['code' => 'MATI', 'is_active' => false]);

    $this->actingAs($this->hrd)->getJson('/lookup/shifts')->assertOk()->assertJsonCount(1)->assertJsonPath('0.value', Shift::query()->where('code', 'AKTIF')->value('id'));

    $admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
    $this->actingAs($admin)->getJson('/lookup/shifts')->assertOk(); // administrator: shifts.view ✅

    $supervisor = User::factory()->create()->assignRole('supervisor');
    $this->actingAs($supervisor)->getJson('/lookup/shifts')->assertOk(); // supervisor: schedules.view 🔸
});

test('04 §4.2 DemoSeeder mengisi shift demo PAGI/GDG/SLS secara idempoten', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(Shift::query()->orderBy('code')->pluck('late_tolerance_min', 'code')->all())
        ->toBe(['GDG' => 5, 'PAGI' => 10, 'SLS' => 15])
        ->and(Shift::query()->where('code', 'SLS')->value('start_time'))->toBe('07:30:00');
});
