<?php

use App\Models\User;
use App\Support\Lookup\LookupRegistry;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Route;
use Tests\Support\AssertsIdor;
use Tests\Support\FakeOrgStructure;
use Tests\Support\ScopedUserLookup;
use Tests\Support\ScopedUserProbe;

/*
 * SEC-033 (06 §10): resource di luar scope di-resolve sebagai TIDAK ADA → 404, bukan 403.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $org = FakeOrgStructure::bind();
    $org->employee(1)->at(10);
    $org->employee(2)->at(10)->reportsTo(1);
    $org->employee(3)->at(20);

    Route::middleware('web')->get('/_uji/scope/{probe}', fn (ScopedUserProbe $probe) => response()->json(['id' => $probe->id]));

    $this->insider = User::factory()->create(['employee_id' => 2]);
    $this->outsider = User::factory()->create(['employee_id' => 3]);
});

uses(AssertsIdor::class);

test('SEC-033 supervisor: data bawahan terbuka, data di luar scope 404 (bukan 403)', function () {
    $supervisor = User::factory()->create(['employee_id' => 1])->assignRole('supervisor');

    $this->actingAs($supervisor)->getJson("/_uji/scope/{$this->insider->id}")->assertOk();
    $this->assertOutOfScope404($supervisor, 'GET', "/_uji/scope/{$this->outsider->id}");
});

test('SEC-033 scope all melihat semua; ID yang memang tidak ada juga 404', function () {
    $hrd = User::factory()->withTwoFactor()->create(['employee_id' => 99])->assignRole('hrd');

    $this->actingAs($hrd)->getJson("/_uji/scope/{$this->outsider->id}")->assertOk();
    $this->actingAs($hrd)->getJson('/_uji/scope/999999')->assertNotFound();
});

test('SEC-033 tanpa login: tidak ada data yang ter-resolve', function () {
    $this->getJson("/_uji/scope/{$this->insider->id}")->assertNotFound();
});

test('BR-032 lookup ber-scope hanya mengembalikan data dalam scope (termasuk ids[])', function () {
    app(LookupRegistry::class)->register('uji_scope', ScopedUserLookup::class);
    $supervisor = User::factory()->create(['employee_id' => 1])->assignRole('supervisor');

    $values = fn (string $query) => collect($this->actingAs($supervisor)->getJson('/lookup/uji_scope'.$query)->assertOk()->json())->pluck('value')->all();

    expect($values(''))->toContain($this->insider->id)->not->toContain($this->outsider->id)
        ->and($values('?ids[]='.$this->outsider->id))->toBe([]);
});
