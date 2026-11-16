<?php

namespace App\Domain\Inventory\Policies;

use App\Domain\Inventory\Models\StockBin;
use App\Models\User;

/** Rak (15 §5 `admin.stock-bins.*`): izin & scope mengikuti lokasi stoknya. */
class StockBinPolicy
{
    public function __construct(private readonly StockLocationPolicy $locations) {}

    public function viewAny(User $user): bool
    {
        return $user->can('stock_locations.view');
    }

    public function update(User $user, StockBin $bin): bool
    {
        return $this->locations->manageBins($user, $bin->stockLocation);
    }
}
