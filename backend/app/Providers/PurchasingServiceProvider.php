<?php

namespace App\Providers;

use App\Domain\Purchasing\Lookup\PrincipalLookup;
use App\Domain\Purchasing\Lookup\SupplierLookup;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\Principal;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Policies\GoodsReceiptPolicy;
use App\Domain\Purchasing\Policies\PrincipalPolicy;
use App\Domain\Purchasing\Policies\PurchaseOrderPolicy;
use App\Domain\Purchasing\Policies\SupplierPolicy;
use App\Support\Lookup\LookupRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Pembelian Paket A (T-072 prinsipal & supplier; PO/penerimaan/retur T-079 dst.). Provider terpisah
 * agar modul ini tidak menyentuh registrasi modul lain.
 */
class PurchasingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(LookupRegistry::class, function (LookupRegistry $registry) {
            $registry->register('principals', PrincipalLookup::class);
            $registry->register('suppliers', SupplierLookup::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        Gate::policy(Principal::class, PrincipalPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(GoodsReceipt::class, GoodsReceiptPolicy::class);
    }
}
