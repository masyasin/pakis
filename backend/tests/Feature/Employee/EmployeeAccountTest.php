<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\UsernameSuggester;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EmployeeWorld;

beforeEach(function () {
    $this->world = EmployeeWorld::make($this);
    $this->hrd = $this->world->actor('hrd');
});

test('FR-016 buat akun dari pegawai: data dari pegawai, password sementara sekali tampil, no-store', function () {
    $employee = $this->world->employee(attributes: ['full_name' => 'Dewi Lestari', 'phone' => '6281299990000', 'email' => 'dewi@contoh.test']);

    $response = $this->actingAs($this->hrd)->postJson("/pegawai/{$employee->id}/akun", [
        'username' => 'Dewi.Lestari', 'roles' => ['pegawai'], 'password_mode' => 'generate',
    ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private');

    $user = User::query()->where('employee_id', $employee->id)->sole();
    expect($response->json('temporary_password'))->toBeString()
        ->and($user->username)->toBe('dewi.lestari')
        ->and($user->name)->toBe('Dewi Lestari')
        ->and($user->email)->toBe('dewi@contoh.test')
        ->and($user->phone)->toBe('6281299990000')
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->hasRole('pegawai'))->toBeTrue();

    $audit = AuditLog::query()->where('event', 'user.created')->sole();
    expect($audit->new_values['employee_id'])->toBe($employee->id)
        ->and(json_encode($audit->new_values))->not->toContain((string) $response->json('temporary_password'));
});

test('FR-016 tambah pegawai sekaligus buat akun (form gabungan)', function () {
    $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload([
        'account' => ['create' => true, 'username' => 'budi.santoso', 'roles' => ['pegawai'], 'password_mode' => 'generate'],
    ]))->assertCreated()->assertJsonPath('username', 'budi.santoso');

    expect(User::query()->where('username', 'budi.santoso')->value('employee_id'))->toBe(Employee::query()->sole()->id);
});

test('FR-016 akun gagal (username dipakai) → pegawai juga tidak tersimpan', function () {
    User::factory()->create(['username' => 'budi.santoso']);

    $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload([
        'account' => ['create' => true, 'username' => 'budi.santoso', 'roles' => ['pegawai'], 'password_mode' => 'generate'],
    ]))->assertUnprocessable()->assertJsonValidationErrors('account.username');

    expect(Employee::query()->count())->toBe(0);
});

test('FR-016 pegawai yang sudah punya akun (termasuk akun terhapus) tidak bisa dibuatkan lagi', function () {
    $employee = $this->world->employee();
    User::factory()->create(['employee_id' => $employee->id])->delete();

    $this->actingAs($this->hrd)->postJson("/pegawai/{$employee->id}/akun", [
        'username' => 'akun.kedua', 'roles' => ['pegawai'], 'password_mode' => 'generate',
    ])->assertUnprocessable()->assertJsonValidationErrors(['username' => 'Pegawai ini sudah punya akun.']);

    $this->actingAs($this->hrd)->get("/pegawai/{$employee->id}?tab=akun")
        ->assertInertia(fn (Assert $page) => $page->where('can.createAccount', false));
});

test('FR-016 tanpa users.create → 403; batas delegasi role tetap berlaku (BR-031)', function () {
    $employee = $this->world->employee();
    $kepala = $this->world->actor('kepala_lokasi', $this->world->employee());

    $this->actingAs($kepala)->postJson("/pegawai/{$employee->id}/akun", [
        'username' => 'coba.saja', 'password_mode' => 'generate',
    ])->assertForbidden();

    // HRD tidak bisa memberi role Owner lewat jalur ini.
    $this->actingAs($this->hrd)->postJson("/pegawai/{$employee->id}/akun", [
        'username' => 'calon.owner', 'roles' => ['owner'], 'password_mode' => 'generate',
    ])->assertUnprocessable()->assertJsonValidationErrors('roles');
    expect(User::query()->where('username', 'calon.owner')->exists())->toBeFalse();
});

test('FR-016 tautan atur password butuh email pegawai', function () {
    $employee = $this->world->employee(attributes: ['email' => null]);

    $this->actingAs($this->hrd)->postJson("/pegawai/{$employee->id}/akun", [
        'username' => 'tanpa.email', 'roles' => ['pegawai'], 'password_mode' => 'email_link',
    ])->assertUnprocessable()->assertJsonValidationErrors('password_mode');
});

test('FR-016 saran username dari nama: huruf kecil, unik dengan akhiran angka', function () {
    expect(UsernameSuggester::base('  Siti   Nur Aisyah '))->toBe('siti.nur')
        ->and(UsernameSuggester::base('Ñoño Ádi'))->toBe('nono.adi')
        ->and(UsernameSuggester::base('Al'))->toBe('al.pegawai')
        ->and(UsernameSuggester::base('?!'))->toBe('pegawai');

    User::factory()->create(['username' => 'siti.nur']);
    User::factory()->create(['username' => 'siti.nur2'])->delete();

    expect(UsernameSuggester::suggest('Siti Nur Aisyah'))->toBe('siti.nur3')
        ->and(preg_match('/^[a-z0-9._-]{3,50}$/', UsernameSuggester::suggest(str_repeat('Panjang', 20).' Sekali')))->toBe(1);
});
