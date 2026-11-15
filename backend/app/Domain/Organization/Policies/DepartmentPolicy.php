<?php

namespace App\Domain\Organization\Policies;

use App\Domain\Organization\Models\Department;
use App\Models\User;

/** Departemen (06 §3–§4): permission saja, tidak ber-scope data (06 §5). */
class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('departments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('departments.manage');
    }

    public function update(User $user, Department $department): bool
    {
        return $user->can('departments.manage');
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->can('departments.manage');
    }
}
