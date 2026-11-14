<?php

use App\Domain\Notification\Otp\OtpManager;
use App\Domain\Settings\Actions\UpdateSettingGroup;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/*
 * BR-013 / OQ-004: driver OTP per kanal. `log` hanya untuk non-produksi; `none` = kanal tidak tersedia.
 */

uses(TestCase::class);

test('driver log di produksi → exception saat boot', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['otp.channels.whatsapp.driver' => 'log']);

    expect(fn () => OtpManager::assertSafeForEnvironment())
        ->toThrow(RuntimeException::class, 'tidak boleh dipakai di produksi');
});

test('driver tidak dikenal → exception', function () {
    config(['otp.channels.telegram.driver' => 'merpati']);

    expect(fn () => OtpManager::assertSafeForEnvironment())->toThrow(RuntimeException::class, 'tidak dikenal');
});

test('ketersediaan kanal: none tidak tersedia, log tersedia di lokal, bot butuh token', function () {
    config(['otp.channels.whatsapp.driver' => 'none', 'otp.channels.telegram.driver' => 'bot', 'otp.telegram.bot_token' => null]);
    expect(OtpManager::available('whatsapp'))->toBeFalse()
        ->and(OtpManager::available('telegram'))->toBeFalse();

    config(['otp.channels.whatsapp.driver' => 'log', 'otp.telegram.bot_token' => '1:abc', 'otp.telegram.bot_username' => 'PakisOneBot']);
    expect(OtpManager::available('whatsapp'))->toBeTrue()
        ->and(OtpManager::available('telegram'))->toBeTrue();
});

test('registri setting menolak kanal OTP yang drivernya tidak tersedia', function (string $channel) {
    config(["otp.channels.{$channel}.driver" => 'none']);

    expect(fn () => app(UpdateSettingGroup::class)->handle('auth', ['auth__forgot_channels' => ['email', $channel]], [], [], null))
        ->toThrow(ValidationException::class);
})->with(['whatsapp', 'telegram']);
