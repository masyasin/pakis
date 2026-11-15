<?php

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organization\Models\Location;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOrgStructure;

/*
 * Hari libur (T-034): FR-033, BR-062, 05 §4.9, 07 §3.4 `holidays`.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class]);
    $this->useRealPermissions();
    $this->hrd = User::factory()->withTwoFactor()->create()->assignRole('hrd');
    $this->year = (int) now(setting('general.timezone'))->format('Y');
});

/** @param array<string, mixed> $attributes */
function holidayLocation(string $code, array $attributes = []): Location
{
    return Location::query()->create([
        'code' => $code,
        'name' => 'Lokasi '.$code,
        'location_type_id' => ReferenceItem::query()->forGroup(ReferenceGroup::LocationType)->value('id'),
        'is_active' => true,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<int>  $locationIds
 */
function makeHoliday(string $date, string $name, array $attributes = [], array $locationIds = []): Holiday
{
    // Libur satu hari kecuali `end_date` diberikan.
    $holiday = Holiday::query()->create(['start_date' => $date, 'end_date' => $date, 'name' => $name, ...$attributes]);
    $holiday->locations()->sync($locationIds);

    return $holiday;
}

/** @param array<string, mixed> $overrides */
function holidayPayload(array $overrides = []): array
{
    return [
        'start_date' => test()->year.'-08-17',
        'end_date' => '',
        'name' => 'Hari Kemerdekaan',
        'description' => '',
        'is_national' => true,
        'applies_to_all' => true,
        ...$overrides,
    ];
}

test('FR-033 tambah libur nasional untuk semua lokasi: tersimpan dan tercatat audit holiday.created', function () {
    $this->actingAs($this->hrd)->post('/hari-libur', holidayPayload())
        ->assertRedirect(route('admin.holidays.index', ['filter' => ['year' => (string) $this->year]]));

    $holiday = Holiday::query()->sole();
    expect($holiday->start_date->toDateString())->toBe($this->year.'-08-17')
        ->and($holiday->end_date->toDateString())->toBe($this->year.'-08-17')
        ->and($holiday->description)->toBeNull()
        ->and($holiday->applies_to_all)->toBeTrue()
        ->and($holiday->locations)->toHaveCount(0)
        ->and($holiday->created_by)->toBe($this->hrd->id);

    $audit = AuditLog::query()->where('event', 'holiday.created')->where('auditable_id', $holiday->id)->sole();
    expect($audit->new_values)->toMatchArray(['start_date' => $this->year.'-08-17', 'end_date' => $this->year.'-08-17', 'applies_to_all' => true, 'location_ids' => []]);
});

test('FR-033 libur khusus lokasi menyimpan lokasi terpilih; lokasi diabaikan bila berlaku semua', function () {
    $a = holidayLocation('GD-A');
    $b = holidayLocation('GD-B');

    $this->actingAs($this->hrd)->post('/hari-libur', holidayPayload([
        'name' => 'Haul Sunan Kudus', 'is_national' => false, 'applies_to_all' => false, 'location_ids' => [$b->id, $a->id],
    ]))->assertSessionHasNoErrors();

    $holiday = Holiday::query()->where('name', 'Haul Sunan Kudus')->sole();
    expect($holiday->locationIds())->toBe([$a->id, $b->id])
        ->and($holiday->is_national)->toBeFalse();

    $this->actingAs($this->hrd)->post('/hari-libur', holidayPayload(['location_ids' => [$a->id]]))->assertSessionHasNoErrors();
    expect(Holiday::query()->where('name', 'Hari Kemerdekaan')->sole()->locationIds())->toBe([]);
});

test('FR-033 validasi: nama kembar di tanggal sama, libur lokasi tanpa lokasi, lokasi nonaktif', function () {
    makeHoliday($this->year.'-08-17', 'Hari Kemerdekaan');
    $inactive = holidayLocation('MATI', ['is_active' => false]);

    $this->actingAs($this->hrd)->post('/hari-libur', holidayPayload())->assertSessionHasErrors('name');
    // Nama sama di tanggal lain sah (mis. cuti bersama berulang).
    $this->actingAs($this->hrd)->post('/hari-libur', holidayPayload(['start_date' => $this->year.'-08-18']))->assertSessionHasNoErrors();

    $this->actingAs($this->hrd)->post('/hari-libur', holidayPayload(['name' => 'Libur A', 'applies_to_all' => false]))
        ->assertSessionHasErrors(['location_ids' => 'Pilih minimal satu lokasi, atau pilih "Semua lokasi".']);
    $this->actingAs($this->hrd)->post('/hari-libur', holidayPayload(['name' => 'Libur B', 'applies_to_all' => false, 'location_ids' => [$inactive->id]]))
        ->assertSessionHasErrors(['location_ids' => 'Ada lokasi yang tidak ditemukan atau sudah nonaktif.']);
    $this->actingAs($this->hrd)->post('/hari-libur', holidayPayload(['name' => 'Libur C', 'start_date' => '17-08-2026']))
        ->assertSessionHasErrors('start_date');

    expect(Holiday::query()->count())->toBe(2);
});

test('FR-033 ubah libur semua lokasi → lokasi tertentu: pivot sinkron, audit hanya yang berubah', function () {
    $a = holidayLocation('GD-A');
    $holiday = makeHoliday($this->year.'-12-31', 'Libur akhir tahun', ['is_national' => false]);

    $this->actingAs($this->hrd)->put("/hari-libur/{$holiday->id}", holidayPayload([
        'start_date' => $this->year.'-12-31', 'name' => 'Libur akhir tahun', 'is_national' => false, 'applies_to_all' => false, 'location_ids' => [$a->id],
    ]))->assertSessionHasNoErrors();

    expect($holiday->fresh()->applies_to_all)->toBeFalse()->and($holiday->locationIds())->toBe([$a->id]);
    $audit = AuditLog::query()->where('event', 'holiday.updated')->where('auditable_id', $holiday->id)->sole();
    expect($audit->old_values)->toBe(['applies_to_all' => true, 'location_ids' => []])
        ->and($audit->new_values)->toBe(['applies_to_all' => false, 'location_ids' => [$a->id]]);

    // Simpan ulang tanpa perubahan (urutan lokasi lain pun) = tidak ada audit baru.
    $this->actingAs($this->hrd)->put("/hari-libur/{$holiday->id}", holidayPayload([
        'start_date' => $this->year.'-12-31', 'name' => 'Libur akhir tahun', 'is_national' => false, 'applies_to_all' => false, 'location_ids' => [$a->id],
    ]))->assertSessionHasNoErrors();
    expect(AuditLog::query()->where('event', 'holiday.updated')->count())->toBe(1);
});

test('FR-033 lokasi yang sudah terpasang boleh dipertahankan walau kemudian dinonaktifkan', function () {
    $a = holidayLocation('GD-A');
    $holiday = makeHoliday($this->year.'-05-01', 'Libur lokal', ['applies_to_all' => false], [$a->id]);
    $a->update(['is_active' => false]);

    $this->actingAs($this->hrd)->put("/hari-libur/{$holiday->id}", holidayPayload([
        'start_date' => $this->year.'-05-01', 'name' => 'Libur lokal (ubah)', 'applies_to_all' => false, 'location_ids' => [$a->id],
    ]))->assertSessionHasNoErrors();

    expect($holiday->fresh()->name)->toBe('Libur lokal (ubah)');
});

test('05 §4.9 hapus libur: hard delete, pivot ikut terhapus, audit holiday.deleted', function () {
    $a = holidayLocation('GD-A');
    $holiday = makeHoliday($this->year.'-05-01', 'Libur lokal', ['applies_to_all' => false], [$a->id]);

    $this->actingAs($this->hrd)->delete("/hari-libur/{$holiday->id}")->assertRedirect();

    expect(Holiday::query()->find($holiday->id))->toBeNull()
        ->and(DB::table('holiday_locations')->where('holiday_id', $holiday->id)->exists())->toBeFalse();
    $audit = AuditLog::query()->where('event', 'holiday.deleted')->where('auditable_id', $holiday->id)->sole();
    expect($audit->old_values)->toMatchArray(['name' => 'Libur lokal', 'location_ids' => [$a->id]]);
});

test('FR-033 libur beberapa hari (Idul Fitri + cuti bersama): rentang inklusif, keterangan tersimpan', function () {
    $y = $this->year;

    $this->actingAs($this->hrd)->post('/hari-libur', holidayPayload([
        'start_date' => "{$y}-03-30", 'end_date' => "{$y}-04-03", 'name' => 'Idul Fitri & cuti bersama',
        'description' => '  Idul Fitri 1 & 2 Syawal + cuti bersama.  ',
    ]))->assertSessionHasNoErrors();

    $holiday = Holiday::query()->sole();
    expect($holiday->dayCount())->toBe(5)
        ->and($holiday->description)->toBe('Idul Fitri 1 & 2 Syawal + cuti bersama.');

    // BR-062: setiap tanggal di dalam rentang adalah libur; sehari sebelum/sesudah bukan.
    $on = fn (string $d) => Holiday::query()->appliesTo($d, null)->exists();
    expect($on("{$y}-03-29"))->toBeFalse()
        ->and($on("{$y}-03-30"))->toBeTrue()
        ->and($on("{$y}-04-01"))->toBeTrue()
        ->and($on("{$y}-04-03"))->toBeTrue()
        ->and($on("{$y}-04-04"))->toBeFalse();
});

test('FR-033 rentang tidak sah: selesai sebelum mulai atau lebih dari 31 hari; CHECK DB sebagai pengaman', function () {
    $y = $this->year;

    $this->actingAs($this->hrd)->post('/hari-libur', holidayPayload(['start_date' => "{$y}-04-03", 'end_date' => "{$y}-03-30"]))
        ->assertSessionHasErrors(['end_date' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);
    $this->actingAs($this->hrd)->post('/hari-libur', holidayPayload(['start_date' => "{$y}-01-01", 'end_date' => "{$y}-02-01"]))
        ->assertSessionHasErrors('end_date');
    $this->actingAs($this->hrd)->post('/hari-libur', holidayPayload(['start_date' => "{$y}-01-01", 'end_date' => "{$y}-01-31"]))
        ->assertSessionHasNoErrors();

    expect(fn () => DB::table('holidays')->insert(['start_date' => "{$y}-05-02", 'end_date' => "{$y}-05-01", 'name' => 'Rusak']))
        ->toThrow(QueryException::class);
});

test('07 §3.4 libur yang melintasi pergantian tahun muncul di filter kedua tahun', function () {
    $y = $this->year;
    makeHoliday("{$y}-12-31", 'Tahun Baru panjang', ['end_date' => ($y + 1).'-01-02']);

    $names = fn (string $uri) => collect($this->actingAs($this->hrd)->get($uri)->assertOk()->viewData('page')['props']['rows'])->pluck('name')->all();

    expect($names('/hari-libur'))->toBe(['Tahun Baru panjang'])
        ->and($names('/hari-libur?filter[year]='.($y + 1)))->toBe(['Tahun Baru panjang'])
        ->and($names('/hari-libur?filter[year]='.($y - 1)))->toBe([]);
});

test('BR-062 libur per lokasi hanya berlaku untuk lokasi terpilih; libur semua lokasi berlaku di mana saja', function () {
    $a = holidayLocation('GD-A');
    $b = holidayLocation('GD-B');
    $date = $this->year.'-03-10';
    makeHoliday($date, 'Libur khusus A', ['applies_to_all' => false], [$a->id]);
    makeHoliday($this->year.'-03-11', 'Libur nasional', ['applies_to_all' => true]);

    $names = fn (string $d, ?int $loc) => Holiday::query()->appliesTo($d, $loc)->pluck('name')->all();

    expect($names($date, $a->id))->toBe(['Libur khusus A'])
        ->and($names($date, $b->id))->toBe([])
        ->and($names($date, null))->toBe([])
        ->and($names($this->year.'-03-11', $a->id))->toBe(['Libur nasional'])
        ->and($names($this->year.'-03-11', $b->id))->toBe(['Libur nasional'])
        ->and($names($this->year.'-03-11', null))->toBe(['Libur nasional'])
        ->and($names($this->year.'-03-12', $a->id))->toBe([]);
});

test('07 §3.4 holidays: default tahun ini, cari nama, filter tahun/nasional/lokasi, sort tanggal/nama', function () {
    $a = holidayLocation('GD-A');
    $b = holidayLocation('GD-B');
    $y = $this->year;
    makeHoliday("{$y}-08-17", 'Kemerdekaan');
    makeHoliday("{$y}-01-01", 'Tahun Baru');
    makeHoliday("{$y}-04-20", 'Haul Lokal', ['is_national' => false, 'applies_to_all' => false, 'description' => 'Haul Sunan Kudus'], [$a->id]);
    makeHoliday(($y + 1).'-01-01', 'Tahun Baru');

    $names = fn (string $uri) => collect($this->actingAs($this->hrd)->get($uri)->assertOk()->viewData('page')['props']['rows'])->pluck('name')->all();

    expect($names('/hari-libur'))->toBe(['Tahun Baru', 'Haul Lokal', 'Kemerdekaan'])
        ->and($names('/hari-libur?filter[year]='.($y + 1)))->toBe(['Tahun Baru'])
        ->and($names('/hari-libur?q=baru'))->toBe(['Tahun Baru'])
        ->and($names('/hari-libur?q=sunan'))->toBe(['Haul Lokal'])
        ->and($names('/hari-libur?filter[is_national]=0'))->toBe(['Haul Lokal'])
        ->and($names("/hari-libur?filter[location]={$a->id}"))->toBe(['Tahun Baru', 'Haul Lokal', 'Kemerdekaan'])
        ->and($names("/hari-libur?filter[location]={$b->id}"))->toBe(['Tahun Baru', 'Kemerdekaan'])
        ->and($names('/hari-libur?sort=-date'))->toBe(['Kemerdekaan', 'Haul Lokal', 'Tahun Baru'])
        ->and($names('/hari-libur?sort=name'))->toBe(['Haul Lokal', 'Kemerdekaan', 'Tahun Baru']);

    $this->actingAs($this->hrd)->get('/hari-libur')->assertInertia(fn (Assert $page) => $page
        ->component('holidays/index')
        ->where('rows.1.locations', ['Lokasi GD-A'])
        ->where('rows.1.applies_to_all', false)
        ->where('can.manage', true));
});

test('07 §3.4 filter tahun menawarkan tahun data + tahun lalu s.d. tahun depan', function () {
    makeHoliday(($this->year - 3).'-01-01', 'Lama');

    $this->actingAs($this->hrd)->get('/hari-libur')->assertInertia(function (Assert $page) {
        $year = collect($page->toArray()['props']['listing']['spec']['filters'])->firstWhere('key', 'year');
        expect(array_column($year['options'], 'value'))->toBe(array_map('strval', range($this->year + 1, $this->year - 3)))
            ->and($year['default'])->toBe((string) $this->year);
    });
});

test('06 §5 filter lokasi hanya menerima lokasi dalam scope; Kepala Lokasi tetap melihat semua libur', function () {
    FakeOrgStructure::bind();
    $b = holidayLocation('GD-B');
    // Kepala Lokasi tanpa pegawai tertaut = scope lokasi kosong (fail-closed), tanpa bergantung
    // pada tabel pegawai (T-024).
    $kepala = User::factory()->create(['employee_id' => null])->assignRole('kepala_lokasi');
    makeHoliday($this->year.'-04-20', 'Libur B', ['applies_to_all' => false], [$b->id]);

    // holidays.view ✅ (bukan 🔸): daftar tidak ber-scope, tanpa tombol kelola.
    $this->actingAs($kepala)->get('/hari-libur')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.name', 'Libur B')->where('can.manage', false));
    // Lokasi di luar scope ditolak filter (diabaikan + galat), bukan bocor sebagai daftar terfilter.
    $this->actingAs($kepala)->get("/hari-libur?filter[location]={$b->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('listing.state.invalid_filters.location'));
    $this->actingAs($kepala)->get('/hari-libur/baru')->assertForbidden();

    auth()->logout();
    $this->actingAs($this->hrd)->get("/hari-libur?filter[location]={$b->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->missing('listing.state.invalid_filters.location')->where('rows.0.name', 'Libur B'));
});

test('BR-041 lokasi yang dipakai hari libur tidak bisa dihapus', function () {
    $admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
    $a = holidayLocation('GD-A', ['name' => 'Gudang A']);
    makeHoliday($this->year.'-04-20', 'Libur A', ['applies_to_all' => false], [$a->id]);

    $this->actingAs($admin)->from('/lokasi')->delete("/lokasi/{$a->id}")->assertSessionHasErrors('location');

    expect(Location::query()->find($a->id))->not->toBeNull();
});

test('FR-033 ekspor hari libur mengunduh berkas', function () {
    makeHoliday($this->year.'-08-17', 'Kemerdekaan');

    $response = $this->actingAs($this->hrd)->get('/hari-libur/ekspor');

    $response->assertOk();
    expect((string) $response->headers->get('content-disposition'))->toContain('.xlsx');
});

test('04 §4.2 DemoSeeder mengisi libur contoh (semua lokasi & khusus gudang) secara idempoten', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(Holiday::query()->orderBy('start_date')->pluck('name')->all())
        ->toBe(['Hari Kemerdekaan RI (contoh)', 'Stok opname akhir tahun (contoh)']);

    $opname = Holiday::query()->where('is_national', false)->with('locations')->sole();
    expect($opname->dayCount())->toBe(2)
        ->and($opname->applies_to_all)->toBeFalse()
        ->and($opname->locations->pluck('code')->all())->toBe(['GDG-01']);
});
