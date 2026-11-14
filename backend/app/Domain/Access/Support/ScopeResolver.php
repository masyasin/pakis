<?php

namespace App\Domain\Access\Support;

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Models\User;

/**
 * Scope data efektif pengguna (BR-032, 06 §2/§11): scope TERLUAS dari semua role-nya
 * (`all` > `location` > `team` > `self`). Super Admin = `all`. Tanpa role = `self`.
 */
final class ScopeResolver
{
    public static function for(User $user): DataScope
    {
        if ($user->hasRole(RoleCatalog::SUPER_ADMIN)) {
            return DataScope::All;
        }

        $widest = DataScope::Self;
        foreach ($user->roles as $role) {
            if ($role instanceof Role && self::rank($role->data_scope) > self::rank($widest)) {
                $widest = $role->data_scope;
            }
        }

        return $widest;
    }

    public static function rank(DataScope $scope): int
    {
        return match ($scope) {
            DataScope::All => 3,
            DataScope::Location => 2,
            DataScope::Team => 1,
            DataScope::Self => 0,
        };
    }
}
