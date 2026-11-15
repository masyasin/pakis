<?php

namespace App\Domain\Employee\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Organization\Support\LocationScope;
use App\Models\User;

/**
 * Penugasan (06 §3–§5): permission + scope data atas pegawainya. Route `{assignment}` sudah 404 di
 * luar scope (binding ber-scope); Policy ini lapisan kedua untuk setiap aksi atas penugasan tertentu.
 */
class AssignmentPolicy
{
    public function __construct(
        private readonly DataScope $scope,
        private readonly LocationScope $locations,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('assignments.view');
    }

    /**
     * Pegawainya dalam scope DAN lokasi penugasan itu dalam scope lokasi pelaku — sama dengan syarat
     * saat membuat penugasan. Tanpa cek lokasi, pelaku ber-scope lokasi bisa mengubah/mengakhiri
     * penugasan rangkap pegawainya di lokasi lain yang bukan wewenangnya.
     */
    public function update(User $user, Assignment $assignment): bool
    {
        $location = $assignment->location;

        return $user->can('assignments.manage')
            && $this->scope->allows($user, $assignment->employee_id)
            && $location !== null
            && $this->locations->allows($user, $location);
    }

    public function end(User $user, Assignment $assignment): bool
    {
        return $this->update($user, $assignment);
    }
}
