<?php

namespace App\Domain\Access\Policies;

use App\Domain\Access\Support\RoleCatalog;
use App\Models\User;

/**
 * Aksi atas AKUN pengguna lain yang dilindungi (SEC-009, BR-031). Ability-nya dikecualikan dari
 * `Gate::before` Super Admin (AppServiceProvider::OWNER_PROTECTED), jadi aturan di sini berlaku
 * juga untuk Super Admin:
 * - tidak untuk akun sendiri (tidak bisa menaikkan hak / melepas MFA sendiri);
 * - akun Owner hanya bisa diubah Owner (Super Admin vendor tidak bisa, 06 §2);
 * - tetap butuh permission dasarnya (`users.update` / `users.reset_mfa`).
 * Scope data atas akun (lewat pegawai terkait) ditambahkan di T-020.
 */
final class UserPolicy
{
    public function assignRoles(User $actor, User $target): bool
    {
        return $actor->can('users.update') && self::mayTouch($actor, $target);
    }

    public function resetMfaOf(User $actor, User $target): bool
    {
        return $actor->can('users.reset_mfa') && self::mayTouch($actor, $target);
    }

    private static function mayTouch(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        return ! $target->hasRole(RoleCatalog::OWNER) || $actor->hasRole(RoleCatalog::OWNER);
    }
}
