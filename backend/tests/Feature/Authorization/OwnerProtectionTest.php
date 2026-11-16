<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;

/*
 * SEC-009 (06 §2): Super Admin lolos semua permission KECUALI mengubah akun Owner. BR-031:
 * tidak ada yang bisa mengubah role akunnya sendiri.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->owner = User::factory()->create()->assignRole('owner');
    $this->otherOwner = User::factory()->create()->assignRole('owner');
    $this->superAdmin = User::factory()->create()->assignRole('super_admin');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->hrd = User::factory()->create()->assignRole('hrd');
});

function canFor(User $actor, string $ability, User $target): bool
{
    return Gate::forUser($actor)->allows($ability, $target);
}

test('SEC-009 Super Admin tidak bisa mengubah role / mereset MFA akun Owner', function (string $ability) {
    expect(canFor($this->superAdmin, $ability, $this->owner))->toBeFalse()
        ->and(canFor($this->superAdmin, $ability, $this->hrd))->toBeTrue();
})->with(['users.assign-roles', 'users.reset-mfa-of']);

test('SEC-009 hanya Owner yang bisa mengubah akun Owner lain', function (string $ability) {
    expect(canFor($this->otherOwner, $ability, $this->owner))->toBeTrue()
        ->and(canFor($this->admin, $ability, $this->owner))->toBeFalse();
})->with(['users.assign-roles', 'users.reset-mfa-of']);

test('BR-031 tidak ada yang bisa mengubah role / mereset MFA akunnya sendiri', function (string $role, string $ability) {
    $self = $this->{$role};

    expect(canFor($self, $ability, $self))->toBeFalse();
})->with([
    ['owner', 'users.assign-roles'],
    ['superAdmin', 'users.assign-roles'],
    ['admin', 'users.reset-mfa-of'],
]);

test('butuh permission dasar: HRD tanpa users.reset_mfa tidak bisa mereset MFA', function () {
    $staff = User::factory()->create()->assignRole('kepala_lokasi');
    $pegawai = User::factory()->create()->assignRole('pegawai');

    expect(canFor($this->hrd, 'users.reset-mfa-of', $staff))->toBeFalse()   // hrd tanpa users.reset_mfa (06 §4)
        ->and(canFor($this->hrd, 'users.reset-mfa-of', $pegawai))->toBeFalse()
        ->and(canFor($this->hrd, 'users.assign-roles', $pegawai))->toBeTrue()  // hrd punya users.update
        ->and(canFor($this->admin, 'users.reset-mfa-of', $pegawai))->toBeTrue();
});

test('T-020 batas hak target: akun dengan permission di luar milik pelaku tidak bisa disentuh', function () {
    $staff = User::factory()->create()->assignRole('kepala_lokasi'); // memegang foto & persetujuan absensi

    // Administrator sengaja tanpa akses foto/data SDM (06 §4) → tidak bisa mengambil alih akun itu.
    expect(canFor($this->admin, 'users.reset-mfa-of', $staff))->toBeFalse()
        ->and(canFor($this->admin, 'users.reset-password-of', $staff))->toBeFalse()
        ->and(canFor($this->admin, 'users.manage-account', $staff))->toBeFalse()
        ->and(canFor($this->admin, 'users.reset-password-of', $this->hrd))->toBeFalse()
        ->and(canFor($this->hrd, 'users.manage-account', $this->admin))->toBeFalse()
        // Sejak T-067 Kepala Lokasi memegang izin Paket A (15 §6.4) yang tidak dimiliki HRD → HRD pun
        // tidak bisa lagi mengelola akun itu (PA-35, keputusan Mas 16 Nov 2026; batas ini tidak dilonggarkan).
        ->and(canFor($this->hrd, 'users.reset-password-of', $staff))->toBeFalse()
        // Akun yang seluruh izinnya dimiliki HRD (pegawai) tetap boleh.
        ->and(canFor($this->hrd, 'users.reset-password-of', User::factory()->create()->assignRole('pegawai')))->toBeTrue();
});

test('Super Admin tetap lolos permission biasa lewat Gate::before', function () {
    expect($this->superAdmin->can('employees.view_sensitive'))->toBeTrue()
        ->and($this->superAdmin->can('audit.export'))->toBeTrue();
});
