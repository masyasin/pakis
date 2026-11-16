<?php

namespace Tests\Support;

use App\Domain\Notification\Otp\OtpManager;
use Illuminate\Http\UploadedFile;

/**
 * Data & alat uji verifikasi kios (T-074). Semua fiktif. OTP dikirim ke kanal perekam; driver
 * WhatsApp `log` di test = kanal "tersedia" (bukan produksi), Telegram dibiarkan `none` kecuali test
 * yang memang membuktikan Telegram tidak pernah dipakai.
 */
final class CustomerVerificationWorld
{
    /** Nyalakan OTP kios lewat WhatsApp + kanal perekam. */
    public static function enableOtp(): RecordingOtpChannel
    {
        config(['otp.channels.whatsapp.driver' => 'log']);
        setSetting('customer.otp_enabled', true);
        $channel = new RecordingOtpChannel;
        app(OtpManager::class)->fake($channel);

        return $channel;
    }

    public static function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('toko.jpg', 640, 480);
    }

    /** @return array<string, mixed> isian kirim verifikasi yang sah */
    public static function payload(string $method = 'office_call', array $override = []): array
    {
        return [
            'method' => $method,
            'photo' => self::photo(),
            'latitude' => '-6.8012345',
            'longitude' => '110.9123456',
            'accuracy_m' => 12,
            'requested_credit_limit' => '5000000',
            ...$override,
        ];
    }
}
