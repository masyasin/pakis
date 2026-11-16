<?php

use App\Domain\Access\Support\TemporaryPassword;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Aksi atas akun lain (T-020): reset password (FR-007, BR-014, SEC-006), reset MFA (BR-015),
 * nonaktif/aktif & cabut sesi (FR-016), perlindungan Owner dari Super Admin (SEC-009).
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->useRealPermissions();
});

function uaActor(string ...$roles): User
{
    return User::factory()->withTwoFactor()->create()->assignRole($roles);
}

function uaSession(User $user, string $id): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
}

function uaSessions(User $user): int
{
    return DB::table('sessions')->where('user_id', $user->id)->count();
}

test('BR-014 reset password admin: 12 karakter tanpa karakter ambigu, wajib ganti, sesi dicabut, tercatat', function () {
    $owner = uaActor('owner');
    $target = uaActor('hrd');
    $target->forceFill(['locked_until' => now()->addHour()])->save();
    uaSession($target, str_repeat('a', 40));
    uaSession($target, str_repeat('b', 40));

    $response = $this->actingAs($owner)
        ->postJson("/pengguna/{$target->id}/reset-password", ['current_password' => 'password'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    $temporary = $response->json('temporary_password');
    $target->refresh();

    expect(strlen($temporary))->toBe(12)
        ->and($temporary)->not->toMatch('/[0O1lI]/')
        ->and(Hash::check($temporary, $target->password))->toBeTrue()
        ->and($target->must_change_password)->toBeTrue()
        ->and($target->locked_until)->toBeNull()
        ->and(uaSessions($target))->toBe(0)
        ->and(SecurityEvent::query()->where('event', 'password_reset_by_admin')->where('user_id', $target->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'user.password_reset')->where('auditable_id', $target->id)->exists())->toBeTrue();
});

test('BR-014 alfabet password sementara tanpa 0 O 1 l I', function () {
    expect(TemporaryPassword::ALPHABET)->not->toMatch('/[0O1lI]/');
    foreach (range(1, 50) as $_) {
        expect(TemporaryPassword::make())->toMatch('/^['.TemporaryPassword::ALPHABET.']{12,}$/');
    }
});

test('SEC-006 setelah reset admin, pengguna dipaksa ke halaman ganti password', function () {
    $target = User::factory()->create()->assignRole('supervisor');
    $this->actingAs(uaActor('owner'))->postJson("/pengguna/{$target->id}/reset-password", ['current_password' => 'password'])->assertOk();

    // Sesi test terbawa antar-aktor; mulai sesi baru seperti login dari peramban lain.
    auth()->logout();
    $this->flushSession();
    $this->actingAs($target->fresh())->get('/dasbor')->assertRedirect(route('admin.password.force'));
});

test('FR-007 reset password butuh password admin yang benar', function () {
    $owner = uaActor('owner');
    $target = User::factory()->create()->assignRole('supervisor');
    $before = $target->password;

    $this->actingAs($owner)->postJson("/pengguna/{$target->id}/reset-password", ['current_password' => 'salah-sekali'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->actingAs($owner)->postJson("/pengguna/{$target->id}/reset-password", [])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');

    expect($target->fresh()->password)->toBe($before);
});

test('BR-014 Administrator & Super Admin tidak bisa mereset password Owner; Owner bisa mereset Owner lain', function () {
    $owner = uaActor('owner');

    foreach (['administrator', 'super_admin'] as $role) {
        $this->actingAs(uaActor($role))->postJson("/pengguna/{$owner->id}/reset-password", ['current_password' => 'password'])
            ->assertForbidden();
    }

    $this->actingAs(uaActor('owner'))->postJson("/pengguna/{$owner->id}/reset-password", ['current_password' => 'password'])
        ->assertOk();
});

test('BR-014 admin tidak bisa mereset password dirinya sendiri lewat menu Pengguna', function () {
    $admin = uaActor('administrator');

    $this->actingAs($admin)->postJson("/pengguna/{$admin->id}/reset-password", ['current_password' => 'password'])
        ->assertForbidden();
});

test('Batas hak target: Administrator tidak bisa mereset password/MFA akun HRD', function () {
    $admin = uaActor('administrator');
    $hrd = uaActor('hrd');

    $this->actingAs($admin)->postJson("/pengguna/{$hrd->id}/reset-password", ['current_password' => 'password'])->assertForbidden();
    $this->actingAs($admin)->post("/pengguna/{$hrd->id}/reset-mfa")->assertForbidden();

    expect($hrd->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

test('BR-015 HRD tidak bisa reset MFA (403); Administrator bisa reset MFA akun di bawah haknya', function () {
    // Administrator tidak memegang izin foto/persetujuan absensi, jadi hanya akun ber-role pegawai
    // (dan Administrator lain) yang berada di bawah haknya (batas hak target ⊆ pelaku).
    $target = User::factory()->withTwoFactor()->create()->assignRole('pegawai');

    $this->actingAs(uaActor('hrd'))->post("/pengguna/{$target->id}/reset-mfa")->assertForbidden();
    expect($target->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->actingAs(uaActor('administrator'))->post("/pengguna/{$target->id}/reset-mfa")->assertRedirect();
    expect($target->fresh()->two_factor_confirmed_at)->toBeNull()
        ->and(SecurityEvent::query()->where('event', 'mfa_reset_by_admin')->where('user_id', $target->id)->exists())->toBeTrue();
});

test('FR-016 nonaktifkan akun: sesi dicabut, request berikutnya keluar; aktifkan lagi', function () {
    $owner = uaActor('owner');
    $target = User::factory()->create()->assignRole('supervisor');
    uaSession($target, str_repeat('c', 40));

    $this->actingAs($owner)->from("/pengguna/{$target->id}")
        ->patch("/pengguna/{$target->id}/status", ['active' => false])
        ->assertRedirect("/pengguna/{$target->id}");

    expect($target->fresh()->is_active)->toBeFalse()
        ->and(uaSessions($target))->toBe(0)
        ->and(AuditLog::query()->where('event', 'user.deactivated')->where('auditable_id', $target->id)->exists())->toBeTrue();

    // Sesi test terbawa antar-aktor; mulai sesi baru seperti login dari peramban lain.
    auth()->logout();
    $this->flushSession();
    $this->actingAs($target->fresh())->get('/dasbor')->assertRedirect(route('login'));

    // Sesi test terbawa antar-aktor; mulai sesi baru seperti login dari peramban lain.
    auth()->logout();
    $this->flushSession();
    $this->actingAs($owner)->patch("/pengguna/{$target->id}/status", ['active' => true]);
    expect($target->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('event', 'user.activated')->where('auditable_id', $target->id)->exists())->toBeTrue();
});

test('SEC-009 Owner bisa menonaktifkan Super Admin; Super Admin tidak bisa mengubah/menonaktifkan/mereset Owner', function () {
    $owner = uaActor('owner');
    $vendor = uaActor('super_admin');

    $this->actingAs($vendor)->patch("/pengguna/{$owner->id}/status", ['active' => false])->assertForbidden();
    $this->actingAs($vendor)->get("/pengguna/{$owner->id}/ubah")->assertForbidden();
    $this->actingAs($vendor)->post("/pengguna/{$owner->id}/reset-mfa")->assertForbidden();
    $this->actingAs($vendor)->post("/pengguna/{$owner->id}/cabut-sesi")->assertForbidden();
    expect($owner->fresh()->is_active)->toBeTrue();

    // Sesi test terbawa antar-aktor; mulai sesi baru seperti login dari peramban lain.
    auth()->logout();
    $this->flushSession();
    $this->actingAs($owner)->patch("/pengguna/{$vendor->id}/status", ['active' => false])->assertRedirect();
    expect($vendor->fresh()->is_active)->toBeFalse();
});

test('SEC-009 aksi Super Admin ditandai actor_label vendor di audit', function () {
    $vendor = uaActor('super_admin');
    $target = User::factory()->create()->assignRole('supervisor');

    $this->actingAs($vendor)->patch("/pengguna/{$target->id}/status", ['active' => false])->assertRedirect();

    $log = AuditLog::query()->where('event', 'user.deactivated')->firstOrFail();
    expect($log->actor_label)->toBe('vendor')->and($log->user_id)->toBe($vendor->id);
});

test('FR-016 tidak bisa menonaktifkan akun sendiri', function () {
    $owner = uaActor('owner');

    $this->actingAs($owner)->patch("/pengguna/{$owner->id}/status", ['active' => false])->assertForbidden();
    expect($owner->fresh()->is_active)->toBeTrue();
});

test('FR-016 cabut semua sesi akun lain tercatat session_revoked', function () {
    // Target pegawai: sejak T-067 supervisor memegang izin Paket A di luar milik HRD (hak target ⊆ pelaku; PA-35, keputusan Mas 16 Nov 2026).
    $target = User::factory()->create()->assignRole('pegawai');
    uaSession($target, str_repeat('d', 40));
    uaSession($target, str_repeat('e', 40));

    $this->actingAs(uaActor('hrd'))->post("/pengguna/{$target->id}/cabut-sesi")->assertRedirect();

    expect(uaSessions($target))->toBe(0);
    $event = SecurityEvent::query()->where('event', 'session_revoked')->where('user_id', $target->id)->firstOrFail();
    expect($event->context)->toMatchArray(['count' => 2, 'by_admin' => true]);
});
