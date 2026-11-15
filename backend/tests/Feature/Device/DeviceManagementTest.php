<?php

use App\Domain\Access\Actions\ChangePassword;
use App\Domain\Access\Actions\ResetMfa;
use App\Domain\Access\Actions\RevokeUserSessions;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Device\Support\DeviceTokens;
use App\Domain\Organization\Models\Location;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\AssertsIdor;
use Tests\Support\EmployeeWorld;

/*
 * Halaman Perangkat (T-031): 05 §4.8, 06 §7.2, 07 §3.4 `devices`, BR-040, SEC-020, SEC-033.
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->world = EmployeeWorld::make($this);
    $this->useRealPermissions();
    $this->hrd = $this->world->actor('hrd');
});

/** @param array<string, mixed> $attributes */
function makeDevice(User $owner, string $status = 'pending', array $attributes = []): Device
{
    // forceFill: kolom seperti `last_seen_at` sengaja tidak fillable di model.
    $device = (new Device)->forceFill([
        'user_id' => $owner->id,
        'device_uid' => hash('sha256', 'hp-'.fake()->unique()->uuid()),
        'model' => 'Xiaomi Redmi 12',
        'os_version' => 'Android 14',
        'app_version' => '1.0.0',
        'status' => $status,
        ...$attributes,
    ]);
    $device->save();

    return $device;
}

/** Pemilik HP: akun pegawai yang terhubung ke pegawai di lokasi tertentu. */
function phoneOwner(EmployeeWorld $world, string $name, ?Location $location = null): User
{
    $employee = $world->employee($location);

    return User::factory()->create(['name' => $name, 'employee_id' => $employee->id])->assignRole('pegawai');
}

test('07 §3.4 devices: default menunggu + aktif, cari nama/model, filter status, sort terakhir aktif', function () {
    $agus = phoneOwner($this->world, 'Agus Santoso');
    $siti = phoneOwner($this->world, 'Siti Aminah');
    makeDevice($agus, 'active', ['model' => 'Samsung A04', 'last_seen_at' => now()->subHour()]);
    makeDevice($siti, 'pending', ['model' => 'Oppo A17']);
    makeDevice($siti, 'rejected', ['model' => 'Vivo Y12']);

    $models = fn (string $uri) => collect($this->actingAs($this->hrd)->get($uri)->assertOk()->viewData('page')['props']['rows'])->pluck('model')->all();

    expect($models('/perangkat'))->toEqualCanonicalizing(['Samsung A04', 'Oppo A17'])
        ->and($models('/perangkat?q=agus'))->toBe(['Samsung A04'])
        ->and($models('/perangkat?q=vivo&filter[status][]=rejected'))->toBe(['Vivo Y12'])
        ->and($models('/perangkat?filter[status][]=pending'))->toBe(['Oppo A17'])
        ->and($models('/perangkat?sort=-last_seen_at'))->toBe(['Samsung A04', 'Oppo A17']);

    $this->actingAs($this->hrd)->get('/perangkat')->assertInertia(fn (Assert $page) => $page
        ->component('devices/index')
        ->where('can.manage', true)
        ->has('rows.0.user.name'));
});

test('06 §5 Kepala Lokasi hanya melihat perangkat pegawai lokasinya; tidak bisa mengelola', function () {
    $headEmployee = $this->world->employee($this->world->gudang);
    $head = $this->world->actor('kepala_lokasi', $headEmployee);
    $gudang = phoneOwner($this->world, 'Pegawai Gudang', $this->world->gudang);
    $toko = phoneOwner($this->world, 'Pegawai Toko', $this->world->toko);
    $own = makeDevice($gudang);
    makeDevice($toko);

    $rows = collect($this->actingAs($head)->get('/perangkat')->assertOk()->viewData('page')['props']['rows']);
    expect($rows->pluck('user.name')->all())->toBe(['Pegawai Gudang']);

    $this->actingAs($head)->post("/perangkat/{$own->id}/setujui")->assertForbidden();
});

test('SEC-033 IDOR: perangkat di luar scope → 404 pada aksi ber-ID', function () {
    $headEmployee = $this->world->employee($this->world->gudang);
    $head = $this->world->actor('kepala_lokasi', $headEmployee);
    // Diberi izin kelola agar yang diuji scope, bukan permission.
    $head->givePermissionTo('devices.manage');
    $outside = makeDevice(phoneOwner($this->world, 'Pegawai Toko', $this->world->toko));

    foreach (['setujui', 'tolak', 'cabut'] as $action) {
        $this->assertOutOfScope404($head, 'POST', "/perangkat/{$outside->id}/{$action}", ['reason' => 'Bukan HP kantor']);
    }
    expect($outside->fresh()->status)->toBe(DeviceStatus::Pending);
});

test('06 §7.2 setujui: perangkat aktif lama diganti, tokennya dicabut, audit & log keamanan tercatat', function () {
    $owner = phoneOwner($this->world, 'Agus Santoso');
    $old = makeDevice($owner, 'active');
    app(DeviceTokens::class)->issue($owner, $old);
    $new = makeDevice($owner);

    $this->actingAs($this->hrd)->post("/perangkat/{$new->id}/setujui")->assertRedirect();

    expect($new->fresh()->status)->toBe(DeviceStatus::Active)
        ->and($new->fresh()->approved_by)->toBe($this->hrd->id)
        ->and($old->fresh()->status)->toBe(DeviceStatus::Replaced)
        ->and(PersonalAccessToken::query()->count())->toBe(0);

    $audit = AuditLog::query()->where('event', 'device.approved')->sole();
    expect($audit->new_values)->toMatchArray(['status' => 'active', 'replaced_device_ids' => [$old->id]])
        ->and(SecurityEvent::query()->where('event', 'device_approved')->exists())->toBeTrue();
});

test('06 §7.2 tolak wajib alasan ≥ 5 karakter; hanya perangkat menunggu yang bisa diputuskan', function () {
    $device = makeDevice(phoneOwner($this->world, 'Agus Santoso'));

    $this->actingAs($this->hrd)->from('/perangkat')->post("/perangkat/{$device->id}/tolak", ['reason' => 'x'])->assertSessionHasErrors('reason');
    $this->actingAs($this->hrd)->from('/perangkat')->post("/perangkat/{$device->id}/tolak", ['reason' => 'HP pribadi keluarga'])->assertRedirect('/perangkat');

    expect($device->fresh()->status)->toBe(DeviceStatus::Rejected)
        ->and($device->fresh()->request_reason)->toBe('HP pribadi keluarga')
        ->and(AuditLog::query()->where('event', 'device.rejected')->exists())->toBeTrue();

    $this->actingAs($this->hrd)->from('/perangkat')->post("/perangkat/{$device->id}/setujui")->assertSessionHasErrors('device');
});

test('06 §7.2 cabut perangkat aktif mencabut tokennya seketika', function () {
    $owner = phoneOwner($this->world, 'Agus Santoso');
    $device = makeDevice($owner, 'active');
    app(DeviceTokens::class)->issue($owner, $device);

    $this->actingAs($this->hrd)->post("/perangkat/{$device->id}/cabut")->assertRedirect();

    expect($device->fresh()->status)->toBe(DeviceStatus::Revoked)
        ->and(PersonalAccessToken::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'device.revoked')->exists())->toBeTrue()
        ->and(SecurityEvent::query()->where('event', 'device_revoked')->exists())->toBeTrue();
});

test('SEC-020 ganti password web, reset MFA, dan cabut sesi oleh admin ikut mencabut token API', function () {
    $owner = phoneOwner($this->world, 'Agus Santoso');
    $issue = function () use ($owner) {
        $device = Device::query()->where('user_id', $owner->id)->where('status', 'active')->first() ?? makeDevice($owner, 'active');
        app(DeviceTokens::class)->issue($owner, $device);
        expect($owner->tokens()->count())->toBe(1);
    };

    $issue();
    app(ChangePassword::class)->handle($owner, 'teh sore di kantor');
    expect($owner->tokens()->count())->toBe(0);

    $issue();
    app(RevokeUserSessions::class)->handle($owner);
    expect($owner->tokens()->count())->toBe(0);

    $issue();
    $owner->forceFill(['two_factor_confirmed_at' => now()])->save();
    $owner->assignRole('pegawai');
    $admin = $this->world->actor('owner');
    app(ResetMfa::class)->handle($admin, $owner);
    expect($owner->tokens()->count())->toBe(0)
        ->and(SecurityEvent::query()->where('event', 'token_revoked')->count())->toBe(3);
});

test('FR-034 ekspor perangkat mengunduh berkas', function () {
    makeDevice(phoneOwner($this->world, 'Agus Santoso'), 'active');

    $response = $this->actingAs($this->hrd)->get('/perangkat/ekspor');

    $response->assertOk();
    expect((string) $response->headers->get('content-disposition'))->toContain('.xlsx');
});

test('SEC-031 pola: pemegang devices.manage tidak bisa menyetujui HP untuk akunnya sendiri', function () {
    $own = makeDevice($this->hrd);

    $this->actingAs($this->hrd)->from('/perangkat')->post("/perangkat/{$own->id}/setujui")
        ->assertSessionHasErrors(['device' => 'Perangkat milik akun Anda sendiri harus disetujui admin lain.']);

    expect($own->fresh()->status)->toBe(DeviceStatus::Pending);
});

test('06 §5 galat "HP masih aktif di akun lain" tidak menyebut nama akun di luar scope pelaku', function () {
    $headEmployee = $this->world->employee($this->world->gudang);
    $head = $this->world->actor('kepala_lokasi', $headEmployee);
    $head->givePermissionTo('devices.manage');
    $uid = hash('sha256', 'hp-bersama');
    makeDevice(phoneOwner($this->world, 'Rahasia Toko', $this->world->toko), 'active', ['device_uid' => $uid]);
    $pending = makeDevice(phoneOwner($this->world, 'Pegawai Gudang', $this->world->gudang), 'pending', ['device_uid' => $uid]);

    $this->actingAs($head)->from('/perangkat')->post("/perangkat/{$pending->id}/setujui")
        ->assertSessionHasErrors(['device' => 'HP ini masih aktif untuk akun lain. Cabut perangkat itu dulu (satu HP hanya untuk satu akun).']);
});
