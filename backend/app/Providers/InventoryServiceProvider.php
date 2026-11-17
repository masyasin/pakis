<?php

namespace App\Providers;

use App\Domain\Fleet\Models\Vehicle;
use App\Domain\Fleet\Policies\VehiclePolicy;
use App\Domain\Inventory\Lookup\AvailableBatchLookup;
use App\Domain\Inventory\Lookup\StockBinLookup;
use App\Domain\Inventory\Lookup\StockLocationLookup;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Policies\StockBinPolicy;
use App\Domain\Inventory\Policies\StockLocationPolicy;
use App\Domain\Inventory\Support\LedgerStockBalanceProbe;
use App\Domain\Inventory\Support\StockBalanceProbe;
use App\Support\Lookup\LookupRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Gudang & stok Paket A (T-068 lokasi stok, rak, kendaraan minimal; T-077+ buku stok). Kendaraan
 * (domain Fleet) ikut didaftarkan di sini karena di Paket A hanya ada sebagai pemilik lokasi stok
 * canvas; Paket D boleh memindahkannya ke provider armada sendiri.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Saldo nyata dari buku stok (T-077): lokasi/rak bersaldo tidak bisa dinonaktifkan.
        $this->app->bind(StockBalanceProbe::class, LedgerStockBalanceProbe::class);

        $this->app->extend(LookupRegistry::class, function (LookupRegistry $registry) {
            $registry->register('stock_locations', StockLocationLookup::class);
            $registry->register('stock_bins', StockBinLookup::class);
            // Batch layak FEFO untuk BatchPicker (T-078).
            $registry->register('available_batches', AvailableBatchLookup::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        Gate::policy(StockLocation::class, StockLocationPolicy::class);
        Gate::policy(StockBin::class, StockBinPolicy::class);
        Gate::policy(Vehicle::class, VehiclePolicy::class);
    }
}
