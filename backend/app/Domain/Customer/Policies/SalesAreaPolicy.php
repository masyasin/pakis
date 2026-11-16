<?php

namespace App\Domain\Customer\Policies;

use App\Domain\Customer\Models\SalesArea;
use App\Models\User;

/**
 * Area penjualan (15 §5, §6.4): master tanpa scope. Route juga dijaga `can:`; Policy lapisan kedua.
 */
class SalesAreaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales_areas.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sales_areas.manage');
    }

    public function update(User $user, SalesArea $area): bool
    {
        return $user->can('sales_areas.manage');
    }
}
