<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Purchasing\Models\Principal;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * T-072 — prinsipal (FR-A11, 15 §4.5/§5). Izin lewat Gate; matriks role diuji PaketAMatrixTest.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    Gate::define('suppliers.view', fn () => true);
    Gate::define('suppliers.manage', fn () => true);
});

/** @return array<string, mixed> */
function principalPayload(array $overrides = []): array
{
    return ['code' => 'agro-demo', 'name' => 'PT Agro Demo', 'contact_name' => null, 'phone' => '024-7600001', 'email' => null, 'address' => null, 'website' => 'www.agro-demo.test', 'is_active' => true, ...$overrides];
}

test('FR-A11 tambah & ubah prinsipal: kode dinormalkan huruf besar, unik, tercatat audit', function () {
    $this->actingAs($this->user)->post('/prinsipal', principalPayload())->assertSessionHasNoErrors();
    $principal = Principal::query()->sole();
    expect($principal->code)->toBe('AGRO-DEMO');

    $this->actingAs($this->user)->post('/prinsipal', principalPayload(['name' => 'Lain']))->assertSessionHasErrors('code');
    $this->actingAs($this->user)->post('/prinsipal', principalPayload(['code' => 'A B']))->assertSessionHasErrors('code');

    $this->actingAs($this->user)->put("/prinsipal/{$principal->id}", principalPayload(['name' => 'PT Agro Demo Baru', 'is_active' => false]))->assertSessionHasNoErrors();
    expect($principal->fresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->whereIn('event', ['principal.created', 'principal.updated'])->count())->toBe(2);
});

test('15 §7.3 daftar prinsipal: cari kode/nama, filter aktif, sort nama; lookup hanya yang aktif', function () {
    Principal::query()->create(['code' => 'BRV', 'name' => 'Bravo Demo', 'is_active' => true]);
    Principal::query()->create(['code' => 'ALF', 'name' => 'Alfa Demo', 'is_active' => false]);

    $names = fn (string $q) => collect($this->actingAs($this->user)->get("/prinsipal{$q}")->assertOk()->inertiaProps('rows'))->pluck('name')->all();
    expect($names(''))->toBe(['Alfa Demo', 'Bravo Demo'])
        ->and($names('?q=BRV'))->toBe(['Bravo Demo'])
        ->and($names('?filter[is_active]=1'))->toBe(['Bravo Demo'])
        ->and($names('?sort=-name'))->toBe(['Bravo Demo', 'Alfa Demo']);

    $labels = collect($this->actingAs($this->user)->getJson('/lookup/principals')->assertOk()->json())->pluck('label')->all();
    expect($labels)->toBe(['Bravo Demo']);
});

test('06 izin: tanpa suppliers.manage tidak bisa tambah/ubah prinsipal; tanpa .view daftar ditolak', function () {
    $principal = Principal::query()->create(['code' => 'X1', 'name' => 'X Demo', 'is_active' => true]);
    Gate::define('suppliers.manage', fn () => false);
    $this->actingAs($this->user)->post('/prinsipal', principalPayload())->assertForbidden();
    $this->actingAs($this->user)->put("/prinsipal/{$principal->id}", principalPayload())->assertForbidden();

    Gate::define('suppliers.view', fn () => false);
    $this->actingAs($this->user)->get('/prinsipal')->assertForbidden();
});
