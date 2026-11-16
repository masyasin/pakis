<?php

namespace App\Domain\Customer\Otp;

use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Enums\VerificationType;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerOtpCode;
use App\Domain\Notification\Otp\OtpManager;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Phone\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * OTP kios (BR-165, BR-166(4), SEC-105, PA-6). Meniru pola OtpService Fase 0 (HMAC + hash_equals,
 * percobaan dihitung atomik SEBELUM membandingkan), tetapi:
 * - kode & percobaan disimpan di `customer_otp_codes` (kios bukan `User`, OtpService per-User);
 * - kirim HANYA lewat `OtpManager->channel('whatsapp')` ke nomor kios: tujuannya membuktikan
 *   kepemilikan nomor itu, jadi Telegram/email tidak pernah dipakai;
 * - semua angka dari setting `customer.otp_*` + `auth.otp_length`.
 *
 * Kode tidak pernah masuk log/audit/notifikasi; nomor di log & audit selalu termasking. Satu-satunya
 * pengecualian adalah driver `log` Fase 0 (LogOtpChannel, ditolak di produksi) yang memang menulis
 * pesan ke log `otp` untuk pengembangan.
 */
final class CustomerOtpService
{
    public function __construct(
        private readonly OtpManager $otp,
        private readonly AuditLogger $audit,
    ) {}

    /** OTP bisa dipakai sekarang: setting menyala DAN driver WhatsApp tersedia di lingkungan ini. */
    public static function available(): bool
    {
        return (bool) setting('customer.otp_enabled') && OtpManager::available('whatsapp');
    }

    public static function assertAvailable(): void
    {
        if (! self::available()) {
            throw new ApiError(ErrorCode::CHANNEL_UNAVAILABLE, publicMessage: 'OTP WhatsApp sedang tidak tersedia. Pakai verifikasi telepon kantor.');
        }
    }

    /**
     * Kirim kode baru ke `$phone`. Kode lama yang belum terpakai untuk kios & tujuan yang sama
     * dihanguskan, sehingga hanya kode terakhir yang berlaku.
     */
    public function send(Customer $customer, VerificationType $purpose, string $phone, User $actor): CustomerOtpCode
    {
        self::assertAvailable();

        // Throttle per nomor & per pengguna (SEC-105). Keduanya diperiksa dulu baru dihitung, agar
        // penolakan oleh satu batas tidak memakan jatah batas yang lain.
        $limits = [
            'customer-otp:phone:'.$phone => (int) setting('customer.otp_send_per_phone_hour'),
            'customer-otp:user:'.$actor->id => (int) setting('customer.otp_send_per_user_hour'),
        ];
        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ApiError(ErrorCode::RATE_LIMITED, ['retry_after' => RateLimiter::availableIn($key)],
                    'Batas kirim OTP per jam sudah tercapai. Coba lagi nanti atau pakai telepon kantor.');
            }
        }
        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 3600);
        }

        $length = (int) setting('auth.otp_length');
        $minutes = (int) setting('customer.otp_minutes');
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        $row = DB::transaction(function () use ($customer, $purpose, $phone, $actor, $code, $minutes) {
            CustomerOtpCode::query()
                ->where('customer_id', $customer->id)->where('purpose', $purpose)
                ->whereNull('consumed_at')->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);

            $row = CustomerOtpCode::query()->create([
                'customer_id' => $customer->id,
                'purpose' => $purpose,
                'phone' => $phone,
                'code_hash' => self::hash($code),
                'expires_at' => now()->addMinutes($minutes),
                'attempts' => 0,
                'sent_by' => $actor->id,
            ]);

            $this->audit->record('customer.otp_sent', $customer, null, [
                'purpose' => $purpose->value,
                'phone' => PhoneNormalizer::mask($phone),
            ]);

            return $row;
        });

        $app = (string) setting('general.app_name');
        try {
            $this->otp->channel('whatsapp')->send($phone, "Kode verifikasi kios {$app}: {$code}. Berlaku {$minutes} menit. Berikan kode ini HANYA kepada petugas yang sedang memverifikasi toko Anda.");
        } catch (Throwable $e) {
            // Pengiriman gagal → kode dihanguskan; jalur pengganti = telepon kantor (BR-165b).
            $row->forceFill(['expires_at' => now()])->save();
            Log::warning('OTP kios gagal dikirim', ['customer_id' => $customer->id, 'phone' => PhoneNormalizer::mask($phone), 'error' => $e::class]);

            throw new ApiError(ErrorCode::CHANNEL_UNAVAILABLE, publicMessage: 'OTP WhatsApp gagal dikirim. Coba lagi atau pakai verifikasi telepon kantor.');
        }

        Log::info('OTP kios dikirim', ['customer_id' => $customer->id, 'purpose' => $purpose->value, 'phone' => PhoneNormalizer::mask($phone)]);

        return $row;
    }

    /**
     * Cocokkan kode dengan kode TERAKHIR kios & tujuan ini yang dikirim ke `$phone`. Setiap
     * pemeriksaan memakai satu jatah percobaan (dinaikkan atomik di DB sebelum dibandingkan, jadi
     * permintaan paralel tidak bisa melewati batas). Tidak menghabiskan kode: pemakai akhir memanggil
     * consume().
     *
     * - tidak ada kode / lewat masa berlaku / sudah dipakai / nomor berbeda → 422 `OTP_EXPIRED`
     * - jatah percobaan habis → 429 `RATE_LIMITED`
     * - salah → 422 `OTP_INVALID`
     */
    public function verify(Customer $customer, VerificationType $purpose, string $phone, string $code): CustomerOtpCode
    {
        $row = CustomerOtpCode::query()
            ->where('customer_id', $customer->id)->where('purpose', $purpose)
            ->orderByDesc('id')
            ->first();
        if ($row === null || $row->consumed_at !== null || $row->phone !== $phone || $row->expires_at->lte(now())) {
            throw new ApiError(ErrorCode::OTP_EXPIRED, ['code' => ErrorCode::OTP_EXPIRED->message()]);
        }

        $max = (int) setting('customer.otp_max_attempts');
        // Increment bersyarat = satu pernyataan atomik; 0 baris = jatah habis (atau baru dipakai/hangus).
        $counted = CustomerOtpCode::query()->whereKey($row->id)
            ->whereNull('consumed_at')->where('expires_at', '>', now())->where('attempts', '<', $max)
            ->increment('attempts');
        if ($counted === 0) {
            $fresh = $row->fresh();
            if ($fresh !== null && $fresh->consumed_at === null && $fresh->expires_at->gt(now())) {
                throw new ApiError(ErrorCode::RATE_LIMITED, publicMessage: 'Terlalu banyak percobaan kode. Kirim ulang kode baru.');
            }
            throw new ApiError(ErrorCode::OTP_EXPIRED, ['code' => ErrorCode::OTP_EXPIRED->message()]);
        }

        if (! hash_equals($row->code_hash, self::hash(trim($code)))) {
            throw new ApiError(ErrorCode::OTP_INVALID, ['code' => ErrorCode::OTP_INVALID->message()]);
        }

        return $row;
    }

    /**
     * Habiskan kode yang sudah lolos verify() (sekali pakai). Dipanggil DI DALAM transaksi pemakai
     * (kirim verifikasi), sehingga kode tetap berlaku bila transaksi itu gagal. Dua pemakaian
     * bersamaan: hanya satu yang mengubah baris, yang lain → `OTP_EXPIRED`.
     */
    public function consume(CustomerOtpCode $row): void
    {
        $done = CustomerOtpCode::query()->whereKey($row->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        if ($done === 0) {
            throw new ApiError(ErrorCode::OTP_EXPIRED, ['code' => ErrorCode::OTP_EXPIRED->message()]);
        }
    }

    private static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
