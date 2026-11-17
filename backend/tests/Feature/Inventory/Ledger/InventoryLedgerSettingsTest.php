<?php

use App\Domain\Settings\Actions\UpdateSettingGroup;
use App\Domain\Settings\SettingRegistry;
use App\Domain\Settings\SettingType;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/*
 * T-077 — setting buku stok (15 §9, 08 §2.10). Kesamaan kunci/tipe/default/batas dengan 08 §2 diuji
 * dua arah oleh SettingRegistryTest; di sini penanda & aturan khususnya.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

test('BR-101 inventory.negative_stock_allowed: bool false, locked, check menolak true', function () {
    $d = SettingRegistry::get('inventory.negative_stock_allowed');

    expect($d->type)->toBe(SettingType::Bool)
        ->and($d->default)->toBeFalse()
        ->and($d->locked)->toBeTrue()
        ->and($d->isPublic)->toBeFalse()
        ->and(($d->check)(true, []))->toBeString()
        ->and(($d->check)(false, []))->toBeNull()
        ->and(SettingRegistry::hasLocked('inventory'))->toBeTrue();
});

test('BR-101 negative_stock_allowed tidak bisa dinyalakan, bahkan oleh super_admin yang sudah konfirmasi password', function () {
    $super = User::factory()->withTwoFactor()->create()->assignRole('super_admin');

    expect(fn () => app(UpdateSettingGroup::class)->handle('inventory', ['inventory__negative_stock_allowed' => true], [], [], $super->id, passwordConfirmed: true))
        ->toThrow(ValidationException::class);
    app()->forgetScopedInstances();
    expect(setting('inventory.negative_stock_allowed'))->toBeFalse();

    $admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
    expect(fn () => app(UpdateSettingGroup::class)->handle('inventory', ['inventory__negative_stock_allowed' => true], [], [], $admin->id, passwordConfirmed: true))
        ->toThrow(ValidationException::class);
});

test('BR-155 inventory.reservation_ttl_hours 1–720, default 72, bisa diubah admin tanpa penanda locked', function () {
    $d = SettingRegistry::get('inventory.reservation_ttl_hours');
    expect([$d->type, $d->default, $d->min, $d->max, $d->locked])->toBe([SettingType::Int, 72, 1, 720, false]);

    $admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
    // Form mengirim field terkunci dengan nilai lamanya; itu tidak dianggap perubahan.
    app(UpdateSettingGroup::class)->handle('inventory', ['inventory__reservation_ttl_hours' => 24, 'inventory__negative_stock_allowed' => false], [], [], $admin->id);
    app()->forgetScopedInstances();
    expect(setting('inventory.reservation_ttl_hours'))->toBe(24);

    expect(fn () => app(UpdateSettingGroup::class)->handle('inventory', ['inventory__reservation_ttl_hours' => 721], [], [], $admin->id))
        ->toThrow(ValidationException::class);
});

test('T-077 jenis notifikasi stock_reconcile_mismatch punya kunci kanal (default lonceng saja)', function () {
    $d = SettingRegistry::get('notification.on_stock_reconcile_mismatch');

    expect($d->type)->toBe(SettingType::MultiEnum)->and($d->default)->toBe([]);
});

// Pengaman: administrator mengubah kunci terkunci dengan nilai lain tetap 403 (mekanisme T-067).
test('PA-19 administrator tidak bisa mengubah kunci terkunci inventory (403)', function () {
    $admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
    setSetting('inventory.negative_stock_allowed', true); // nilai rusak, hanya untuk memicu "perubahan" ke false

    expect(fn () => app(UpdateSettingGroup::class)->handle('inventory', ['inventory__negative_stock_allowed' => false], [], [], $admin->id))
        ->toThrow(AuthorizationException::class);
});
