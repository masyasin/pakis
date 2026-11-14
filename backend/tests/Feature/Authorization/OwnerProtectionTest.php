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

    expect(canFor($this->hrd, 'users.reset-mfa-of', $staff))->toBeFalse()   // hrd tanpa users.reset_mfa (06 §4)
        ->and(canFor($this->hrd, 'users.assign-roles', $staff))->toBeTrue()  // hrd punya users.update
        ->and(canFor($this->admin, 'users.reset-mfa-of', $staff))->toBeTrue();
});

test('Super Admin tetap lolos permission biasa lewat Gate::before', function () {
    expect($this->superAdmin->can('employees.view_sensitive'))->toBeTrue()
        ->and($this->superAdmin->can('audit.export'))->toBeTrue();
});
