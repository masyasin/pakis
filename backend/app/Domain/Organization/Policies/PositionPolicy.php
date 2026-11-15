<?php

namespace App\Domain\Organization\Policies;

use App\Domain\Organization\Models\Position;
use App\Models\User;

/** Jabatan (06 §3–§4): permission saja, tidak ber-scope data (06 §5). */
class PositionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('positions.view');
    }

    public function create(User $user): bool
    {
        return $user->can('positions.manage');
    }

    public function update(User $user, Position $position): bool
    {
        return $user->can('positions.manage');
    }

    public function delete(User $user, Position $position): bool
    {
        return $user->can('positions.manage');
    }
}
