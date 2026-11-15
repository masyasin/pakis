<?php

namespace App\Domain\Employee\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Employee\Models\Assignment;
use App\Models\User;

/**
 * Penugasan (06 §3–§5): permission + scope data atas pegawainya. Route `{assignment}` sudah 404 di
 * luar scope (binding ber-scope); Policy ini lapisan kedua untuk setiap aksi atas penugasan tertentu.
 */
class AssignmentPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('assignments.view');
    }

    public function update(User $user, Assignment $assignment): bool
    {
        return $user->can('assignments.manage') && $this->scope->allows($user, $assignment->employee_id);
    }

    public function end(User $user, Assignment $assignment): bool
    {
        return $this->update($user, $assignment);
    }
}
