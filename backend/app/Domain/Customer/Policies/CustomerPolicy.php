<?php

namespace App\Domain\Customer\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Customer\Models\Customer;
use App\Models\User;

/**
 * Kios (15 §5, §6.2): permission + dimensi Pelanggan. Route juga dijaga `can:` dan binding
 * ber-scope (404); Policy ini lapisan kedua.
 */
class CustomerPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->viewAny($user) && $this->scope->allowsCustomer($user, $customer->id);
    }

    /** Scope `self` belum bisa membuat/mengalihkan kios dari web (PA-47): tidak ada area dalam scope. */
    public function create(User $user): bool
    {
        return $user->can('customers.manage') && $this->scope->assignableSalesAreaIds($user) !== [];
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.manage') && $this->scope->allowsCustomer($user, $customer->id);
    }

    public function reassign(User $user): bool
    {
        return $this->create($user);
    }

    /** HP penuh di daftar & detail hanya untuk pengelola (SEC-108); ekspor selalu termasking. */
    public function viewFullPhone(User $user): bool
    {
        return $user->can('customers.manage');
    }
}
