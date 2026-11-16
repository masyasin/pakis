<?php

namespace App\Domain\Inventory\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;

/**
 * Lokasi stok (15 §5, §6.2): permission + dimensi Stok. Route juga dijaga `can:` dan binding
 * ber-scope (404); Policy ini lapisan kedua. TRANSIT (lokasi sistem) tidak pernah bisa diubah → 403.
 */
class StockLocationPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('stock_locations.view');
    }

    public function view(User $user, StockLocation $location): bool
    {
        return $this->viewAny($user) && $this->inScope($user, $location);
    }

    public function create(User $user): bool
    {
        return $user->can('stock_locations.manage');
    }

    public function update(User $user, StockLocation $location): bool
    {
        return ! $location->isProtected()
            && $user->can('stock_locations.manage')
            && $this->inScope($user, $location);
    }

    /** Rak hanya untuk gudang/toko aktif maupun nonaktif yang dalam scope (15 §4.8). */
    public function manageBins(User $user, StockLocation $location): bool
    {
        return $location->type->hasBins()
            && $user->can('stock_locations.manage')
            && $this->inScope($user, $location);
    }

    private function inScope(User $user, StockLocation $location): bool
    {
        return $this->scope->forStock(StockLocation::query()->whereKey($location->id), $user, 'stock_locations.id')->exists();
    }
}
