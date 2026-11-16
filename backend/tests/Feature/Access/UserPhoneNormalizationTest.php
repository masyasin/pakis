<?php

use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use App\Support\Phone\PhoneNormalizer;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
 * Addendum T-020 di T-073 (BR-166(1)/(3)): `users.phone` disimpan `62…` lewat normalizer bersama di
 * form Pengguna & Profil web (API PUT /me diuji di ApiAuthTest), plus migrasi data nilai lama.
 * Semua nomor fiktif.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    Notification::fake();
});

test('BR-166(1) form Pengguna: HP 08…/+62… tersimpan 62…; HP tak sah ditolak dengan pesan jelas', function () {
    $this->useRealPermissions();
    $owner = User::factory()->withTwoFactor()->create()->assignRole('owner');
    $target = User::factory()->create(['phone' => null])->assignRole('supervisor');
    $payload = fn (array $o) => ['name' => $target->name, 'username' => $target->username, 'email' => null, 'roles' => ['supervisor'], ...$o];

    $this->actingAs($owner)->put("/pengguna/{$target->id}", $payload(['phone' => '+62 812-3456-7002']))
        ->assertSessionHasNoErrors()->assertRedirect();
    expect($target->refresh()->phone)->toBe('6281234567002');

    $this->actingAs($owner)->from("/pengguna/{$target->id}/ubah")->put("/pengguna/{$target->id}", $payload(['phone' => '021 1234']))
        ->assertSessionHasErrors(['phone' => PhoneNormalizer::INVALID_MESSAGE]);
    expect($target->refresh()->phone)->toBe('6281234567002');
});

test('BR-166(1) form Pengguna: HP lama tak sah yang tidak diubah tetap diterima saat mengubah role', function () {
    $this->useRealPermissions();
    $owner = User::factory()->withTwoFactor()->create()->assignRole('owner');
    $target = User::factory()->create()->assignRole('supervisor');
    DB::table('users')->where('id', $target->id)->update(['phone' => '12345']);

    $this->actingAs($owner)->put("/pengguna/{$target->id}", [
        'name' => $target->name, 'username' => $target->username, 'email' => null, 'phone' => '12345', 'roles' => ['hrd'],
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect($target->refresh()->phone)->toBe('12345')->and($target->hasRole('hrd'))->toBeTrue();
});

test('BR-166(1) Profil web: HP lama 08… tanpa diubah tidak meminta password; ganti HP tersimpan 62…', function () {
    $user = User::factory()->create(['email' => 'siti@contoh.test']);
    DB::table('users')->where('id', $user->id)->update(['phone' => '081200000001']);
    $user->refresh();

    $this->actingAs($user)->from('/profil')->put('/profil', ['name' => 'Siti Baru', 'email' => 'siti@contoh.test', 'phone' => '0812 0000 0001'])
        ->assertSessionHasNoErrors();
    expect($user->refresh()->phone)->toBe('6281200000001')->and($user->name)->toBe('Siti Baru');

    $this->actingAs($user)->from('/profil')->put('/profil', ['name' => 'Siti Baru', 'email' => 'siti@contoh.test', 'phone' => '0812 9999 0002', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();
    expect($user->refresh()->phone)->toBe('6281299990002');

    $this->actingAs($user)->from('/profil')->put('/profil', ['name' => 'Siti Baru', 'email' => 'siti@contoh.test', 'phone' => 'abc', 'current_password' => 'password'])
        ->assertSessionHasErrors(['phone' => PhoneNormalizer::INVALID_MESSAGE]);
});

test('BR-166(1) migrasi data users.phone: 08…/+62… → 62…, nilai tak sah dibiarkan & dilaporkan tanpa nomornya', function () {
    $valid = User::factory()->create();
    $plus = User::factory()->create();
    $invalid = User::factory()->create();
    $empty = User::factory()->create(['phone' => null]);
    DB::table('users')->where('id', $valid->id)->update(['phone' => '081234567003']);
    DB::table('users')->where('id', $plus->id)->update(['phone' => '+6281234567004']);
    DB::table('users')->where('id', $invalid->id)->update(['phone' => '0211234']);
    $auditBefore = AuditLog::query()->count();

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e;
    });

    (require database_path('migrations/2026_11_17_030200_normalize_user_phones.php'))->up();

    expect($valid->refresh()->phone)->toBe('6281234567003')
        ->and($plus->refresh()->phone)->toBe('6281234567004')
        ->and($invalid->refresh()->phone)->toBe('0211234')
        ->and($empty->refresh()->phone)->toBeNull()
        ->and(AuditLog::query()->count())->toBe($auditBefore);

    $report = collect($logged)->first(fn (MessageLogged $e) => str_contains($e->message, 'users.phone'));
    expect($report)->not->toBeNull()
        ->and($report->context)->toMatchArray(['changed' => 2, 'invalid_count' => 1, 'invalid_user_ids' => [$invalid->id]])
        ->and(json_encode($report->context))->not->toContain('0211234');
});
