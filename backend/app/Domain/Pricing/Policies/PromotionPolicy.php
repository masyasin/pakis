<?php

namespace App\Domain\Pricing\Policies;

use App\Domain\Pricing\Enums\PromotionStatus;
use App\Domain\Pricing\Models\Promotion;
use App\Models\User;

/** Promo (15 §6): `promotions.view` / `promotions.manage`; ubah hanya draf, akhiri hanya yang aktif. */
class PromotionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('promotions.view');
    }

    public function update(User $user, Promotion $promotion): bool
    {
        return $user->can('promotions.manage') && $promotion->status === PromotionStatus::Draft;
    }

    public function end(User $user, Promotion $promotion): bool
    {
        return $user->can('promotions.manage') && $promotion->status === PromotionStatus::Active;
    }
}
