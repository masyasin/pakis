<?php

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Employee\Actions\CreateAssignment;
use App\Domain\Employee\Events\AssignmentChanged;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\SupervisorCycleGuard;
use App\Domain\Organization\Models\Position;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\EmployeeWorld;

uses(AssertsIdor::class);

beforeEach(function () {
    $this->world = EmployeeWorld::make($this);
    $this->hrd = $this->world->actor('hrd');
    $this->today = DisplayTime::toLocal(now())->toImmutable()->startOfDay();
});

/** @return array<string, mixed> */
function mutation(array $override = []): array
{
    return [
        'is_primary' => true,
        'position_id' => test()->world->head->id,
        'location_id' => test()->world->toko->id,
        'start_date' => test()->today->toDateString(),
        ...$override,
    ];
}

function primaryOf(Employee $employee): Assignment
{
    return Assignment::query()->where('employee_id', $employee->id)->where('is_primary', true)->whereNull('end_date')->sole();
}

// --- BR-052 Mutasi ----------------------------------------------------------------------------

test('BR-052 mutasi menutup penugasan lama (end = mulai baru − 1) dan membuat yang baru, satu event', function () {
    Event::fake([AssignmentChanged::class]);
    $employee = $this->world->employee($this->world->gudang);
    $old = primaryOf($employee);

    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/penugasan", mutation())
        ->assertRedirect("/pegawai/{$employee->id}?tab=penugasan")->assertSessionHasNoErrors();

    $new = primaryOf($employee);
    expect($old->fresh()->end_date->toDateString())->toBe($this->today->subDay()->toDateString())
        ->and($new->location_id)->toBe($this->world->toko->id)
        ->and($new->start_date->toDateString())->toBe($this->today->toDateString())
        ->and(AuditLog::query()->where('event', 'assignment.ended')->where('auditable_id', $old->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'assignment.created')->where('auditable_id', $new->id)->exists())->toBeTrue();

    Event::assertDispatchedTimes(AssignmentChanged::class, 1);
    Event::assertDispatched(AssignmentChanged::class, fn (AssignmentChanged $e) => $e->employeeId === $employee->id && $e->reason === 'mutated');
});

test('BR-052 mutasi gagal di tengah → penugasan lama tetap terbuka (satu transaksi)', function () {
    $employee = $this->world->employee($this->world->gudang);
    $old = primaryOf($employee);
    // Lokasi tujuan dinonaktifkan di antara validasi & simpan: CreateAssignment menolak setelah yang lama ditutup.
    DB::listen(function ($query) {
        if (str_starts_with($query->sql, 'update `assignments` set `end_date`')) {
            DB::table('locations')->update(['is_active' => false]);
        }
    });

    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/penugasan", mutation())->assertSessionHasErrors('location_id');

    expect($old->fresh()->end_date)->toBeNull()
        ->and(Assignment::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

test('BR-052 tanggal mulai harus setelah penugasan lama dan ≤ 31 hari ke belakang', function () {
    $employee = $this->world->employee($this->world->gudang, attributes: ['join_date' => $this->today->subDays(10)->toDateString()]);

    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/penugasan", mutation(['start_date' => $this->today->subDays(10)->toDateString()]))
        ->assertSessionHasErrors('start_date');
    expect(session('errors')->first('start_date'))->toContain('Tanggal mulai harus setelah');

    $old = $this->world->employee($this->world->gudang);
    $this->actingAs($this->hrd)->post("/pegawai/{$old->id}/penugasan", mutation(['start_date' => $this->today->subDays(32)->toDateString()]))
        ->assertSessionHasErrors('start_date');
    expect(session('errors')->first('start_date'))->toContain('paling jauh 31 hari');
});

test('BR-052 mutasi terjadwal: penugasan yang berlaku hari ini belum berubah', function () {
    $employee = $this->world->employee($this->world->gudang);
    $future = $this->today->addDays(7)->toDateString();

    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/penugasan", mutation(['start_date' => $future]))->assertSessionHasNoErrors();

    expect($employee->primaryAssignment()->first()?->location_id)->toBe($this->world->gudang->id)
        ->and(primaryOf($employee)->start_date->toDateString())->toBe($future);
});

test('BR-052 pegawai nonaktif tidak bisa dimutasi; tombol Mutasi tidak tersedia', function () {
    $employee = $this->world->employee($this->world->gudang);
    $employee->forceFill(['status' => 'inactive', 'exit_date' => $this->today->toDateString()])->save();

    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/penugasan", mutation())->assertSessionHasErrors('start_date');
    $this->actingAs($this->hrd)->get("/pegawai/{$employee->id}?tab=penugasan")
        ->assertInertia(fn (Assert $page) => $page->where('can.manageAssignments', false));
});

// --- BR-051 Penugasan -------------------------------------------------------------------------

test('BR-051 dua penugasan utama aktif ditolak di aplikasi dan di DB', function () {
    $employee = $this->world->employee($this->world->gudang);

    expect(fn () => app(CreateAssignment::class)->handle($employee, [
        'position_id' => $this->world->staff->id, 'location_id' => $this->world->toko->id, 'is_primary' => true,
    ], null, ''))->toThrow(ValidationException::class);

    expect(fn () => DB::table('assignments')->insert([
        'employee_id' => $employee->id, 'position_id' => $this->world->staff->id, 'location_id' => $this->world->toko->id,
        'is_primary' => true, 'start_date' => $this->today->toDateString(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('BR-051 A atasan B lalu B atasan A ditolak (langsung & lewat beberapa tingkat)', function () {
    $a = $this->world->employee();
    $b = $this->world->employee(supervisor: $a);
    $c = $this->world->employee(supervisor: $b);

    // Langsung: A di bawah B.
    $this->actingAs($this->hrd)->put('/penugasan/'.primaryOf($a)->id, ['supervisor_employee_id' => $b->id])
        ->assertSessionHasErrors('supervisor_employee_id');
    expect(session('errors')->first('supervisor_employee_id'))->toContain('lingkaran');
    // Lewat dua tingkat: A di bawah C (C → B → A).
    $this->actingAs($this->hrd)->put('/penugasan/'.primaryOf($a)->id, ['supervisor_employee_id' => $c->id])
        ->assertSessionHasErrors('supervisor_employee_id');
    expect(session('errors')->first('supervisor_employee_id'))->toContain('lingkaran');
    // Diri sendiri.
    $this->actingAs($this->hrd)->put('/penugasan/'.primaryOf($a)->id, ['supervisor_employee_id' => $a->id])
        ->assertSessionHasErrors('supervisor_employee_id');
    expect(session('errors')->first('supervisor_employee_id'))->toContain('dirinya sendiri');

    expect(primaryOf($a)->supervisor_employee_id)->toBeNull();
});

test('BR-051 rantai atasan lebih dari 10 tingkat ditolak', function () {
    $chain = [$this->world->employee()];
    for ($i = 1; $i <= SupervisorCycleGuard::MAX_DEPTH; $i++) {
        $chain[] = $this->world->employee(supervisor: $chain[$i - 1]);
    }
    $newcomer = $this->world->employee();

    // Atasan = ujung rantai 11 orang → rantai baru 12 tingkat.
    $this->actingAs($this->hrd)->put('/penugasan/'.primaryOf($newcomer)->id, ['supervisor_employee_id' => end($chain)->id])
        ->assertSessionHasErrors('supervisor_employee_id');
    expect(session('errors')->first('supervisor_employee_id'))->toContain('lebih dari 10 tingkat');
    // Atasan di tengah rantai masih boleh.
    $this->actingAs($this->hrd)->put('/penugasan/'.primaryOf($newcomer)->id, ['supervisor_employee_id' => $chain[3]->id])
        ->assertSessionHasNoErrors();
});

test('BR-051 penugasan tambahan: boleh di lokasi lain, ditolak di lokasi yang sama; jabatan nonaktif ditolak', function () {
    $employee = $this->world->employee($this->world->gudang);

    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/penugasan", mutation(['is_primary' => false, 'location_id' => $this->world->gudang->id]))
        ->assertSessionHasErrors('location_id');
    expect(session('errors')->first('location_id'))->toContain('sudah punya penugasan aktif');

    $inactive = Position::query()->create(['code' => 'OLD', 'name' => 'Lama', 'department_id' => $this->world->department->id, 'is_active' => false]);
    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/penugasan", mutation(['is_primary' => false, 'position_id' => $inactive->id]))
        ->assertSessionHasErrors('position_id');

    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/penugasan", mutation(['is_primary' => false]))
        ->assertSessionHasNoErrors();

    expect(Assignment::query()->where('employee_id', $employee->id)->whereNull('end_date')->count())->toBe(2)
        ->and(primaryOf($employee)->location_id)->toBe($this->world->gudang->id);
});

// --- Ubah & akhiri ----------------------------------------------------------------------------

test('05 ubah penugasan: hanya atasan, mode absen, catatan; diaudit', function () {
    $spv = $this->world->employee();
    $employee = $this->world->employee($this->world->gudang);
    $assignment = primaryOf($employee);

    $this->actingAs($this->hrd)->put("/penugasan/{$assignment->id}", [
        'supervisor_employee_id' => $spv->id,
        'attendance_mode' => 'anywhere',
        'note' => 'Merangkap sales',
        'location_id' => $this->world->toko->id,
        'end_date' => '2020-01-01',
    ])->assertSessionHasNoErrors();

    $fresh = $assignment->fresh();
    expect($fresh->supervisor_employee_id)->toBe($spv->id)
        ->and($fresh->attendance_mode?->value)->toBe('anywhere')
        ->and($fresh->location_id)->toBe($this->world->gudang->id)
        ->and($fresh->end_date)->toBeNull()
        ->and(AuditLog::query()->where('event', 'assignment.updated')->where('auditable_id', $assignment->id)->exists())->toBeTrue();
});

test('keputusan Mas: penugasan utama tidak bisa diakhiri; tambahan bisa', function () {
    $employee = $this->world->employee($this->world->gudang);

    $this->actingAs($this->hrd)->post('/penugasan/'.primaryOf($employee)->id.'/akhiri', ['end_date' => $this->today->toDateString()])
        ->assertSessionHasErrors('end_date');
    expect(session('errors')->first('end_date'))->toContain('Gunakan Mutasi');

    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/penugasan", mutation(['is_primary' => false]));
    $extra = Assignment::query()->where('employee_id', $employee->id)->where('is_primary', false)->sole();

    $this->actingAs($this->hrd)->post("/penugasan/{$extra->id}/akhiri", ['end_date' => $this->today->subDays(40)->toDateString()])
        ->assertSessionHasErrors('end_date');
    $this->actingAs($this->hrd)->post("/penugasan/{$extra->id}/akhiri", ['end_date' => $this->today->toDateString()])
        ->assertSessionHasNoErrors();

    expect($extra->fresh()->end_date?->toDateString())->toBe($this->today->toDateString())
        ->and(AuditLog::query()->where('event', 'assignment.ended')->where('auditable_id', $extra->id)->exists())->toBeTrue();
});

// --- Scope rangkap (keputusan Mas: dua arah) -----------------------------------------------------

test('BR-051 rangkap dua arah: pegawai rangkap terlihat kepala lokasi kedua; kepala yang merangkap melihat lokasi kedua', function () {
    $kepalaToko = $this->world->employee($this->world->toko, attributes: ['full_name' => 'Kepala Toko']);
    $this->world->toko->update(['head_employee_id' => $kepalaToko->id]);
    $stafGudang = $this->world->employee($this->world->gudang, attributes: ['full_name' => 'Staf Gudang Rangkap']);
    $kepalaGudang = $this->world->employee($this->world->gudang, attributes: ['full_name' => 'Kepala Gudang']);
    $stafToko = $this->world->employee($this->world->toko, attributes: ['full_name' => 'Staf Toko']);

    $kepalaTokoUser = $this->world->actor('kepala_lokasi', $kepalaToko);
    $kepalaGudangUser = $this->world->actor('kepala_lokasi', $kepalaGudang);

    $this->actingAs($kepalaTokoUser)->get("/pegawai/{$stafGudang->id}")->assertNotFound();
    $this->actingAs($kepalaGudangUser)->get("/pegawai/{$stafToko->id}")->assertNotFound();

    // Staf gudang merangkap di toko; kepala gudang juga merangkap di toko (mulai 5 hari lalu).
    foreach ([$stafGudang, $kepalaGudang] as $e) {
        $this->actingAs($this->hrd)->post("/pegawai/{$e->id}/penugasan", mutation(['is_primary' => false, 'start_date' => $this->today->subDays(5)->toDateString()]))->assertSessionHasNoErrors();
    }
    app()->forgetScopedInstances();

    $this->actingAs($kepalaTokoUser)->get("/pegawai/{$stafGudang->id}")->assertOk();
    app()->forgetScopedInstances();
    $this->actingAs($kepalaGudangUser)->get("/pegawai/{$stafToko->id}")->assertOk();

    // Rangkap diakhiri → akses hilang.
    $extra = Assignment::query()->where('employee_id', $stafGudang->id)->where('is_primary', false)->sole();
    $this->actingAs($this->hrd)->post("/penugasan/{$extra->id}/akhiri", ['end_date' => $this->today->subDay()->toDateString()])->assertSessionHasNoErrors();
    app()->forgetScopedInstances();
    $this->actingAs($kepalaTokoUser)->get("/pegawai/{$stafGudang->id}")->assertNotFound();
});

// --- Akses ------------------------------------------------------------------------------------

test('06 §4 kepala lokasi & supervisor hanya melihat penugasan: mutasi/ubah/akhiri 403', function (string $role) {
    $self = $this->world->employee($this->world->gudang);
    $mate = $this->world->employee($this->world->gudang, $self);
    $actor = $this->world->actor($role, $self);

    $this->actingAs($actor)->get('/penugasan')->assertOk();
    $this->actingAs($actor)->post("/pegawai/{$mate->id}/penugasan", mutation())->assertForbidden();
    $this->actingAs($actor)->put('/penugasan/'.primaryOf($mate)->id, [])->assertForbidden();
    $this->actingAs($actor)->post('/penugasan/'.primaryOf($mate)->id.'/akhiri', ['end_date' => $this->today->toDateString()])->assertForbidden();
})->with(['kepala_lokasi', 'supervisor']);

test('SEC-033 IDOR: mutasi, ubah, akhiri penugasan pegawai di luar scope → 404', function () {
    $self = $this->world->employee($this->world->gudang);
    $outside = $this->world->employee($this->world->toko);
    $role = Role::query()->create(['name' => 'hrd_lokasi', 'label' => 'HRD Lokasi', 'guard_name' => 'web', 'data_scope' => DataScope::Location]);
    $role->givePermissionTo(['employees.view', 'assignments.view', 'assignments.manage']);
    $actor = User::factory()->withTwoFactor()->create(['employee_id' => $self->id])->assignRole('hrd_lokasi');

    $this->assertOutOfScope404($actor, 'POST', "/pegawai/{$outside->id}/penugasan", mutation());
    $this->assertOutOfScope404($actor, 'PUT', '/penugasan/'.primaryOf($outside)->id, []);
    $this->assertOutOfScope404($actor, 'POST', '/penugasan/'.primaryOf($outside)->id.'/akhiri', ['end_date' => $this->today->toDateString()]);
});
