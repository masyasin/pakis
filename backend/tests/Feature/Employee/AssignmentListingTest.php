<?php

use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Position;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EmployeeWorld;

beforeEach(function () {
    $this->world = EmployeeWorld::make($this);
    $this->hrd = $this->world->actor('hrd');
});

/** @return list<string> nama pegawai di daftar penugasan */
function assignmentNames(User $user, string $query = ''): array
{
    app()->forgetScopedInstances();
    $names = [];
    test()->actingAs($user)->get('/penugasan'.$query)->assertOk()
        ->assertInertia(function (Assert $page) use (&$names) {
            $names = array_map(fn (array $r) => $r['employee']['name'], $page->toArray()['props']['rows']);
        });

    return $names;
}

test('07 §3.4 daftar penugasan: default aktif, urut mulai terbaru, kolom katalog', function () {
    $lama = $this->world->employee(attributes: ['full_name' => 'Pegawai Lama', 'join_date' => '2020-01-01']);
    $this->world->employee(attributes: ['full_name' => 'Pegawai Baru', 'join_date' => '2026-01-01']);
    $lama->assignments()->update(['end_date' => '2021-01-01']);

    $this->actingAs($this->hrd)->get('/penugasan')
        ->assertInertia(fn (Assert $page) => $page->component('assignments/index')
            ->has('rows', 1)
            ->where('rows.0.employee.name', 'Pegawai Baru')
            ->where('rows.0.position', 'Staf Gudang')
            ->where('rows.0.location', 'Gudang Uji')
            ->where('rows.0.is_primary', true)
            ->where('rows.0.mode_from_position', true));

    expect(assignmentNames($this->hrd, '?filter[active]=0'))->toBe(['Pegawai Lama']);
});

test('07 §3.4 cari nama/nomor, filter lokasi/jabatan/departemen/atasan/mulai, sort nama', function () {
    $spv = $this->world->employee(attributes: ['full_name' => 'Agus Atasan', 'join_date' => '2015-01-01']);
    $a = $this->world->employee($this->world->toko, $spv, ['full_name' => 'Wahyu Toko', 'join_date' => '2026-02-01']);
    $driver = Position::query()->create(['code' => 'DRV', 'name' => 'Driver', 'department_id' => $this->world->department->id]);
    Employee::factory()->assignedTo($this->world->gudang, $driver)->create(['full_name' => 'Bayu Driver', 'join_date' => '2025-06-01']);

    expect(assignmentNames($this->hrd, '?q=wahyu'))->toBe(['Wahyu Toko'])
        ->and(assignmentNames($this->hrd, '?q='.$a->employee_number))->toBe(['Wahyu Toko'])
        ->and(assignmentNames($this->hrd, "?filter[location]={$this->world->toko->id}"))->toBe(['Wahyu Toko'])
        ->and(assignmentNames($this->hrd, "?filter[position]={$driver->id}"))->toBe(['Bayu Driver'])
        ->and(assignmentNames($this->hrd, "?filter[department]={$this->world->department->id}"))->toHaveCount(3)
        ->and(assignmentNames($this->hrd, "?filter[supervisor]={$spv->id}"))->toBe(['Wahyu Toko'])
        ->and(assignmentNames($this->hrd, '?filter[start_date][from]=2026-01-01'))->toBe(['Wahyu Toko'])
        ->and(assignmentNames($this->hrd, '?sort=employee_name'))->toBe(['Agus Atasan', 'Bayu Driver', 'Wahyu Toko']);
});

test('06 §5 daftar penugasan ber-scope: kepala lokasi hanya lokasinya, supervisor timnya', function () {
    $kepala = $this->world->employee($this->world->gudang, attributes: ['full_name' => 'Kepala Gudang']);
    $this->world->employee($this->world->gudang, $kepala, ['full_name' => 'Staf Gudang']);
    $this->world->employee($this->world->toko, attributes: ['full_name' => 'Staf Toko']);

    $spv = $this->world->employee($this->world->toko, attributes: ['full_name' => 'Supervisor Toko']);
    $this->world->employee($this->world->toko, $spv, ['full_name' => 'Sales Toko']);

    expect(assignmentNames($this->world->actor('kepala_lokasi', $kepala), '?sort=employee_name'))->toBe(['Kepala Gudang', 'Staf Gudang'])
        ->and(assignmentNames($this->world->actor('supervisor', $spv), '?sort=employee_name'))->toBe(['Sales Toko', 'Supervisor Toko']);
});

test('FR-026 ekspor penugasan mengunduh berkas', function () {
    $this->world->employee();

    $response = $this->actingAs($this->hrd)->get('/penugasan/ekspor');
    $response->assertOk();
    expect((string) $response->headers->get('content-disposition'))->toContain('.xlsx');
});

test('07 §4 detail pegawai: tombol penugasan hanya untuk assignments.manage, pilihan mode tersedia', function () {
    $employee = $this->world->employee();

    $this->actingAs($this->hrd)->get("/pegawai/{$employee->id}?tab=penugasan")
        ->assertInertia(fn (Assert $page) => $page->where('can.manageAssignments', true)
            ->has('modes', 3)
            ->where('mutationBackdateDays', 31)
            ->where('assignments.0.running', true)
            ->where('assignments.0.start_date', $employee->join_date->toDateString()));

    $kepala = $this->world->actor('kepala_lokasi', $employee);
    $this->actingAs($kepala)->get("/pegawai/{$employee->id}?tab=penugasan")
        ->assertInertia(fn (Assert $page) => $page->where('can.manageAssignments', false));
});
