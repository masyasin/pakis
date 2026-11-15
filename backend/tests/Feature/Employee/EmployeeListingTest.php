<?php

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Position;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EmployeeWorld;

beforeEach(function () {
    $this->world = EmployeeWorld::make($this);
    $this->hrd = $this->world->actor('hrd');
});

/** @return list<string> nama baris daftar pegawai untuk pengguna & query ini */
function employeeNames(User $user, string $query = ''): array
{
    // DataScope di-memo per instance scoped, dan di test instance itu bertahan antar-request.
    app()->forgetScopedInstances();
    $names = [];
    test()->actingAs($user)->get('/pegawai'.$query)->assertOk()
        ->assertInertia(function (Assert $page) use (&$names) {
            $names = array_column($page->toArray()['props']['rows'], 'full_name');
        });

    return $names;
}

test('07 §3.4 daftar pegawai: kolom sesuai katalog, default status aktif, urut nama', function () {
    $this->world->employee(attributes: ['full_name' => 'Wahyu Pratama', 'phone' => '6281234567890']);
    $this->world->employee(attributes: ['full_name' => 'Agus Setiawan']);
    Employee::factory()->inactive()->create(['full_name' => 'Bambang Keluar']);

    $this->actingAs($this->hrd)->get('/pegawai')
        ->assertInertia(fn (Assert $page) => $page->component('employees/index')
            ->has('rows', 2)
            ->where('rows.0.full_name', 'Agus Setiawan')
            ->where('rows.1.position', 'Staf Gudang')
            ->where('rows.1.location', 'Gudang Uji')
            ->where('rows.1.phone', '6281234567890')
            ->where('rows.1.has_account', false)
            ->where('can.create', true)
            ->where('can.export', true));

    expect(employeeNames($this->hrd, '?filter[status]=inactive'))->toBe(['Bambang Keluar'])
        ->and(employeeNames($this->hrd, '?filter[status]=all'))->toHaveCount(3);
});

test('07 §3.4 cari nama, nomor, HP (format 08…), email', function () {
    $a = $this->world->employee(attributes: ['full_name' => 'Wahyu Pratama', 'phone' => '6281234567890', 'email' => 'wahyu@contoh.test']);
    $this->world->employee(attributes: ['full_name' => 'Agus Setiawan']);

    expect(employeeNames($this->hrd, '?q=wahyu'))->toBe(['Wahyu Pratama'])
        ->and(employeeNames($this->hrd, '?q='.$a->employee_number))->toBe(['Wahyu Pratama'])
        ->and(employeeNames($this->hrd, '?q=0812-3456'))->toBe(['Wahyu Pratama'])
        ->and(employeeNames($this->hrd, '?q=contoh.test'))->toBe(['Wahyu Pratama']);
});

test('07 §3.4 filter lokasi, jabatan, departemen, punya akun, status kerja, tanggal masuk', function () {
    $gudang = $this->world->employee($this->world->gudang, attributes: ['full_name' => 'Di Gudang', 'join_date' => '2025-03-01']);
    $toko = $this->world->employee($this->world->toko, attributes: ['full_name' => 'Di Toko', 'join_date' => '2026-02-01']);
    User::factory()->create(['employee_id' => $toko->id]);
    $other = Position::query()->create(['code' => 'DRV', 'name' => 'Driver', 'department_id' => $this->world->department->id]);
    Employee::factory()->assignedTo($this->world->gudang, $other)->create(['full_name' => 'Pak Driver']);

    expect(employeeNames($this->hrd, "?filter[location]={$this->world->toko->id}"))->toBe(['Di Toko'])
        ->and(employeeNames($this->hrd, "?filter[position]={$other->id}"))->toBe(['Pak Driver'])
        ->and(employeeNames($this->hrd, "?filter[department]={$this->world->department->id}"))->toHaveCount(3)
        ->and(employeeNames($this->hrd, '?filter[has_account]=1'))->toBe(['Di Toko'])
        ->and(employeeNames($this->hrd, '?filter[has_account]=0'))->toBe(['Di Gudang', 'Pak Driver'])
        ->and(employeeNames($this->hrd, "?filter[employment_status][]={$this->world->permanent}"))->toHaveCount(3)
        ->and(employeeNames($this->hrd, '?filter[join_date][from]=2026-01-01'))->toContain('Di Toko')
        ->and(employeeNames($this->hrd, '?filter[join_date][from]=2026-01-01'))->not->toContain('Di Gudang');
});

test('07 §3.4 sort nomor & tanggal masuk', function () {
    $this->world->employee(attributes: ['full_name' => 'Lama', 'join_date' => '2020-01-01']);
    $this->world->employee(attributes: ['full_name' => 'Baru', 'join_date' => '2026-01-01']);

    expect(employeeNames($this->hrd, '?sort=-join_date')[0])->toBe('Baru')
        ->and(employeeNames($this->hrd, '?sort=join_date')[0])->toBe('Lama')
        ->and(employeeNames($this->hrd, '?sort=employee_number'))->toHaveCount(2);
});

test('06 §5 scope location dengan data penugasan nyata: lokasi tugas + lokasi yang dikepalai', function () {
    $kepalaGudang = $this->world->employee($this->world->gudang, attributes: ['full_name' => 'Kepala Gudang']);
    $this->world->employee($this->world->gudang, attributes: ['full_name' => 'Staf Gudang A']);
    $this->world->employee($this->world->toko, attributes: ['full_name' => 'Staf Toko B']);
    $kepala = $this->world->actor('kepala_lokasi', $kepalaGudang);

    expect(employeeNames($kepala))->toBe(['Kepala Gudang', 'Staf Gudang A']);

    // Menjadi kepala toko juga → staf toko ikut terlihat.
    $this->world->toko->update(['head_employee_id' => $kepalaGudang->id]);
    expect(employeeNames($kepala))->toBe(['Kepala Gudang', 'Staf Gudang A', 'Staf Toko B']);
});

test('06 §5 scope team dengan data penugasan nyata: diri + bawahan langsung & tidak langsung', function () {
    $spv = $this->world->employee(attributes: ['full_name' => 'Supervisor']);
    $sales = $this->world->employee($this->world->toko, $spv, ['full_name' => 'Sales Satu']);
    $this->world->employee($this->world->toko, $sales, ['full_name' => 'Sales Bawahan']);
    $this->world->employee(attributes: ['full_name' => 'Orang Lain']);
    $actor = $this->world->actor('supervisor', $spv);

    expect(employeeNames($actor))->toBe(['Sales Bawahan', 'Sales Satu', 'Supervisor']);
});

test('06 §5 filter lokasi di luar scope ditolak sebagai pilihan tidak ditemukan', function () {
    $kepalaGudang = $this->world->employee($this->world->gudang);
    $this->world->employee($this->world->toko, attributes: ['full_name' => 'Staf Toko']);
    $kepala = $this->world->actor('kepala_lokasi', $kepalaGudang);

    expect(employeeNames($kepala, "?filter[location]={$this->world->toko->id}"))->not->toContain('Staf Toko');
});

test('FR-025 ekspor pegawai mengunduh berkas & mengikuti scope', function () {
    $this->world->employee();

    $response = $this->actingAs($this->hrd)->get('/pegawai/ekspor');
    $response->assertOk();
    expect((string) $response->headers->get('content-disposition'))->toContain('.xlsx');

    $kepala = $this->world->actor('kepala_lokasi', $this->world->employee());
    $this->actingAs($kepala)->get('/pegawai/ekspor')->assertForbidden();
});

test('05 §4.11 lookup employees: aktif, ber-scope, without_account & exclude', function () {
    $a = $this->world->employee(attributes: ['full_name' => 'Andi Lookup']);
    $b = $this->world->employee(attributes: ['full_name' => 'Bayu Lookup']);
    User::factory()->create(['employee_id' => $b->id]);
    Employee::factory()->inactive()->create(['full_name' => 'Citra Nonaktif']);

    $this->actingAs($this->hrd)->getJson('/lookup/employees?q=lookup')
        ->assertJsonCount(2)
        ->assertJsonFragment(['label' => 'Andi Lookup', 'hint' => "{$a->employee_number} · Staf Gudang"]);
    $this->actingAs($this->hrd)->getJson('/lookup/employees?q=lookup&without_account=1')
        ->assertJsonCount(1)->assertJsonFragment(['label' => 'Andi Lookup']);
    $this->actingAs($this->hrd)->getJson("/lookup/employees?q=lookup&exclude={$a->id}")
        ->assertJsonCount(1)->assertJsonFragment(['label' => 'Bayu Lookup']);
    $this->actingAs($this->hrd)->getJson('/lookup/employees?q=citra')->assertJsonCount(0);

    // Ber-scope: kepala lokasi toko tidak melihat pegawai gudang.
    $kepalaToko = $this->world->employee($this->world->toko, attributes: ['full_name' => 'Kepala Toko']);
    $kepala = $this->world->actor('kepala_lokasi', $kepalaToko);
    $this->actingAs($kepala)->getJson('/lookup/employees?q=lookup')->assertJsonCount(0);
});

test('05 §4.11 lookup employees tanpa izin terkait → 403', function () {
    $role = Role::query()->create(['name' => 'tanpa_pegawai', 'label' => 'Tanpa', 'guard_name' => 'web', 'data_scope' => DataScope::All]);
    $role->givePermissionTo(['dashboard.view', 'shifts.view']);
    $user = User::factory()->create()->assignRole('tanpa_pegawai');

    $this->actingAs($user)->getJson('/lookup/employees')->assertForbidden();
});
