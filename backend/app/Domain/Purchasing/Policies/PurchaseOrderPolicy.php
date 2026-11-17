<?php

namespace App\Domain\Purchasing\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Models\User;

/**
 * PO (15 §6, 06 §5): lihat = `purchase_orders.view` DAN gudang tujuan dalam scope Stok pengguna
 * (staf gudang hanya PO ke lokasinya). Di luar scope controller membalas 404 (bukan 403) agar
 * keberadaan PO tidak bocor (IDOR). Ubah/ajukan/batal/tutup = `purchase_orders.create` + status.
 */
class PurchaseOrderPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('purchase_orders.view');
    }

    public function view(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchase_orders.view') && $this->inScope($user, $order);
    }

    public function update(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchase_orders.create') && $order->status === PurchaseOrderStatus::Draft && $this->inScope($user, $order);
    }

    public function cancel(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchase_orders.create') && in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Rejected], true)
            && $this->inScope($user, $order);
    }

    public function close(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchase_orders.create') && $order->status->isOpen() && $this->inScope($user, $order);
    }

    public function inScope(User $user, PurchaseOrder $order): bool
    {
        return $this->scope->forStock(PurchaseOrder::query()->whereKey($order->id), $user, 'purchase_orders.stock_location_id')->exists();
    }
}
