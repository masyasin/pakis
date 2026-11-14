<?php

use App\Domain\Settings\SettingRegistry;
use App\Http\Controllers\Api\V1\ConfigController;
use Illuminate\Support\Arr;

/*
 * SEC-062: GET /api/v1/config hanya memuat kunci `isPublic` registri, dengan bentuk 05 §5.2.
 */

test('SEC-062 peta config identik dengan kunci publik registri (dua arah)', function () {
    expect(array_keys(ConfigController::MAP))->toEqualCanonicalizing(array_keys(SettingRegistry::public()));
});

test('SEC-062 respons tidak memuat nilai setting non-publik', function () {
    setSetting('attendance.default_radius_m', 4321);
    setSetting('security.login_max_attempts', 17);

    $body = $this->getJson('/api/v1/config')->assertOk()->getContent();

    expect($body)->not->toContain('4321')
        ->not->toContain('default_radius')
        ->not->toContain('login_max_attempts');
});

test('SEC-062 setiap path di respons berasal dari peta, tidak ada tambahan', function () {
    $data = $this->getJson('/api/v1/config')->json('data');

    // Array list (mis. forgot_channels) adalah satu nilai, bukan cabang.
    $paths = collect(Arr::dot($data))->keys()
        ->map(fn (string $path) => preg_replace('/\.\d+$/', '', $path))
        ->unique()->values()->all();

    expect($paths)->toEqualCanonicalizing(array_values(ConfigController::MAP));
});

test('config publik memakai bentuk 05 §5.2 dan bisa diakses tanpa login', function () {
    setSetting('mobile.latest_version', '1.0.3');

    $this->getJson('/api/v1/config')
        ->assertOk()
        ->assertJsonPath('data.app_name', 'PAKIS ONE')
        ->assertJsonPath('data.company_name', 'CV Pakis Jaya Abadi')
        ->assertJsonPath('data.primary_color', '#2F6B3B')
        ->assertJsonPath('data.timezone', 'Asia/Jakarta')
        ->assertJsonPath('data.mobile.latest_version', '1.0.3')
        ->assertJsonPath('data.attendance.require_selfie', true)
        ->assertJsonPath('data.attendance.max_accuracy_m', 100)
        ->assertJsonPath('data.auth.password_min_length', 10)
        ->assertJsonPath('data.auth.forgot_channels', ['email'])
        ->assertJsonStructure(['meta' => ['request_id']]);
});
