<?php

namespace App\Domain\Fleet\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Fleet\Models\Vehicle;
use App\Models\User;

/**
 * Kendaraan (15 §5, §7.3 `vehicles`): permission + scope lokasi asal (dimensi Stok, 15 §6.2).
 * Route juga dijaga `can:` dan binding ber-scope (404); Policy ini lapisan kedua.
 */
class VehiclePolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('vehicles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('vehicles.manage');
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        // Kendaraan tanpa lokasi asal hanya dalam scope all/team.
        return $user->can('vehicles.manage') && $this->scope->allowsStockLocation($user, $vehicle->home_location_id);
    }
}
