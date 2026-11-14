<?php

namespace App\Domain\Access\Listeners;

use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Http\Middleware\RecoveryCodesOnce;
use App\Models\User;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Event MFA Fortify → `security_events` (09 §7, T-016), dan izin sekali-lihat kode pemulihan.
 * `mfa_enabled` dicatat saat KONFIRMASI (kode pertama benar), bukan saat secret dibuat, karena
 * MFA baru benar-benar berlaku setelah dikonfirmasi.
 */
final class RecordTwoFactorEvents
{
    public function __construct(private readonly SecurityLogger $security) {}

    public function confirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->record(SecurityEventType::MfaEnabled, $event->user);
    }

    public function disabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->record(SecurityEventType::MfaDisabled, $event->user);
    }

    public function failed(TwoFactorAuthenticationFailed $event): void
    {
        $this->record(SecurityEventType::MfaFailed, $event->user);
    }

    /** Aktivasi (Enabled) juga membuat kode pemulihan, tanpa memicu RecoveryCodesGenerated. */
    public function recoveryCodesGenerated(RecoveryCodesGenerated|TwoFactorAuthenticationEnabled $event): void
    {
        // Hanya request web ber-sesi yang membuat kode (aktivasi / buat ulang di Profil).
        if (app()->bound('session.store') && request()->hasSession()) {
            request()->session()->put(RecoveryCodesOnce::SESSION_FLAG, true);
        }
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(TwoFactorAuthenticationConfirmed::class, [self::class, 'confirmed']);
        $events->listen(TwoFactorAuthenticationDisabled::class, [self::class, 'disabled']);
        $events->listen(TwoFactorAuthenticationFailed::class, [self::class, 'failed']);
        $events->listen(RecoveryCodesGenerated::class, [self::class, 'recoveryCodesGenerated']);
        $events->listen(TwoFactorAuthenticationEnabled::class, [self::class, 'recoveryCodesGenerated']);
    }

    private function record(SecurityEventType $type, mixed $user): void
    {
        if ($user instanceof User) {
            $this->security->record($type, SecurityChannel::Web, $user, $user->username);
        }
    }
}
