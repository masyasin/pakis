<?php

namespace App\Domain\Pricing\Policies;

use App\Domain\Pricing\Models\PriceList;
use App\Models\User;

/** Daftar harga (15 §6): `prices.view` / `prices.manage`, master tanpa scope data. */
class PriceListPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('prices.view');
    }

    public function view(User $user, PriceList $list): bool
    {
        return $user->can('prices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('prices.manage');
    }

    public function update(User $user, PriceList $list): bool
    {
        return $user->can('prices.manage');
    }
}
