<?php

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Listings\UserListing;
use App\Domain\Access\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\FakeOrgStructure;

/*
 * Daftar pengguna (07 §3.4 `users`, T-020): cari, filter, sort, scope data (06 §5), IDOR (SEC-033),
 * ekspor tanpa kolom rahasia.
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->useRealPermissions();
    $this->owner = User::factory()->withTwoFactor()->create(['name' => 'Owner Uji'])->assignRole('owner');
});

/** @return list<string> */
function ulUsernames(Assert $page): array
{
    return collect($page->toArray()['props']['rows'])->pluck('username')->all();
}

test('07 §3.4 users: cari nama/username, filter role & aktif, sort login terakhir', function () {
    User::factory()->create(['name' => 'Agus Salim', 'username' => 'agus', 'last_login_at' => now()->subDays(3)])->assignRole('supervisor');
    User::factory()->create(['name' => 'Bambang', 'username' => 'bambang.k', 'is_active' => false, 'last_login_at' => now()->subDay()])->assignRole('pegawai');
    User::factory()->create(['name' => 'Citra', 'username' => 'citra', 'last_login_at' => now()->subDays(2)])->assignRole('supervisor');

    $this->actingAs($this->owner)->get('/pengguna?q=agus')->assertOk()
        ->assertInertia(fn (Assert $page) => expect(ulUsernames($page))->toBe(['agus']));
    $this->actingAs($this->owner)->get('/pengguna?q=bamb')
        ->assertInertia(fn (Assert $page) => expect(ulUsernames($page))->toBe(['bambang.k']));

    $this->actingAs($this->owner)->get('/pengguna?filter[role][]=supervisor')
        ->assertInertia(fn (Assert $page) => expect(ulUsernames($page))->toBe(['agus', 'citra']));
    $this->actingAs($this->owner)->get('/pengguna?filter[is_active]=0')
        ->assertInertia(fn (Assert $page) => expect(ulUsernames($page))->toBe(['bambang.k']));

    $this->actingAs($this->owner)->get('/pengguna?sort=-last_login_at&filter[role][]=supervisor&filter[role][]=pegawai')
        ->assertInertia(fn (Assert $page) => expect(ulUsernames($page))->toBe(['bambang.k', 'citra', 'agus']));
});

test('07 §3.4 users: filter MFA aktif & punya pegawai; baris tanpa kolom rahasia', function () {
    User::factory()->withTwoFactor()->create(['username' => 'pakai.mfa']);
    User::factory()->create(['username' => 'tanpa.mfa', 'employee_id' => employeeRow(7)]);

    $this->actingAs($this->owner)->get('/pengguna?filter[mfa]=0')
        ->assertInertia(fn (Assert $page) => expect(ulUsernames($page))->toBe(['tanpa.mfa']));
    $this->actingAs($this->owner)->get('/pengguna?filter[has_employee]=1')
        ->assertInertia(function (Assert $page) {
            expect(ulUsernames($page))->toBe(['tanpa.mfa']);
            $row = $page->toArray()['props']['rows'][0];
            expect(array_keys($row))->not->toContain('password')->not->toContain('two_factor_secret');
        });
});

test('06 §5 scope pengguna: role ber-scope lokasi hanya melihat akun pegawai di lokasinya', function () {
    $org = FakeOrgStructure::bind();
    $org->employee(1)->at(10);
    $org->employee(2)->at(10);
    $org->employee(3)->at(20);

    $role = Role::query()->create(['name' => 'admin_cabang', 'label' => 'Admin Cabang', 'guard_name' => 'web', 'data_scope' => DataScope::Location]);
    $role->givePermissionTo(['dashboard.view', 'users.view', 'users.update', 'users.reset_password']);
    $actor = User::factory()->create(['employee_id' => employeeRow(1), 'name' => 'Kepala Cabang', 'username' => 'kepala.cabang'])->assignRole('admin_cabang');

    $inScope = User::factory()->create(['employee_id' => employeeRow(2), 'name' => 'Dalam Scope', 'username' => 'dalam.scope'])->assignRole('pegawai');
    $outScope = User::factory()->create(['employee_id' => employeeRow(3), 'username' => 'luar.scope'])->assignRole('pegawai');
    User::factory()->create(['username' => 'tanpa.pegawai']);

    $this->actingAs($actor)->get('/pengguna')->assertOk()
        ->assertInertia(fn (Assert $page) => expect(ulUsernames($page))->toBe(['dalam.scope', 'kepala.cabang']));

    $this->actingAs($actor)->get("/pengguna/{$inScope->id}")->assertOk();

    // SEC-033: akun di luar scope = tidak ada (404), bukan 403.
    $this->assertOutOfScope404($actor, 'GET', "/pengguna/{$outScope->id}");
    $this->assertOutOfScope404($actor, 'GET', "/pengguna/{$outScope->id}/ubah");
    $this->assertOutOfScope404($actor, 'PUT', "/pengguna/{$outScope->id}", ['name' => 'X', 'username' => 'luar.scope']);
    $this->assertOutOfScope404($actor, 'POST', "/pengguna/{$outScope->id}/reset-password", ['current_password' => 'password']);
    $this->assertOutOfScope404($actor, 'POST', "/pengguna/{$outScope->id}/cabut-sesi");
    $this->assertOutOfScope404($actor, 'PATCH', "/pengguna/{$outScope->id}/status", ['active' => false]);
    $this->assertOutOfScope404($actor, 'POST', "/pengguna/{$outScope->id}/reset-mfa");

    expect($outScope->fresh()->must_change_password)->toBeFalse();
});

test('07 §3.4 users: ekspor sesuai izin dan scope, kolom tanpa password/secret', function () {
    $headers = (new UserListing)->exportColumns()->headers();
    expect($headers)->toBe(['Nama', 'Username', 'Email', 'HP', 'Role', 'Aktif', 'MFA aktif', 'Login terakhir', 'No. pegawai', 'Pegawai']);

    $hrd = User::factory()->withTwoFactor()->create()->assignRole('hrd');
    $this->actingAs($hrd)->get('/pengguna/ekspor')->assertForbidden();

    $this->actingAs($this->owner)->get('/pengguna/ekspor')->assertOk()->assertDownload();
});
