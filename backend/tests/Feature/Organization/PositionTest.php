<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organization\Enums\AttendanceMode;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Position;
use App\Domain\Organization\Support\PositionUsage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOrgStructure;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->useRealPermissions();
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
    $this->gudang = Department::query()->create(['code' => 'GDG', 'name' => 'Gudang']);
    $this->penjualan = Department::query()->create(['code' => 'PJL', 'name' => 'Penjualan']);
});

function jabatan(string $code, ?Position $parent = null, array $attributes = []): Position
{
    return Position::query()->create([
        'code' => $code, 'name' => 'Jabatan '.$code, 'department_id' => test()->gudang->id,
        'parent_position_id' => $parent?->id, ...$attributes,
    ]);
}

/** @return array<string, mixed> */
function positionInput(array $override = []): array
{
    return ['code' => 'ka-gdg', 'name' => 'Kepala Gudang', 'department_id' => test()->gudang->id, 'level' => 3, 'attendance_mode' => 'location', 'is_active' => true, ...$override];
}

test('FR-024 tambah jabatan: tersimpan dengan mode absen & tercatat di audit', function () {
    $this->actingAs($this->admin)->post('/jabatan', positionInput(['attendance_mode' => 'any_location']))
        ->assertRedirect('/jabatan')
        ->assertSessionHasNoErrors();

    $position = Position::query()->sole();
    expect($position->code)->toBe('KA-GDG')
        ->and($position->attendance_mode)->toBe(AttendanceMode::AnyLocation)
        ->and($position->level)->toBe(3);

    $audit = AuditLog::query()->where('event', 'position.created')->sole();
    expect($audit->new_values['attendance_mode'])->toBe('any_location');
});

test('BR-042 mode absen bawaan = location, tampil di daftar dengan labelnya', function () {
    jabatan('STF');

    expect(Position::query()->sole()->attendance_mode)->toBe(AttendanceMode::Location);

    $this->actingAs($this->admin)->get('/jabatan')
        ->assertInertia(fn (Assert $page) => $page->component('positions/index')
            ->where('rows.0.attendance_mode', 'location')
            ->where('rows.0.attendance_mode_label', 'Di lokasi tugas'));
});

test('BR-042 form menampilkan tiga mode absen beserta penjelasannya', function () {
    $this->actingAs($this->admin)->get('/jabatan/baru')
        ->assertInertia(fn (Assert $page) => $page->component('positions/form')
            ->has('modes', 3)
            ->where('modes.2.value', 'anywhere')
            ->where('modes.2.help', AttendanceMode::Anywhere->help()));
});

test('FR-024 validasi: mode tak dikenal, level 0/11, departemen tak ada', function (array $override, string $field) {
    $this->actingAs($this->admin)->post('/jabatan', positionInput($override))->assertSessionHasErrors($field);
    expect(Position::query()->count())->toBe(0);
})->with([
    'mode tak dikenal' => [['attendance_mode' => 'rumah'], 'attendance_mode'],
    'level 0' => [['level' => 0], 'level'],
    'level 11' => [['level' => 11], 'level'],
    'departemen tak ada' => [['department_id' => 999999], 'department_id'],
]);

test('FR-024 atasan jabatan tidak boleh membentuk siklus atau menunjuk diri sendiri', function () {
    $owner = jabatan('OWN');
    $spv = jabatan('SPV', $owner);
    $sales = jabatan('SLS', $spv);

    $this->actingAs($this->admin)
        ->put("/jabatan/{$owner->id}", positionInput(['code' => 'OWN', 'parent_position_id' => $sales->id]))
        ->assertSessionHasErrors(['parent_position_id' => 'Jabatan atasan ini berada di bawah jabatan yang sedang diubah, sehingga membentuk lingkaran. Pilih atasan lain.']);

    $this->actingAs($this->admin)
        ->put("/jabatan/{$owner->id}", positionInput(['code' => 'OWN', 'parent_position_id' => $owner->id]))
        ->assertSessionHasErrors('parent_position_id');

    expect($owner->fresh()->parent_position_id)->toBeNull();
});

test('FR-024 atasan boleh dari departemen lain; perubahan diaudit', function () {
    $manager = jabatan('MGR', attributes: ['department_id' => $this->penjualan->id]);
    $head = jabatan('KA');

    $this->actingAs($this->admin)
        ->put("/jabatan/{$head->id}", positionInput(['code' => 'KA', 'parent_position_id' => $manager->id]))
        ->assertSessionHasNoErrors();

    expect($head->fresh()->parent_position_id)->toBe($manager->id)
        ->and(AuditLog::query()->where('event', 'position.updated')->exists())->toBeTrue();
});

test('FR-024 departemen/atasan nonaktif tidak bisa dipilih, kecuali yang sudah terpasang', function () {
    $parent = jabatan('ATS');
    $child = jabatan('BWH', $parent);
    $parent->update(['is_active' => false]);
    $this->gudang->update(['is_active' => false]);

    $this->actingAs($this->admin)->post('/jabatan', positionInput())->assertSessionHasErrors('department_id');
    $this->actingAs($this->admin)
        ->post('/jabatan', positionInput(['department_id' => $this->penjualan->id, 'parent_position_id' => $parent->id]))
        ->assertSessionHasErrors('parent_position_id');

    $this->actingAs($this->admin)
        ->put("/jabatan/{$child->id}", positionInput(['code' => 'BWH', 'name' => 'Ganti nama', 'parent_position_id' => $parent->id]))
        ->assertSessionHasNoErrors();
});

test('FR-024 hapus: ditolak bila masih atasan jabatan lain atau dipakai penugasan', function () {
    $parent = jabatan('ATS');
    jabatan('BWH', $parent);

    $this->actingAs($this->admin)->from('/jabatan')->delete("/jabatan/{$parent->id}")->assertSessionHasErrors('position');

    $solo = jabatan('SOLO');
    // DDL biasa memutus transaksi RefreshDatabase; TEMPORARY TABLE tidak (pola T-010).
    DB::statement('CREATE TEMPORARY TABLE tmp_assignments (id INT PRIMARY KEY, position_id BIGINT UNSIGNED)');
    DB::table('tmp_assignments')->insert(['id' => 1, 'position_id' => $solo->id]);
    app()->instance(PositionUsage::class, new PositionUsage([['tmp_assignments', 'position_id']]));

    $this->actingAs($this->admin)->from('/jabatan')->delete("/jabatan/{$solo->id}")->assertSessionHasErrors('position');
});

test('FR-024 jabatan tanpa pemakai bisa dihapus (soft delete + audit)', function () {
    $solo = jabatan('SOLO');

    $this->actingAs($this->admin)->delete("/jabatan/{$solo->id}")->assertRedirect('/jabatan');

    expect(Position::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'position.deleted')->exists())->toBeTrue();
});

test('FR-023 departemen yang dipakai jabatan tidak bisa dihapus (integrasi DepartmentUsage)', function () {
    jabatan('STF');

    $this->actingAs($this->admin)->from('/departemen')->delete("/departemen/{$this->gudang->id}")
        ->assertSessionHasErrors('department');
});

test('FR-024 daftar: cari, filter departemen/mode/level/aktif, sort', function () {
    jabatan('KA-GDG', attributes: ['name' => 'Kepala Gudang', 'level' => 3]);
    jabatan('DRV', attributes: ['name' => 'Driver', 'attendance_mode' => 'any_location']);
    jabatan('SLS', attributes: ['name' => 'Sales', 'department_id' => $this->penjualan->id, 'attendance_mode' => 'anywhere']);
    jabatan('LAMA', attributes: ['name' => 'Jabatan Lama', 'is_active' => false]);

    $this->actingAs($this->admin)->get('/jabatan?q=kepala')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'KA-GDG'));
    $this->actingAs($this->admin)->get("/jabatan?filter[department]={$this->penjualan->id}")
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'SLS'));
    $this->actingAs($this->admin)->get('/jabatan?filter[mode][]=any_location&filter[mode][]=anywhere')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2));
    $this->actingAs($this->admin)->get('/jabatan?filter[level][max]=3')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'KA-GDG'));
    $this->actingAs($this->admin)->get('/jabatan?filter[active]=0')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'LAMA'));
    $this->actingAs($this->admin)->get('/jabatan?sort=level')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.code', 'KA-GDG'));
});

test('FR-024 ekspor jabatan mengunduh berkas', function () {
    jabatan('STF');

    $response = $this->actingAs($this->admin)->get('/jabatan/ekspor');

    $response->assertOk();
    expect((string) $response->headers->get('content-disposition'))->toContain('.xlsx');
});

test('FR-024 lookup: hanya aktif, bisa disaring per departemen & mengecualikan diri sendiri', function () {
    $a = jabatan('AKTIF');
    jabatan('MATI', attributes: ['is_active' => false]);
    jabatan('PJL-1', attributes: ['department_id' => $this->penjualan->id]);

    $this->actingAs($this->admin)->getJson('/lookup/positions')->assertJsonCount(2);
    $this->actingAs($this->admin)->getJson("/lookup/positions?department={$this->gudang->id}")
        ->assertJsonCount(1)->assertJsonFragment(['label' => 'Jabatan AKTIF', 'hint' => 'Gudang']);
    $this->actingAs($this->admin)->getJson("/lookup/positions?department={$this->gudang->id}&exclude={$a->id}")->assertJsonCount(0);
});

test('06 §4 kepala lokasi & supervisor tidak punya akses jabatan', function (string $role) {
    FakeOrgStructure::bind();
    $user = User::factory()->create(['employee_id' => employeeRow(1)])->assignRole($role);

    $this->actingAs($user)->get('/jabatan')->assertForbidden();
    $this->actingAs($user)->post('/jabatan', positionInput())->assertForbidden();
})->with(['kepala_lokasi', 'supervisor']);
