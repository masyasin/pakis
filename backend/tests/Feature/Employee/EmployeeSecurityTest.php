<?php

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Support\EmployeeStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Employee\Listings\EmployeeListing;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\NikCipher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\EmployeeWorld;

uses(AssertsIdor::class);

beforeEach(function () {
    $this->world = EmployeeWorld::make($this);
    $this->hrd = $this->world->actor('hrd');
});

test('SEC-010 dump tabel employees tidak memuat NIK plaintext; audit NIK = ***', function () {
    $nik = EmployeeWorld::nik();
    $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload(['nik' => $nik]))->assertCreated();

    // NIK asli tidak muncul di kolom mana pun; pola 16 digit hanya di kolom plaintext, karena hash
    // hex/ciphertext kadang kebetulan memuat 16 digit beruntun (test goyah).
    $dump = json_encode(DB::table('employees')->get()->all(), JSON_THROW_ON_ERROR);
    $plain = json_encode(DB::table('employees')->get()->map(fn ($row) => array_diff_key((array) $row, array_flip(['nik_enc', 'nik_hash'])))->all(), JSON_THROW_ON_ERROR);
    expect($dump)->not->toContain($nik)
        ->and(preg_match('/(?<!\d)\d{16}(?!\d)/', $plain))->toBe(0);

    $employee = Employee::query()->sole();
    expect($employee->nik_hash)->toBe(app(NikCipher::class)->hash($nik))
        ->and(app(NikCipher::class)->decrypt((string) $employee->nik_enc))->toBe($nik);

    $audit = AuditLog::query()->where('event', 'employee.created')->sole();
    expect($audit->new_values['nik'])->toBe('***')
        ->and(json_encode([$audit->old_values, $audit->new_values]))->not->toContain($nik);

    // Ganti NIK: audit tetap *** di kedua sisi.
    $this->actingAs($this->hrd)->put("/pegawai/{$employee->id}", [...$this->world->payload(), 'nik' => EmployeeWorld::nik()])->assertRedirect();
    $update = AuditLog::query()->where('event', 'employee.updated')->sole();
    expect($update->old_values['nik'])->toBe('***')->and($update->new_values['nik'])->toBe('***');
});

test('SEC-010 respons detail, form, daftar, dan lookup hanya memuat NIK termasking', function () {
    $nik = EmployeeWorld::nik();
    $employee = Employee::factory()->withNik($nik)->assignedTo($this->world->gudang, $this->world->staff)->create();
    $masked = substr($nik, 0, 4).'********'.substr($nik, -4);

    foreach (["/pegawai/{$employee->id}", "/pegawai/{$employee->id}/ubah", '/pegawai', '/lookup/employees'] as $uri) {
        $content = (string) $this->actingAs($this->hrd)->get($uri)->assertOk()->getContent();
        expect($content)->not->toContain($nik, "NIK lengkap bocor di {$uri}");
    }

    $this->actingAs($this->hrd)->get("/pegawai/{$employee->id}")
        ->assertInertia(fn (Assert $page) => $page->where('employee.nik_masked', $masked));
});

test('SEC-010 reveal-nik tanpa employees.view_sensitive → 403', function () {
    $employee = Employee::factory()->withNik()->assignedTo($this->world->gudang, $this->world->staff)->create();
    $admin = $this->world->actor('administrator');

    $this->actingAs($admin)->postJson("/pegawai/{$employee->id}/nik")->assertForbidden();

    expect(SecurityEvent::query()->where('event', 'sensitive_data_viewed')->exists())->toBeFalse();
});

test('SEC-010 reveal-nik dengan izin → NIK lengkap, no-store, tercatat sensitive_data_viewed + audit', function () {
    $nik = EmployeeWorld::nik();
    $employee = Employee::factory()->withNik($nik)->assignedTo($this->world->gudang, $this->world->staff)->create();

    $this->actingAs($this->hrd)->postJson("/pegawai/{$employee->id}/nik")
        ->assertOk()
        ->assertExactJson(['nik' => $nik])
        ->assertHeader('Cache-Control', 'no-store, private');

    $event = SecurityEvent::query()->where('event', 'sensitive_data_viewed')->sole();
    expect($event->user_id)->toBe($this->hrd->id)
        ->and(json_encode($event->toArray()))->not->toContain($nik)
        ->and(AuditLog::query()->where('event', 'employee.nik_viewed')->where('auditable_id', $employee->id)->exists())->toBeTrue();
});

test('SEC-010 ekspor pegawai: NIK hanya termasking', function () {
    $nik = EmployeeWorld::nik();
    $employee = Employee::factory()->withNik($nik)->create();
    $columns = (new EmployeeListing)->exportColumns();

    $row = $columns->values($employee);
    expect(json_encode($row))->not->toContain($nik)
        ->and(in_array(substr($nik, 0, 4).'********'.substr($nik, -4), $row, true))->toBeTrue();
});

test('SEC-033 IDOR: show, ubah, hapus, buka NIK, foto, buat akun pegawai lokasi lain → 404', function () {
    $self = $this->world->employee($this->world->gudang);
    $other = Employee::factory()->withNik()->assignedTo($this->world->toko, $this->world->staff)->create();

    // Role uji ber-scope lokasi dengan semua izin pegawai: yang menghalangi hanya scope.
    $role = Role::query()->create(['name' => 'hrd_lokasi', 'label' => 'HRD Lokasi', 'guard_name' => 'web', 'data_scope' => DataScope::Location]);
    $role->givePermissionTo(['employees.view', 'employees.update', 'employees.delete', 'employees.view_sensitive', 'users.create']);
    $actor = User::factory()->withTwoFactor()->create(['employee_id' => $self->id])->assignRole('hrd_lokasi');

    $this->assertOutOfScope404($actor, 'GET', "/pegawai/{$other->id}");
    $this->assertOutOfScope404($actor, 'GET', "/pegawai/{$other->id}/ubah");
    $this->assertOutOfScope404($actor, 'PUT', "/pegawai/{$other->id}", $this->world->payload());
    $this->assertOutOfScope404($actor, 'DELETE', "/pegawai/{$other->id}");
    $this->assertOutOfScope404($actor, 'POST', "/pegawai/{$other->id}/nik");
    $this->assertOutOfScope404($actor, 'GET', "/pegawai/{$other->id}/foto");
    $this->assertOutOfScope404($actor, 'POST', "/pegawai/{$other->id}/akun", ['username' => 'luar.scope', 'password_mode' => 'generate']);

    // Pegawai di lokasinya sendiri tetap bisa dibuka.
    $mate = $this->world->employee($this->world->gudang);
    app()->forgetScopedInstances(); // memo DataScope dari request sebelumnya
    $this->actingAs($actor)->get("/pegawai/{$mate->id}")->assertOk();
});

test('SEC-033 penugasan pertama: lokasi & atasan di luar scope pembuat dianggap tidak ditemukan', function () {
    $self = $this->world->employee($this->world->gudang);
    $outside = $this->world->employee($this->world->toko);
    $role = Role::query()->create(['name' => 'hrd_lokasi', 'label' => 'HRD Lokasi', 'guard_name' => 'web', 'data_scope' => DataScope::Location]);
    $role->givePermissionTo(['employees.view', 'employees.create']);
    $actor = User::factory()->withTwoFactor()->create(['employee_id' => $self->id])->assignRole('hrd_lokasi');

    $this->actingAs($actor)->postJson('/pegawai', $this->world->payload(['assignment' => [
        'location_id' => $this->world->toko->id,
        'supervisor_employee_id' => $outside->id,
    ]]))->assertUnprocessable()->assertJsonValidationErrors([
        'assignment.location_id' => 'Lokasi tidak ditemukan.',
        'assignment.supervisor_employee_id' => 'Atasan tidak ditemukan.',
    ]);
});

test('BR-010 login ditolak bila pegawai terkait nonaktif atau terhapus (fail-closed)', function () {
    $employee = $this->world->employee();
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $status = app(EmployeeStatus::class);

    expect($status->isActive($user))->toBeTrue();

    $employee->forceFill(['status' => 'inactive', 'exit_date' => now()->toDateString()])->save();
    expect($status->isActive($user->fresh()))->toBeFalse();

    $employee->forceFill(['status' => 'active', 'exit_date' => null])->save();
    $employee->delete();
    expect($status->isActive($user->fresh()))->toBeFalse();
});
