<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Notifications\ResetPasswordLink;
use App\Domain\Access\Support\PasswordResetBroker;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Notification\Otp\OtpService;
use App\Models\User;
use App\Support\Http\ClientNetwork;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Permintaan lupa password (FR-005, FR-006, BR-012, BR-013, BR-016, SEC-004).
 *
 * Respons SELALU sama apa pun hasilnya (akun ada/tidak ada, tanpa email/nomor/chat). Semua
 * pembatas (3/identifier/jam, 10/jaringan/jam, jeda OTP 60 dtk) memakai identifier yang dilipat
 * (huruf kecil, tanpa aksen) sehingga berlaku sama untuk identifier yang tidak ada.
 */
final class RequestPasswordReset
{
    public const SENT = 'Jika akun ditemukan, kami sudah mengirim petunjuk reset.';

    private const PER_IDENTIFIER_PER_HOUR = 3;

    private const PER_NETWORK_PER_HOUR = 10;

    private const OTP_RESEND_SECONDS = 60;

    public function __construct(
        private readonly SecurityLogger $security,
        private readonly OtpService $otp,
    ) {}

    public function handle(string $login, string $channel, ?string $ip): void
    {
        $identifier = AttemptLogin::normalize($login);
        $folded = Str::lower(Str::ascii($identifier));
        $network = ClientNetwork::key($ip);

        if (RateLimiter::tooManyAttempts('pw-reset:id:'.sha1($folded), self::PER_IDENTIFIER_PER_HOUR)
            || RateLimiter::tooManyAttempts('pw-reset:net:'.sha1($network), self::PER_NETWORK_PER_HOUR)) {
            throw ValidationException::withMessages(['login' => 'Terlalu banyak permintaan. Coba lagi nanti.']);
        }

        $cooldownKey = 'pw-reset:otp:'.sha1($folded.'|'.$channel);
        if ($channel !== 'email' && RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            throw ValidationException::withMessages(['login' => 'Tunggu 60 detik sebelum meminta kode lagi.']);
        }

        RateLimiter::hit('pw-reset:id:'.sha1($folded), 3600);
        RateLimiter::hit('pw-reset:net:'.sha1($network), 3600);
        if ($channel !== 'email') {
            RateLimiter::hit($cooldownKey, self::OTP_RESEND_SECONDS);
        }

        $user = $identifier === '' ? null : User::query()
            ->where(fn ($q) => $q->where('username', $identifier)->orWhere('email', $identifier))
            ->first();

        $this->security->record(SecurityEventType::PasswordResetRequested, SecurityChannel::Web, $user, $identifier, ['channel' => $channel]);

        if ($user === null || ! $user->is_active) {
            return;
        }

        match ($channel) {
            'email' => $this->sendLink($user),
            'whatsapp' => $user->phone ? $this->otp->issue($user, 'whatsapp', $user->phone) : null,
            'telegram' => $user->telegram_chat_id ? $this->otp->issue($user, 'telegram', $user->telegram_chat_id) : null,
            default => null,
        };
    }

    private function sendLink(User $user): void
    {
        // BR-012: email yang baru diganti & belum diverifikasi bisa saja milik orang lain.
        if (! $user->email || ! $user->hasVerifiedEmail()) {
            return;
        }

        // Broker menyimpan token sebagai hash & menghapusnya setelah dipakai (SEC-004).
        $token = PasswordResetBroker::make()->createToken($user);
        $user->notify(new ResetPasswordLink($token));
    }
}
