<?php

namespace App\Domain\Purchasing\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Purchasing\Enums\PurchaseReturnStatus;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Models\User;

/**
 * Retur ke prinsipal (15 §5, 06 §5): semua aksi = `purchase_returns.manage` DAN lokasi stok dalam scope
 * Stok pengguna; di luar scope controller membalas 404 (IDOR). Persetujuan lewat mesin approval
 * (`purchase_returns.approve`), bukan Policy ini.
 */
class PurchaseReturnPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('purchase_returns.manage');
    }

    public function view(User $user, PurchaseReturn $return): bool
    {
        return $user->can('purchase_returns.manage') && $this->inScope($user, $return);
    }

    public function update(User $user, PurchaseReturn $return): bool
    {
        return $this->view($user, $return) && $return->status === PurchaseReturnStatus::Draft;
    }

    public function ship(User $user, PurchaseReturn $return): bool
    {
        return $this->view($user, $return) && $return->status === PurchaseReturnStatus::Approved;
    }

    public function close(User $user, PurchaseReturn $return): bool
    {
        return $this->view($user, $return) && $return->status === PurchaseReturnStatus::Shipped;
    }

    public function cancel(User $user, PurchaseReturn $return): bool
    {
        return $this->view($user, $return) && $return->status->isCancellable();
    }

    public function inScope(User $user, PurchaseReturn $return): bool
    {
        return $this->scope->forStock(PurchaseReturn::query()->whereKey($return->id), $user, 'purchase_returns.stock_location_id')->exists();
    }
}
