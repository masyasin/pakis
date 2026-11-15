<?php

namespace App\Domain\Access\Policies;

use App\Domain\Access\Actions\SaveRole;
use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Access\Support\ScopeResolver;
use App\Models\User;

/**
 * Aksi atas AKUN pengguna lain (SEC-009, BR-014, BR-031). Ability-nya dikecualikan dari
 * `Gate::before` Super Admin (AppServiceProvider::OWNER_PROTECTED), jadi aturan di sini berlaku
 * juga untuk Super Admin:
 * - tidak untuk akun sendiri (tidak bisa menaikkan hak / melepas MFA / mereset diri sendiri);
 * - akun Owner hanya bisa disentuh Owner (Super Admin vendor tidak bisa, 06 §2);
 * - **hak target ⊆ hak pelaku** (keputusan Mas 15 Nov 2026): permission & scope akun target tidak
 *   boleh melebihi milik pelaku. Tanpa ini Administrator bisa mereset password + MFA akun HRD lalu
 *   masuk sebagai HRD dan membuka NIK — padahal 06 §4 sengaja memisahkan keduanya;
 * - tetap butuh permission dasarnya.
 * Scope data (akun di luar scope → 404) ditegakkan lebih dulu oleh route binding ber-scope.
 */
final class UserPolicy
{
    /** Ubah data akun, nonaktif/aktifkan, cabut sesi (`users.manage-account`). */
    public function manageAccount(User $actor, User $target): bool
    {
        return ($actor->can('users.update') || $actor->can('users.deactivate')) && self::mayTouch($actor, $target);
    }

    public function assignRoles(User $actor, User $target): bool
    {
        return $actor->can('users.update') && self::mayTouch($actor, $target);
    }

    public function resetPasswordOf(User $actor, User $target): bool
    {
        return $actor->can('users.reset_password') && self::mayTouch($actor, $target);
    }

    public function resetMfaOf(User $actor, User $target): bool
    {
        return $actor->can('users.reset_mfa') && self::mayTouch($actor, $target);
    }

    public static function mayTouch(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if ($target->hasRole(RoleCatalog::OWNER) && ! $actor->hasRole(RoleCatalog::OWNER)) {
            return false;
        }

        return self::withinActor($actor, $target);
    }

    /** Semua permission & scope `$target` termasuk dalam milik `$actor`. */
    private static function withinActor(User $actor, User $target): bool
    {
        $beyond = array_diff(SaveRole::grantablePermissions($target), SaveRole::grantablePermissions($actor));

        return $beyond === []
            && ScopeResolver::rank(ScopeResolver::for($target)) <= ScopeResolver::rank(ScopeResolver::for($actor));
    }
}
