<?php

namespace App\Domain\Access\Listeners;

use App\Domain\Access\Notifications\NewDeviceLogin;
use App\Domain\Access\Support\DeviceFingerprint;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Http\Middleware\EnforceSessionLifetime;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Efek login web yang BENAR-BENAR terjadi (event `Login`, termasuk setelah tantangan 2FA di T-016):
 * jejak login, `login_success`, penanda umur sesi (BR-018), dan deteksi perangkat baru.
 */
final class RecordSuccessfulLogin
{
    public function __construct(
        private readonly SecurityLogger $security,
        private readonly Request $request,
    ) {}

    public function handle(Login $event): void
    {
        $user = $event->user;
        if (! $user instanceof User) {
            return;
        }

        $fingerprint = DeviceFingerprint::of($this->request);
        $hadLogin = DeviceFingerprint::hasPreviousLogin($user);
        $known = $hadLogin && DeviceFingerprint::isKnown($user, $fingerprint);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $this->request->ip(),
            'locked_until' => null,
        ])->saveQuietly();

        EnforceSessionLifetime::start($this->request);

        $this->security->record(SecurityEventType::LoginSuccess, SecurityChannel::Web, $user, $user->username, ['device' => $fingerprint]);

        if ($known) {
            return;
        }

        $this->security->record(SecurityEventType::NewDeviceLogin, SecurityChannel::Web, $user, $user->username, ['device' => $fingerprint]);

        // Login pertama kali tidak diberi email: belum ada perangkat lama sebagai pembanding.
        if ($hadLogin && $user->email && setting('security.notify_new_device_login')) {
            $user->notify(new NewDeviceLogin(now(), (string) $this->request->ip(), (string) $this->request->userAgent()));
        }
    }
}
