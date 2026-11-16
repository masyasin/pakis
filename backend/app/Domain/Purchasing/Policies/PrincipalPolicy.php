<?php

namespace App\Domain\Purchasing\Policies;

use App\Domain\Purchasing\Models\Principal;
use App\Models\User;

/** Prinsipal (15 §6): `suppliers.view` / `suppliers.manage`, master tanpa scope data. */
class PrincipalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('suppliers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('suppliers.manage');
    }

    public function update(User $user, Principal $principal): bool
    {
        return $user->can('suppliers.manage');
    }
}
