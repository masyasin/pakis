<?php

namespace App\Domain\Access\Support;

use App\Domain\Access\Models\Role;
use App\Models\User;

/**
 * BR-015 / 06 §2: MFA wajib bila SALAH SATU role pengguna `mfa_required`. Dipakai middleware
 * EnforceMfa (paksa aktivasi), larangan menonaktifkan MFA, dan (T-031) login API.
 */
final class MfaRequirement
{
    public static function requires(User $user): bool
    {
        return $user->roles->contains(fn ($role) => $role instanceof Role && $role->mfa_required);
    }

    /** Wajib tetapi belum aktif (Fortify: `two_factor_confirmed_at` terisi = aktif). */
    public static function pending(User $user): bool
    {
        return self::requires($user) && ! $user->hasEnabledTwoFactorAuthentication();
    }
}
