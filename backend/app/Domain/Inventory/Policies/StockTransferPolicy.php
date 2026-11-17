<?php

namespace App\Domain\Inventory\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mutasi antar lokasi (15 §5/§6.6, BR-161). Lihat = `stock_transfers.view` DAN lokasi asal ATAU
 * tujuan dalam scope Stok (di luar itu controller membalas 404, IDOR 06 §5). Ubah/kirim/batal =
 * `stock_transfers.create` di lokasi ASAL; terima = `stock_transfers.receive` di lokasi TUJUAN.
 * Penerima ≠ pengirim diperiksa Action (`TRANSFER_SAME_PERSON` 403 berpesan, bukan tombol hilang).
 */
class StockTransferPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('stock_transfers.view');
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock_transfers.view')
            && $this->visible(StockTransfer::query()->whereKey($transfer->id), $user)->exists();
    }

    public function update(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock_transfers.create') && $transfer->status === StockTransferStatus::Draft && $this->atSource($user, $transfer);
    }

    public function ship(User $user, StockTransfer $transfer): bool
    {
        return $this->update($user, $transfer);
    }

    public function cancel(User $user, StockTransfer $transfer): bool
    {
        return $this->update($user, $transfer);
    }

    /**
     * Terima pertama (`shipped`) atau hitung ulang setelah selisih ditolak/ditarik
     * (`received_with_variance` tanpa permintaan approval aktif, PA-66).
     */
    public function receive(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock_transfers.receive') && $transfer->acceptsReceipt() && $this->atDestination($user, $transfer);
    }

    /**
     * Query daftar: mutasi yang lokasi asal ATAU tujuannya dalam scope Stok pengguna. `forStock`
     * dipakai dua kali dalam satu grup OR agar definisi scope tetap satu (DataScope).
     *
     * @param  Builder<StockTransfer>  $query
     * @return Builder<StockTransfer>
     */
    public function visible(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $from) => $this->scope->forStock($from, $user, 'stock_transfers.from_stock_location_id'))
            ->orWhere(fn (Builder $to) => $this->scope->forStock($to, $user, 'stock_transfers.to_stock_location_id')));
    }

    public function atSource(User $user, StockTransfer $transfer): bool
    {
        return $this->scope->forStock(StockTransfer::query()->whereKey($transfer->id), $user, 'stock_transfers.from_stock_location_id')->exists();
    }

    public function atDestination(User $user, StockTransfer $transfer): bool
    {
        return $this->scope->forStock(StockTransfer::query()->whereKey($transfer->id), $user, 'stock_transfers.to_stock_location_id')->exists();
    }
}
