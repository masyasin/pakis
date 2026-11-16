<?php

use App\Domain\Access\Notifications\EmailChanged;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * FR-010 Profil saya: nama, HP, email (verifikasi ulang). Email & HP adalah kanal pemulihan akun
 * (lupa password), jadi mengubahnya wajib password saat ini.
 */

beforeEach(function () {
    Notification::fake();
    $this->user = User::factory()->create([
        'name' => 'Siti Aminah',
        'username' => 'siti.aminah',
        'email' => 'siti@contoh.test',
        'phone' => '6281200000001', // T-073: HP akun tersimpan 62…
    ]);
});

function updateProfile(array $data)
{
    return test()->from('/profil')->put('/profil', [
        'name' => 'Siti Aminah',
        'email' => 'siti@contoh.test',
        'phone' => '6281200000001', // T-073: HP akun tersimpan 62…
        ...$data,
    ]);
}

test('halaman profil tampil', function () {
    $this->actingAs($this->user)->get('/profil')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('profile/edit'));
});

test('FR-010 ubah nama tanpa password saat ini; tercatat audit user.updated', function () {
    $this->actingAs($this->user);

    updateProfile(['name' => 'Siti Aminah Rahayu'])->assertSessionHasNoErrors()->assertRedirect('/profil');

    expect($this->user->refresh()->name)->toBe('Siti Aminah Rahayu');
    $log = AuditLog::query()->where('event', 'user.updated')->sole();
    expect($log->old_values)->toBe(['name' => 'Siti Aminah'])
        ->and($log->new_values)->toBe(['name' => 'Siti Aminah Rahayu']);
    Notification::assertNothingSent();
});

test('FR-010 ganti email/HP wajib password saat ini yang benar', function (string $field, string $value) {
    $this->actingAs($this->user);

    updateProfile([$field => $value])->assertSessionHasErrors('current_password');
    updateProfile([$field => $value, 'current_password' => 'salah'])->assertSessionHasErrors('current_password');

    expect($this->user->refresh()->{$field})->not->toBe($value);

    updateProfile([$field => $value, 'current_password' => 'password'])->assertSessionHasNoErrors();
    expect($this->user->refresh()->{$field})->toBe($value);
})->with([
    'email' => ['email', 'siti.baru@contoh.test'],
    'HP' => ['phone', '6281299999999'],
]);

test('FR-010 ganti email → email_verified_at null, verifikasi ke alamat baru, pemberitahuan ke alamat lama', function () {
    $this->actingAs($this->user);

    updateProfile(['email' => 'Siti.Baru@Contoh.test', 'current_password' => 'password'])->assertSessionHasNoErrors();

    $this->user->refresh();
    expect($this->user->email)->toBe('siti.baru@contoh.test')
        ->and($this->user->email_verified_at)->toBeNull();

    Notification::assertSentTo($this->user, VerifyEmail::class);
    Notification::assertSentOnDemand(EmailChanged::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'siti@contoh.test');
});

test('email tidak berubah → status verifikasi tetap', function () {
    $this->actingAs($this->user);

    updateProfile(['email' => 'SITI@contoh.test'])->assertSessionHasNoErrors();

    expect($this->user->refresh()->email_verified_at)->not->toBeNull();
    Notification::assertNothingSent();
});

test('email unik tanpa memandang huruf besar/kecil', function () {
    User::factory()->create(['email' => 'dipakai@contoh.test']);
    $this->actingAs($this->user);

    updateProfile(['email' => 'Dipakai@Contoh.test', 'current_password' => 'password'])->assertSessionHasErrors('email');
});

test('email boleh dikosongkan (opsional), tetap wajib password', function () {
    $this->actingAs($this->user);

    updateProfile(['email' => '', 'current_password' => 'password'])->assertSessionHasNoErrors();

    expect($this->user->refresh()->email)->toBeNull();
});

test('username tidak bisa diubah lewat profil', function () {
    $this->actingAs($this->user);

    updateProfile(['username' => 'penyusup'])->assertSessionHasNoErrors();

    expect($this->user->refresh()->username)->toBe('siti.aminah');
});

test('auth.user hanya membawa field yang dibutuhkan UI (tanpa chat Telegram, IP login, status kunci)', function () {
    $this->user->forceFill(['telegram_chat_id' => '123456789', 'last_login_ip' => '10.0.0.1'])->save();

    $this->actingAs($this->user)->get('/profil')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.username', 'siti.aminah')
            ->missing('auth.user.telegram_chat_id')
            ->missing('auth.user.last_login_ip')
            ->missing('auth.user.locked_until')
            ->missing('auth.user.employee_id'));
});

test('route starter kit lama sudah tidak ada', function (string $method, string $uri) {
    $this->actingAs($this->user)->call($method, $uri)->assertNotFound();
})->with([
    ['GET', '/settings/profile'],
    ['DELETE', '/settings/profile'],
    ['GET', '/settings/appearance'],
    ['GET', '/settings/security'],
]);
