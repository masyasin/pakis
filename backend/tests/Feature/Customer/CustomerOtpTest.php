<?php

use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Models\Role;
use App\Domain\Customer\Models\CustomerOtpCode;
use App\Domain\Employee\Models\Employee;
use App\Domain\Notification\Otp\OtpManager;
use App\Domain\Settings\SettingDefinition;
use App\Domain\Settings\SettingRegistry;
use App\Domain\Settings\SettingType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Tests\Support\AssertsIdor;
use Tests\Support\CustomerVerificationWorld;
use Tests\Support\CustomerWorld;
use Tests\Support\RecordingOtpChannel;

/*
 * T-074 — OTP kios (BR-165a, SEC-105, PA-6): hanya kanal WhatsApp, kode hash, berlaku & batas dari
 * setting `customer.otp_*`, kode tidak pernah bocor. Semua data fiktif.
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Jakarta'));
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, SequenceSeeder::class]);
    $this->useRealPermissions();
    config(['inertia.testing.ensure_pages_exist' => false, 'otp.channels.telegram.driver' => 'none']);

    $this->apj = User::factory()->withTwoFactor()->create()->assignRole('admin_penjualan');
    $this->area = CustomerWorld::area('AR-KDS', ['name' => 'Kudus']);
    $this->customer = CustomerWorld::customer($this->area, 'Toko Sumber Rejeki', ['owner_phone' => '6281234567001']);
    $this->send = fn () => $this->actingAs($this->apj)->postJson("/pelanggan/{$this->customer->id}/otp");
    $this->check = fn (string $code) => $this->actingAs($this->apj)->postJson("/pelanggan/{$this->customer->id}/otp/cek", ['code' => $code]);
});

test('SEC-105 CHANNEL_UNAVAILABLE: customer.otp_enabled=false → 503, walau driver WhatsApp ada', function () {
    config(['otp.channels.whatsapp.driver' => 'log']);
    setSetting('customer.otp_enabled', false);

    ($this->send)()->assertStatus(503)->assertJsonPath('error.code', 'CHANNEL_UNAVAILABLE');
    ($this->check)('123456')->assertStatus(503);
    expect(CustomerOtpCode::query()->count())->toBe(0);
});

test('SEC-105 PA-6 kanal WhatsApp tidak tersedia → 503; Telegram yang tersedia TIDAK dipakai untuk OTP kios', function () {
    config(['otp.channels.whatsapp.driver' => 'none', 'otp.channels.telegram.driver' => 'log']);
    setSetting('customer.otp_enabled', true);
    $channel = new RecordingOtpChannel;
    app(OtpManager::class)->fake($channel);

    ($this->send)()->assertStatus(503)->assertJsonPath('error.code', 'CHANNEL_UNAVAILABLE');
    expect($channel->sent)->toBe([]);
});

test('SEC-105 customer.otp_enabled hanya bisa disimpan true bila WhatsApp tersedia', function () {
    $definition = SettingRegistry::get('customer.otp_enabled');

    config(['otp.channels.whatsapp.driver' => 'none']);
    expect(($definition->check)(true, []))->toContain('belum bisa dipakai')
        ->and(($definition->check)(false, []))->toBeNull();

    config(['otp.channels.whatsapp.driver' => 'log']);
    expect(($definition->check)(true, []))->toBeNull();
});

test('BR-165 SEC-105 kirim OTP: WhatsApp ke HP pemilik, panjang auth.otp_length, berlaku customer.otp_minutes, kode tersimpan hash', function () {
    $channel = CustomerVerificationWorld::enableOtp();
    setSetting('auth.otp_length', 4);
    setSetting('customer.otp_minutes', 7);

    ($this->send)()->assertOk()
        ->assertJsonPath('data.phone', '0812•••••001')
        ->assertJsonPath('data.length', 4)
        ->assertJsonMissingPath('data.code');

    $code = $channel->lastCode();
    $row = CustomerOtpCode::query()->sole();
    expect($channel->sent[0]['address'])->toBe('6281234567001')
        ->and($code)->toHaveLength(4)
        ->and($row->code_hash)->not->toContain($code)
        ->and($row->code_hash)->toBe(hash_hmac('sha256', $code, (string) config('app.key')))
        ->and($row->expires_at->toDateTimeString())->toBe(now()->addMinutes(7)->toDateTimeString());

    ($this->check)($code === '0000' ? '1111' : '0000')->assertStatus(422)->assertJsonPath('error.code', 'OTP_INVALID');
    ($this->check)($code)->assertOk()->assertJsonPath('data.valid', true);

    // Kirim ulang menghanguskan kode lama.
    ($this->send)()->assertOk();
    expect(CustomerOtpCode::query()->where('id', $row->id)->value('expires_at') <= now())->toBeTrue();

    $this->travel(8)->minutes();
    ($this->check)((string) $channel->lastCode())->assertStatus(422)->assertJsonPath('error.code', 'OTP_EXPIRED');
});

test('SEC-105 RATE_LIMITED: percobaan melebihi customer.otp_max_attempts → 429 (angka dari setting)', function () {
    $channel = CustomerVerificationWorld::enableOtp();
    setSetting('customer.otp_max_attempts', 3);
    ($this->send)()->assertOk();
    $wrong = $channel->lastCode() === '000000' ? '111111' : '000000';

    foreach (range(1, 3) as $i) {
        ($this->check)($wrong)->assertStatus(422)->assertJsonPath('error.code', 'OTP_INVALID');
    }
    // Jatah habis: kode BENAR pun ditolak 429.
    ($this->check)((string) $channel->lastCode())->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    expect(CustomerOtpCode::query()->sole()->attempts)->toBe(3);
});

test('SEC-105 RATE_LIMITED: kirim melebihi customer.otp_send_per_phone_hour / per_user_hour → 429 (angka dari setting)', function () {
    CustomerVerificationWorld::enableOtp();
    setSetting('customer.otp_send_per_phone_hour', 2);

    ($this->send)()->assertOk();
    ($this->send)()->assertOk();
    ($this->send)()->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');

    // Satu jam kemudian jatah nomor pulih.
    $this->travel(61)->minutes();
    ($this->send)()->assertOk();

    // Per pengguna: kios lain, pengguna sama.
    setSetting('customer.otp_send_per_phone_hour', 10);
    setSetting('customer.otp_send_per_user_hour', 5);
    $others = collect(range(1, 5))->map(fn ($i) => CustomerWorld::customer($this->area, "Kios Uji {$i}"));
    foreach ($others->take(4) as $c) {
        $this->actingAs($this->apj)->postJson("/pelanggan/{$c->id}/otp")->assertOk();
    }
    $this->actingAs($this->apj)->postJson("/pelanggan/{$others->last()->id}/otp")->assertStatus(429);
});

test('SEC-105 kode OTP tidak pernah muncul di log aplikasi, audit_logs, security_events, maupun notifikasi; nomor di log termasking', function () {
    $channel = CustomerVerificationWorld::enableOtp();
    $logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) {
        $logs[] = $e->message.' '.json_encode($e->context, JSON_UNESCAPED_UNICODE);
    });

    ($this->send)()->assertOk();
    $code = (string) $channel->lastCode();
    ($this->check)($code)->assertOk();
    ($this->check)($code === '000000' ? '111111' : '000000')->assertStatus(422);

    $haystacks = [
        implode("\n", $logs),
        DB::table('audit_logs')->get()->toJson(),
        DB::table('security_events')->get()->toJson(),
        DB::table('notifications')->get()->toJson(),
    ];
    foreach ($haystacks as $text) {
        expect($text)->not->toContain($code);
    }
    // Log aplikasi hanya memuat nomor termasking.
    expect(implode("\n", $logs))->toContain('0812•••••001')->not->toContain('6281234567001');
});

test('SEC-105 pengiriman gagal → kode hangus & 503 (jalur telepon kantor)', function () {
    $channel = CustomerVerificationWorld::enableOtp();
    $channel->fail = true;

    ($this->send)()->assertStatus(503)->assertJsonPath('error.code', 'CHANNEL_UNAVAILABLE');
    expect(CustomerOtpCode::query()->sole()->expires_at <= now())->toBeTrue();
});

test('SEC-033 IDOR: kirim & cek OTP kios di luar scope → 404', function () {
    CustomerVerificationWorld::enableOtp();
    $role = Role::query()->create(['name' => 'uji_sales_otp', 'label' => 'Uji', 'guard_name' => 'web', 'data_scope' => Scope::Self]);
    $role->givePermissionTo(['customers.view', 'customers.manage', 'dashboard.view']);
    $sales = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole($role);

    $this->assertOutOfScope404($sales, 'POST', "/pelanggan/{$this->customer->id}/otp");
    $this->assertOutOfScope404($sales, 'POST', "/pelanggan/{$this->customer->id}/otp/cek", ['code' => '123456']);
});

test('T-074 SettingDefinition batas desimal: customer.duplicate_name_similarity 0.5–1 dibandingkan sebagai string', function () {
    $rules = SettingRegistry::get('customer.duplicate_name_similarity')->validationRules();
    $valid = fn (string $v) => Validator::make(['v' => $v], ['v' => $rules])->passes();

    expect($valid('0.5'))->toBeTrue()->and($valid('1'))->toBeTrue()->and($valid('0.85'))->toBeTrue()
        ->and($valid('0.49'))->toBeFalse()->and($valid('1.01'))->toBeFalse();
});

test('T-074 batas desimal hanya untuk tipe decimal/money', function () {
    new SettingDefinition('customer.contoh', SettingType::Int, 1, 'Contoh', min: '0.5');
})->throws(InvalidArgumentException::class);
