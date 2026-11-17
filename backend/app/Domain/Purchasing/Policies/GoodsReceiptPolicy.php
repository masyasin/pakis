<?php

namespace App\Domain\Purchasing\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Models\User;

/**
 * Penerimaan barang (15 §6, 06 §5): lihat = `goods_receipts.view` DAN lokasi stok dalam scope Stok
 * pengguna; di luar scope controller membalas 404 (IDOR). Ubah/periksa/posting/nomor seri =
 * `goods_receipts.create` + status. Aturan dua orang (SEC-104) dicek Action, bukan Policy, agar
 * pesannya `RECEIPT_SAME_PERSON`.
 */
class GoodsReceiptPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('goods_receipts.view');
    }

    public function view(User $user, GoodsReceipt $receipt): bool
    {
        return $user->can('goods_receipts.view') && $this->inScope($user, $receipt);
    }

    public function update(User $user, GoodsReceipt $receipt): bool
    {
        return $user->can('goods_receipts.create') && $receipt->status->isEditable() && $this->inScope($user, $receipt);
    }

    public function check(User $user, GoodsReceipt $receipt): bool
    {
        return $user->can('goods_receipts.create') && $receipt->status === GoodsReceiptStatus::Draft && $this->inScope($user, $receipt);
    }

    public function post(User $user, GoodsReceipt $receipt): bool
    {
        return $user->can('goods_receipts.create') && $receipt->status === GoodsReceiptStatus::Checked && $this->inScope($user, $receipt);
    }

    public function serials(User $user, GoodsReceipt $receipt): bool
    {
        return $this->update($user, $receipt);
    }

    public function inScope(User $user, GoodsReceipt $receipt): bool
    {
        return $this->scope->forStock(GoodsReceipt::query()->whereKey($receipt->id), $user, 'goods_receipts.stock_location_id')->exists();
    }
}
