<?php

namespace App\Domain\Pricing\Policies;

use App\Domain\Pricing\Models\PriceListVersion;
use App\Models\User;

/**
 * Versi daftar harga (SEC-106): item hanya bisa diubah selama versi `draft`. Ini lapis pertama;
 * Action menolak dengan `PRICE_VERSION_LOCKED` dan trigger DB menjadi lapis kedua.
 */
class PriceListVersionPolicy
{
    public function view(User $user, PriceListVersion $version): bool
    {
        return $user->can('prices.view');
    }

    public function updateItems(User $user, PriceListVersion $version): bool
    {
        return $user->can('prices.manage') && $version->isDraft();
    }

    public function submit(User $user, PriceListVersion $version): bool
    {
        return $user->can('prices.manage') && $version->isDraft();
    }
}
