<?php

namespace App\Domain\Organization\Policies;

use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Support\LocationScope;
use App\Models\User;

/**
 * Lokasi (06 §3–§5): permission + scope. Route juga dijaga `can:` dan binding ber-scope (404);
 * Policy ini lapisan kedua agar aksi ubah/hapus tidak bergantung pada satu pengaman saja.
 */
class LocationPolicy
{
    public function __construct(private readonly LocationScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('locations.view');
    }

    public function create(User $user): bool
    {
        return $user->can('locations.manage');
    }

    public function update(User $user, Location $location): bool
    {
        return $user->can('locations.manage') && $this->scope->allows($user, $location);
    }

    public function delete(User $user, Location $location): bool
    {
        return $this->update($user, $location);
    }
}
