<?php

namespace App\Domain\Inventory\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Models\StockOpname;
use App\Models\User;

/**
 * Stock opname (15 §5/§6.6, BR-162). Lihat = `stock_opnames.view` DAN lokasi dalam scope Stok
 * (di luar → 404 di controller, IDOR 06 §5). Semua aksi = `stock_opnames.manage` + status yang
 * cocok; hitung ulang ≠ penghitung pertama diperiksa Action (pesan di form).
 */
class StockOpnamePolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('stock_opnames.view');
    }

    public function view(User $user, StockOpname $opname): bool
    {
        return $user->can('stock_opnames.view') && $this->inScope($user, $opname);
    }

    public function start(User $user, StockOpname $opname): bool
    {
        return $this->manage($user, $opname, [StockOpnameStatus::Draft]);
    }

    /** Isi lembar hitung: hitung (counting), hitung ulang (counting/review), alasan (review). */
    public function count(User $user, StockOpname $opname): bool
    {
        return $this->manage($user, $opname, [StockOpnameStatus::Counting, StockOpnameStatus::Review]);
    }

    public function finish(User $user, StockOpname $opname): bool
    {
        return $this->manage($user, $opname, [StockOpnameStatus::Counting]);
    }

    public function post(User $user, StockOpname $opname): bool
    {
        return $this->manage($user, $opname, [StockOpnameStatus::Review]);
    }

    public function cancel(User $user, StockOpname $opname): bool
    {
        return $this->manage($user, $opname, [StockOpnameStatus::Draft, StockOpnameStatus::Counting, StockOpnameStatus::Review]);
    }

    public function inScope(User $user, StockOpname $opname): bool
    {
        return $this->scope->forStock(StockOpname::query()->whereKey($opname->id), $user, 'stock_opnames.stock_location_id')->exists();
    }

    /** @param  list<StockOpnameStatus>  $statuses */
    private function manage(User $user, StockOpname $opname, array $statuses): bool
    {
        return $user->can('stock_opnames.manage') && in_array($opname->status, $statuses, true) && $this->inScope($user, $opname);
    }
}
