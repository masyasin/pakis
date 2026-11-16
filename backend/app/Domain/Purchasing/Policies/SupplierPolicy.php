<?php

namespace App\Domain\Purchasing\Policies;

use App\Domain\Purchasing\Models\Supplier;
use App\Models\User;

/**
 * Supplier (15 §6): lihat = `suppliers.view`, ubah & buka rekening lengkap = `suppliers.manage`
 * (SEC-108: rekening termasking untuk pemegang `.view` saja). Master tanpa scope data.
 */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('suppliers.view');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->can('suppliers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('suppliers.manage');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->can('suppliers.manage');
    }

    public function revealAccount(User $user, Supplier $supplier): bool
    {
        return $user->can('suppliers.manage');
    }
}
