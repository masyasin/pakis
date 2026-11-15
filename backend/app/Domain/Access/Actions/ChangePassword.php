<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Notifications\PasswordChanged;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Device\Support\DeviceTokens;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ganti password oleh pemiliknya sendiri (FR-004, FR-007). Password SUDAH divalidasi
 * PasswordPolicy oleh pemanggil.
 *
 * Sesi web lain otomatis keluar lewat middleware AuthenticateSession (hash password di sesi tidak
 * cocok lagi); sesi yang dipakai mengganti tetap hidup karena hash-nya diperbarui di akhir request.
 * Token API (Pakis Pro) ikut dicabut, kecuali token perangkat yang sedang dipakai mengganti (T-031).
 */
final class ChangePassword
{
    public function __construct(
        private readonly SecurityLogger $security,
        private readonly DeviceTokens $tokens,
    ) {}

    public function handle(User $user, string $password, SecurityChannel $channel = SecurityChannel::Web, ?int $keepTokenId = null): void
    {
        DB::transaction(function () use ($user, $password, $channel, $keepTokenId) {
            $user->forceFill([
                'password' => $password,
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();

            $this->security->record(SecurityEventType::PasswordChanged, $channel, $user, $user->username);
            $this->tokens->revokeForUser($user, 'password_changed', $keepTokenId);
        });

        if ($user->email) {
            $user->notify(new PasswordChanged(now()));
        }
    }
}
