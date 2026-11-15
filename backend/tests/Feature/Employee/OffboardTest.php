<?php

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Device\Support\DeviceTokens;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Events\EmployeeOffboarded;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\EmployeeWorld;

uses(AssertsIdor::class);

beforeEach(function () {
    $this->world = EmployeeWorld::make($this);
    $this->hrd = $this->world->actor('hrd');
    $this->today = DisplayTime::toLocal(now())->toImmutable()->startOfDay();
    $this->resign = (int) ReferenceItem::query()->forGroup(ReferenceGroup::OffboardingReason)->where('code', 'resign')->value('id');
});

/** @return array<string, mixed> */
function offboardInput(Employee $employee, array $override = []): array
{
    return [
        'exit_date' => test()->today->toDateString(),
        'exit_reason_id' => test()->resign,
        'exit_note' => 'Surat pengunduran diri',
        'confirm_name' => strtoupper($employee->full_name),
        'current_password' => 'password',
        ...$override,
    ];
}

function phoneOf(User $user, string $status): Device
{
    $device = (new Device)->forceFill([
        'user_id' => $user->id,
        'device_uid' => hash('sha256', 'hp-'.fake()->unique()->uuid()),
        'model' => 'Android Uji',
        'os_version' => 'Android 14',
        'app_version' => '1.0.0',
        'status' => $status,
    ]);
    $device->save();

    return $device;
}

test('SEC-021 BR-053 nonaktifkan: login web gagal, token API 401, HP dicabut/ditolak, kepala lokasi kosong, penugasan ditutup', function () {
    Event::fake([EmployeeOffboarded::class]);
    $employee = $this->world->employee($this->world->gudang);
    $this->world->gudang->update(['head_employee_id' => $employee->id]);
    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/penugasan", [
        'is_primary' => false, 'position_id' => $this->world->staff->id, 'location_id' => $this->world->toko->id,
        'start_date' => $this->today->toDateString(),
    ])->assertSessionHasNoErrors();

    $user = User::factory()->create(['username' => 'pegawai.keluar', 'employee_id' => $employee->id])->assignRole('pegawai');
    $active = phoneOf($user, 'active');
    $pending = phoneOf($user, 'pending');
    $token = app(DeviceTokens::class)->issue($user, $active)->plainTextToken;
    DB::table('sessions')->insert(['id' => 'sesi-uji', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/nonaktifkan", offboardInput($employee))
        ->assertRedirect("/pegawai/{$employee->id}")->assertSessionHasNoErrors();
    app('auth')->forgetGuards();

    $employee->refresh();
    expect($employee->status)->toBe(EmployeeStatus::Inactive)
        ->and($employee->exit_date?->toDateString())->toBe($this->today->toDateString())
        ->and($employee->exit_reason_id)->toBe($this->resign)
        ->and($user->fresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($active->fresh()->status)->toBe(DeviceStatus::Revoked)
        ->and($pending->fresh()->status)->toBe(DeviceStatus::Rejected)
        ->and($this->world->gudang->fresh()->head_employee_id)->toBeNull()
        ->and(Assignment::query()->where('employee_id', $employee->id)->whereNull('end_date')->exists())->toBeFalse()
        ->and(Assignment::query()->where('employee_id', $employee->id)->pluck('end_date')->map->toDateString()->unique()->all())->toBe([$this->today->toDateString()])
        ->and(AuditLog::query()->where('event', 'employee.offboarded')->where('auditable_id', $employee->id)->exists())->toBeTrue();

    // Login web → gagal (pesan generik); token API → 401. Web dulu: request API mengganti guard default.
    $this->post('/login', ['login' => 'pegawai.keluar', 'password' => 'password'])->assertSessionHasErrors();
    $this->assertGuest();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/me', ['X-Device-Id' => $active->device_uid, 'X-App-Version' => '1.0.0', 'Authorization' => "Bearer {$token}"])->assertUnauthorized();

    Event::assertDispatched(EmployeeOffboarded::class, fn (EmployeeOffboarded $e) => $e->employeeId === $employee->id);
    // Data pegawai TIDAK hilang.
    expect(Employee::query()->find($employee->id))->not->toBeNull();
});

test('BR-053 gagal di tengah → rollback penuh, tidak ada perubahan parsial', function () {
    $employee = $this->world->employee($this->world->gudang);
    $user = User::factory()->create(['employee_id' => $employee->id])->assignRole('pegawai');
    $device = phoneOf($user, 'active');
    $this->world->gudang->update(['head_employee_id' => $employee->id]);

    // Gagal di langkah TERAKHIR (mengosongkan kepala lokasi): semua langkah sebelumnya harus batal.
    DB::listen(function ($query) {
        if (str_starts_with($query->sql, 'update `locations`')) {
            throw new RuntimeException('Gagal paksa');
        }
    });
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/nonaktifkan", offboardInput($employee)))
        ->toThrow(RuntimeException::class, 'Gagal paksa');

    expect($employee->fresh()->status)->toBe(EmployeeStatus::Active)
        ->and($user->fresh()->is_active)->toBeTrue()
        ->and($device->fresh()->status)->toBe(DeviceStatus::Active)
        ->and(Assignment::query()->where('employee_id', $employee->id)->whereNull('end_date')->exists())->toBeTrue()
        ->and($this->world->gudang->fresh()->head_employee_id)->toBe($employee->id)
        ->and(AuditLog::query()->where('event', 'employee.offboarded')->exists())->toBeFalse();
});

test('BR-053 penugasan terjadwal setelah tanggal keluar dihapus (belum pernah berlaku)', function () {
    $employee = $this->world->employee($this->world->gudang);
    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/penugasan", [
        'is_primary' => true, 'position_id' => $this->world->head->id, 'location_id' => $this->world->toko->id,
        'start_date' => $this->today->addDays(5)->toDateString(),
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/nonaktifkan", offboardInput($employee))->assertSessionHasNoErrors();

    expect(Assignment::query()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and(Assignment::query()->where('employee_id', $employee->id)->value('end_date')?->toDateString())->toBe($this->today->toDateString());
});

test('BR-053 validasi: nama salah, password salah, tanggal di masa depan / sebelum masuk, alasan nonaktif, sudah nonaktif', function () {
    $employee = $this->world->employee(attributes: ['join_date' => $this->today->subDays(30)->toDateString()]);
    $url = "/pegawai/{$employee->id}/nonaktifkan";

    $this->actingAs($this->hrd)->post($url, offboardInput($employee, ['confirm_name' => 'Orang Lain']))->assertSessionHasErrors('confirm_name');
    $this->actingAs($this->hrd)->post($url, offboardInput($employee, ['current_password' => 'salah-sekali']))->assertSessionHasErrors('current_password');
    $this->actingAs($this->hrd)->post($url, offboardInput($employee, ['exit_date' => $this->today->addDay()->toDateString()]))->assertSessionHasErrors('exit_date');
    $this->actingAs($this->hrd)->post($url, offboardInput($employee, ['exit_date' => $this->today->subDays(31)->toDateString()]))->assertSessionHasErrors('exit_date');

    ReferenceItem::query()->whereKey($this->resign)->update(['is_active' => false]);
    $this->actingAs($this->hrd)->post($url, offboardInput($employee))->assertSessionHasErrors('exit_reason_id');

    expect($employee->fresh()->status)->toBe(EmployeeStatus::Active);

    $gone = Employee::factory()->inactive()->create();
    $this->actingAs($this->hrd)->post("/pegawai/{$gone->id}/nonaktifkan", offboardInput($gone, ['exit_reason_id' => ReferenceItem::query()->forGroup(ReferenceGroup::OffboardingReason)->where('is_active', true)->value('id')]))
        ->assertSessionHasErrors('exit_date');
});

test('07 §4 ringkasan dampak & bawahan tersedia untuk dialog; pegawai nonaktif menampilkan data keluar', function () {
    $boss = $this->world->employee($this->world->gudang, attributes: ['full_name' => 'Atasan Keluar']);
    $this->world->employee($this->world->gudang, $boss, ['full_name' => 'Bawahan Satu']);
    $this->world->gudang->update(['head_employee_id' => $boss->id]);
    $user = User::factory()->create(['employee_id' => $boss->id]);
    phoneOf($user, 'active');

    $this->actingAs($this->hrd)->get("/pegawai/{$boss->id}")
        ->assertInertia(fn (Assert $page) => $page->where('can.offboard', true)
            ->where('can.rehire', false)
            ->where('offboardImpact.devices_active', 1)
            ->where('offboardImpact.headed_locations.0', 'Gudang Uji')
            ->where('offboardImpact.subordinates.0.name', 'Bawahan Satu')
            ->has('offboardingReasons'));

    $this->actingAs($this->hrd)->post("/pegawai/{$boss->id}/nonaktifkan", offboardInput($boss))->assertSessionHasNoErrors();

    $this->actingAs($this->hrd)->get("/pegawai/{$boss->id}")
        ->assertInertia(fn (Assert $page) => $page->where('can.offboard', false)
            ->where('can.rehire', true)
            ->where('offboardImpact', null)
            ->where('exit.reason', 'Mengundurkan diri'));

    // Daftar pegawai: default hanya aktif; yang keluar tetap ada di status Nonaktif.
    $this->actingAs($this->hrd)->get('/pegawai?filter[status]=inactive')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.full_name', 'Atasan Keluar'));
});

test('06 §7.3 aktifkan kembali: wajib penugasan baru; tanggal masuk diganti; akun aktif bila dicentang', function () {
    $employee = $this->world->employee(attributes: ['join_date' => '2020-01-01']);
    $user = User::factory()->create(['employee_id' => $employee->id])->assignRole('pegawai');
    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/nonaktifkan", offboardInput($employee, ['exit_date' => $this->today->subDays(10)->toDateString()]))->assertSessionHasNoErrors();

    $url = "/pegawai/{$employee->id}/aktifkan";
    $base = ['rehire_date' => $this->today->toDateString(), 'current_password' => 'password', 'activate_account' => true];

    // Tanpa penugasan → ditolak.
    $this->actingAs($this->hrd)->post($url, $base)->assertSessionHasErrors(['position_id', 'location_id']);
    // Tanggal ≤ tanggal keluar → ditolak.
    $this->actingAs($this->hrd)->post($url, [...$base, 'rehire_date' => $this->today->subDays(10)->toDateString(),
        'position_id' => $this->world->staff->id, 'location_id' => $this->world->toko->id])->assertSessionHasErrors('rehire_date');

    $this->actingAs($this->hrd)->post($url, [...$base, 'position_id' => $this->world->staff->id, 'location_id' => $this->world->toko->id])
        ->assertRedirect("/pegawai/{$employee->id}?tab=penugasan")->assertSessionHasNoErrors();

    $employee->refresh();
    expect($employee->status)->toBe(EmployeeStatus::Active)
        ->and($employee->join_date->toDateString())->toBe($this->today->toDateString())
        ->and($employee->exit_date)->toBeNull()
        ->and($employee->exit_reason_id)->toBeNull()
        ->and($employee->primaryAssignment()->first()?->location_id)->toBe($this->world->toko->id)
        ->and($user->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('event', 'employee.rehired')->value('old_values')['join_date'])->toBe('2020-01-01');
});

test('06 §7.3 aktifkan kembali tanpa centang akun → akun tetap nonaktif', function () {
    $employee = $this->world->employee();
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/nonaktifkan", offboardInput($employee, ['exit_date' => $this->today->subDays(3)->toDateString()]));

    $this->actingAs($this->hrd)->post("/pegawai/{$employee->id}/aktifkan", [
        'rehire_date' => $this->today->toDateString(), 'current_password' => 'password', 'activate_account' => false,
        'position_id' => $this->world->staff->id, 'location_id' => $this->world->gudang->id,
    ])->assertSessionHasNoErrors();

    expect($employee->fresh()->status)->toBe(EmployeeStatus::Active)
        ->and($user->fresh()->is_active)->toBeFalse();
});

test('06 §4 kepala lokasi & supervisor tidak bisa menonaktifkan/mengaktifkan (403); IDOR → 404', function (string $role) {
    $self = $this->world->employee($this->world->gudang);
    $mate = $this->world->employee($this->world->gudang, $self);
    $actor = $this->world->actor($role, $self);

    $this->actingAs($actor)->post("/pegawai/{$mate->id}/nonaktifkan", offboardInput($mate))->assertForbidden();
    $this->actingAs($actor)->post("/pegawai/{$mate->id}/aktifkan", [])->assertForbidden();
})->with(['kepala_lokasi', 'supervisor']);

test('SEC-033 IDOR: nonaktifkan/aktifkan pegawai di luar scope → 404', function () {
    $self = $this->world->employee($this->world->gudang);
    $outside = $this->world->employee($this->world->toko);
    $role = Role::query()->create(['name' => 'hrd_lokasi', 'label' => 'HRD Lokasi', 'guard_name' => 'web', 'data_scope' => DataScope::Location]);
    $role->givePermissionTo(['employees.view', 'employees.offboard']);
    $actor = User::factory()->withTwoFactor()->create(['employee_id' => $self->id])->assignRole('hrd_lokasi');

    $this->assertOutOfScope404($actor, 'POST', "/pegawai/{$outside->id}/nonaktifkan", offboardInput($outside));
    $this->assertOutOfScope404($actor, 'POST', "/pegawai/{$outside->id}/aktifkan", []);
});
