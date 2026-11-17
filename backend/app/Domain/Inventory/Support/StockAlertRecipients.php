<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Access\DataScope;
use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Penerima alert stok per lokasi stok (15 §9.1 `batch_near_expiry`/`batch_expired`, BR-157):
 * pemegang role `kepala_gudang` yang aktif dan dimensi Stok-nya mencakup lokasi induk lokasi stok
 * itu (`stock_locations.location_id`, definisi lokasi yang sama dengan DataScope, PA-13).
 *
 * Lokasi tanpa kepala gudang (kendaraan canvas, TRANSIT, toko) → Owner, agar alert tidak hilang
 * diam-diam (keputusan Mas 17 Nov 2026, PA-64). Hasil di-memo per lokasi untuk satu run job.
 */
final class StockAlertRecipients
{
    /** @var Collection<int, User>|null */
    private ?Collection $heads = null;

    /** @var array<int, Collection<int, User>> */
    private array $memo = [];

    public function __construct(private readonly DataScope $scope) {}

    /** @return Collection<int, User> */
    public function for(StockLocation $location): Collection
    {
        if (isset($this->memo[$location->id])) {
            return $this->memo[$location->id];
        }

        $this->heads ??= User::role('kepala_gudang')->where('is_active', true)->orderBy('id')->get();
        $recipients = $this->heads
            ->filter(fn (User $user) => $this->scope->allowsStockLocation($user, $location->location_id))
            ->values();

        if ($recipients->isEmpty()) {
            $recipients = User::role(RoleCatalog::OWNER)->where('is_active', true)->orderBy('id')->get();
        }

        return $this->memo[$location->id] = $recipients;
    }
}
