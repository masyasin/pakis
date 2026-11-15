<?php

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Notifications\SetPasswordLink;
use App\Domain\Access\Support\TemporaryPassword;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOrgStructure;

/*
 * Manajemen pengguna (T-020): FR-016, BR-014, BR-031, SEC-006, SEC-009.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->useRealPermissions();
});

/** Akun dengan role; MFA aktif agar role wajib MFA (BR-015) tidak tertahan di halaman aktivasi. */
function umActor(string ...$roles): User
{
    return User::factory()->withTwoFactor()->create()->assignRole($roles);
}

/** @param array<string, mixed> $overrides */
function umPayload(array $overrides = []): array
{
    return [
        'name' => 'Budi Santoso',
        'username' => 'budi.santoso',
        'email' => null,
        'phone' => '0812 3456 7890',
        'roles' => ['supervisor'],
        'is_active' => true,
        'password_mode' => 'generate',
        ...$overrides,
    ];
}

test('FR-016 BR-014 buat akun: password sementara tampil sekali, wajib ganti, role terpasang', function () {
    $owner = umActor('owner');

    $response = $this->actingAs($owner)->postJson('/pengguna', umPayload(['username' => '  Budi.Santoso ']));

    $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    $temporary = $response->json('temporary_password');
    $user = User::query()->where('username', 'budi.santoso')->firstOrFail();

    expect($temporary)->toMatch('/^['.TemporaryPassword::ALPHABET.']{12}$/')
        ->and(Hash::check($temporary, $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->phone)->toBe('081234567890')
        ->and($user->hasRole('supervisor'))->toBeTrue()
        ->and($user->created_by)->toBe($owner->id)
        ->and($response->json('show_url'))->toBe(route('admin.users.show', $user));

    $audit = AuditLog::query()->where('event', 'user.created')->where('auditable_id', $user->id)->firstOrFail();
    expect($audit->new_values)->toMatchArray(['username' => 'budi.santoso', 'roles' => ['supervisor'], 'initial_password' => 'sementara']);
});

test('SEC-006 password sementara tidak tersimpan di audit, security event, log, sesi, atau respons berikutnya', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.json_encode($e->context);
    });
    $owner = umActor('owner');
    $target = User::factory()->create()->assignRole('pegawai');

    $created = $this->actingAs($owner)->postJson('/pengguna', umPayload())->json('temporary_password');
    $reset = $this->actingAs($owner)->postJson("/pengguna/{$target->id}/reset-password", ['current_password' => 'password'])
        ->assertOk()->json('temporary_password');

    foreach ([$created, $reset] as $secret) {
        expect($secret)->toBeString()->not->toBe('')
            ->and(AuditLog::query()->get()->toJson())->not->toContain($secret)
            ->and(SecurityEvent::query()->get()->toJson())->not->toContain($secret)
            ->and(implode("\n", $logged))->not->toContain($secret)
            ->and(serialize(session()->all()))->not->toContain($secret);
    }

    $next = $this->actingAs($owner)->get('/pengguna');
    expect($next->getContent())->not->toContain($created)->not->toContain($reset);
});

test('FR-016 kirim tautan atur password: email wajib, tautan terkirim, email belum terverifikasi', function () {
    Notification::fake();
    $owner = umActor('owner');

    $this->actingAs($owner)->postJson('/pengguna', umPayload(['password_mode' => 'email_link']))
        ->assertUnprocessable()->assertJsonValidationErrors('email');

    $response = $this->actingAs($owner)->postJson('/pengguna', umPayload(['password_mode' => 'email_link', 'email' => 'Budi@Pakis-Demo.test']))
        ->assertCreated();

    $user = User::query()->where('username', 'budi.santoso')->firstOrFail();
    expect($response->json('temporary_password'))->toBeNull()
        ->and($user->email)->toBe('budi@pakis-demo.test')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->must_change_password)->toBeFalse();
    Notification::assertSentTo($user, SetPasswordLink::class);
});

test('BR-012 tautan atur password yang dipakai menandai email terverifikasi', function () {
    Notification::fake();
    $this->actingAs(umActor('owner'))->postJson('/pengguna', umPayload(['password_mode' => 'email_link', 'email' => 'budi@pakis-demo.test']));
    $user = User::query()->where('username', 'budi.santoso')->firstOrFail();

    $token = null;
    Notification::assertSentTo($user, SetPasswordLink::class, function (SetPasswordLink $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    auth()->logout();
    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'budi@pakis-demo.test',
        'password' => 'kebun-jeruk-pagi-hari',
        'password_confirmation' => 'kebun-jeruk-pagi-hari',
    ])->assertRedirect(route('login'));

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('04 §2.2 username unik, huruf kecil, format; pegawai tidak dikenal ditolak', function () {
    $owner = umActor('owner');
    User::factory()->create(['username' => 'budi.santoso']);

    $this->actingAs($owner)->postJson('/pengguna', umPayload(['username' => 'BUDI.SANTOSO']))
        ->assertUnprocessable()->assertJsonValidationErrors('username');
    $this->actingAs($owner)->postJson('/pengguna', umPayload(['username' => 'bu di']))
        ->assertUnprocessable()->assertJsonValidationErrors('username');
    $this->actingAs($owner)->postJson('/pengguna', umPayload(['username' => 'baru.lagi', 'employee_id' => 999999]))
        ->assertUnprocessable()->assertJsonValidationErrors('employee_id');
});

test('FR-016 tautkan pegawai (T-024): hanya pegawai aktif yang belum punya akun, tercatat audit', function () {
    $owner = umActor('owner');
    $employee = Employee::factory()->create();
    $taken = Employee::factory()->create();
    User::factory()->create(['employee_id' => $taken->id]);
    $inactive = Employee::factory()->inactive()->create();

    $this->actingAs($owner)->postJson('/pengguna', umPayload(['employee_id' => $taken->id]))
        ->assertUnprocessable()->assertJsonValidationErrors(['employee_id' => 'sudah punya akun']);
    $this->actingAs($owner)->postJson('/pengguna', umPayload(['employee_id' => $inactive->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('employee_id');

    $id = $this->actingAs($owner)->postJson('/pengguna', umPayload(['employee_id' => $employee->id]))->assertCreated()->json('id');
    $user = User::query()->findOrFail($id);

    expect($user->employee_id)->toBe($employee->id)
        ->and(AuditLog::query()->where('event', 'user.created')->where('auditable_id', $id)->value('new_values')['employee_id'])->toBe($employee->id);

    // Melepas tautan lewat ubah akun.
    $this->actingAs($owner)->put("/pengguna/{$id}", [...umPayload(['username' => $user->username]), 'employee_id' => null])->assertRedirect();
    expect($user->fresh()->employee_id)->toBeNull();
});

test('BR-032 pelaku ber-scope sempit tidak bisa menautkan pegawai (cegah pelebaran scope akun)', function () {
    $org = FakeOrgStructure::bind();
    $org->employee(employeeRow(1))->at(10);
    $org->employee(employeeRow(2))->at(20);
    Role::query()->create(['name' => 'admin_lokasi', 'label' => 'Admin lokasi', 'guard_name' => 'web', 'data_scope' => DataScope::Location])
        ->givePermissionTo(['users.view', 'users.create']);
    $actor = User::factory()->withTwoFactor()->create(['employee_id' => employeeRow(1)])->assignRole('admin_lokasi');

    // Pegawai #3 (dalam scope pelaku) pun ditolak: pegawai bisa saja mengepalai lokasi lain.
    $org->employee(employeeRow(3))->at(10);
    foreach ([3, 2] as $id) {
        $this->actingAs($actor)->postJson('/pengguna', umPayload(['roles' => [], 'employee_id' => $id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_id' => 'Hanya pengguna dengan cakupan data "Semua"']);
    }
});

test('BR-010 tautan ke pegawai nonaktif tidak bisa dilepas/diganti; ubah tanpa field employee_id tidak melepas tautan', function () {
    $owner = umActor('owner');
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['username' => 'pegawai.keluar', 'employee_id' => $employee->id]);
    $payload = umPayload(['username' => 'pegawai.keluar', 'name' => 'Pegawai Keluar']);

    // Tanpa field employee_id: tautan tetap.
    $this->actingAs($owner)->put("/pengguna/{$user->id}", $payload)->assertRedirect();
    expect($user->fresh()->employee_id)->toBe($employee->id);

    $employee->forceFill(['status' => 'inactive', 'exit_date' => now()->toDateString()])->save();
    $other = Employee::factory()->create();

    foreach ([null, $other->id] as $value) {
        $this->actingAs($owner)->from("/pengguna/{$user->id}/ubah")->put("/pengguna/{$user->id}", [...$payload, 'employee_id' => $value])
            ->assertSessionHasErrors('employee_id');
        expect($user->fresh()->employee_id)->toBe($employee->id);
    }

    // Nilai sama (tautan dipertahankan) tetap boleh walau pegawainya nonaktif.
    $this->actingAs($owner)->put("/pengguna/{$user->id}", [...$payload, 'employee_id' => $employee->id])->assertSessionHasNoErrors();
});

test('BR-031 batas delegasi: HRD tidak bisa menetapkan role Administrator atau Owner', function () {
    $hrd = umActor('hrd');

    foreach (['administrator', 'owner', 'super_admin'] as $role) {
        $this->actingAs($hrd)->postJson('/pengguna', umPayload(['username' => "akun.{$role}", 'roles' => [$role]]))
            ->assertUnprocessable()->assertJsonValidationErrors('roles');
    }

    // Role yang permission-nya dimiliki HRD (supervisor) boleh.
    $this->actingAs($hrd)->postJson('/pengguna', umPayload())->assertCreated();
    expect(User::query()->where('username', 'like', 'akun.%')->exists())->toBeFalse();
});

test('SEC-009 role Owner & Super Admin hanya bisa ditetapkan Owner', function () {
    $vendor = umActor('super_admin');
    $this->actingAs($vendor)->postJson('/pengguna', umPayload(['roles' => ['owner']]))
        ->assertUnprocessable()->assertJsonValidationErrors('roles');

    $this->actingAs(umActor('owner'))->postJson('/pengguna', umPayload(['roles' => ['owner']]))->assertCreated();
});

test('FR-016 ubah akun: data & role, email berubah → belum terverifikasi, audit + role_changed', function () {
    $owner = umActor('owner');
    $target = User::factory()->create(['email' => 'lama@pakis-demo.test'])->assignRole('pegawai');

    $this->actingAs($owner)->put("/pengguna/{$target->id}", [
        'name' => 'Nama Baru',
        'username' => $target->username,
        'email' => 'baru@pakis-demo.test',
        'phone' => null,
        'roles' => ['pegawai', 'supervisor'],
    ])->assertRedirect(route('admin.users.show', $target));

    $target->refresh();
    expect($target->name)->toBe('Nama Baru')
        ->and($target->email_verified_at)->toBeNull()
        ->and($target->getRoleNames()->sort()->values()->all())->toBe(['pegawai', 'supervisor']);

    $audit = AuditLog::query()->where('event', 'user.updated')->where('auditable_id', $target->id)->firstOrFail();
    expect($audit->old_values['roles'])->toBe(['pegawai'])
        ->and($audit->new_values['roles'])->toBe(['pegawai', 'supervisor']);
    expect(SecurityEvent::query()->where('event', 'role_changed')->where('user_id', $target->id)->exists())->toBeTrue();
});

test('BR-031 pengguna tidak bisa mengubah akun & daftar role miliknya sendiri lewat menu Pengguna', function () {
    $admin = umActor('administrator');

    $this->actingAs($admin)->get("/pengguna/{$admin->id}/ubah")->assertForbidden();
    $this->actingAs($admin)->put("/pengguna/{$admin->id}", [
        'name' => $admin->name, 'username' => $admin->username, 'roles' => ['administrator', 'owner'],
    ])->assertForbidden();

    expect($admin->fresh()->hasRole('owner'))->toBeFalse();
});

test('Batas hak target: Administrator tidak bisa mengubah akun HRD, HRD tidak bisa mengubah akun Administrator', function () {
    $admin = umActor('administrator');
    $hrd = umActor('hrd');

    $this->actingAs($admin)->get("/pengguna/{$hrd->id}/ubah")->assertForbidden();
    $this->actingAs($hrd)->get("/pengguna/{$admin->id}/ubah")->assertForbidden();
    // Melihat tetap boleh; tombol aksi disembunyikan.
    $this->actingAs($admin)->get("/pengguna/{$hrd->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/show')
            ->where('protectedReason', 'beyond')
            ->where('can.update', false)
            ->where('can.resetPassword', false));
});

test('FR-016 detail pengguna: tab riwayat memuat audit akun dengan filter event dari allowlist', function () {
    $owner = umActor('owner');
    $this->actingAs($owner)->postJson('/pengguna', umPayload());
    $user = User::query()->where('username', 'budi.santoso')->firstOrFail();
    $this->actingAs($owner)->patch("/pengguna/{$user->id}/status", ['active' => false]);

    $this->actingAs($owner)->get("/pengguna/{$user->id}?tab=riwayat")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'riwayat')
            ->where('history.pagination.total', 2)
            ->where('history.rows.0.event', 'user.deactivated'));

    $this->actingAs($owner)->get("/pengguna/{$user->id}?tab=riwayat&event=user.created")
        ->assertInertia(fn (Assert $page) => $page->where('history.pagination.total', 1));
    // Event di luar allowlist diabaikan (bukan where dari input mentah).
    $this->actingAs($owner)->get("/pengguna/{$user->id}?tab=riwayat&event=role.created")
        ->assertInertia(fn (Assert $page) => $page->where('history.event', null)->where('history.pagination.total', 2));
});
