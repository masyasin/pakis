<?php

namespace App\Providers;

use App\Domain\Customer\Lookup\CustomerLookup;
use App\Domain\Customer\Lookup\SalesAreaLookup;
use App\Domain\Customer\Lookup\SalesEmployeeLookup;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\SalesArea;
use App\Domain\Customer\Policies\CustomerPolicy;
use App\Domain\Customer\Policies\SalesAreaPolicy;
use App\Support\Lookup\LookupRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Area penjualan & kios Paket A (T-073 data inti & penugasan; T-074 verifikasi, kontak, limit).
 */
class CustomerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(LookupRegistry::class, function (LookupRegistry $registry) {
            $registry->register('sales_areas', SalesAreaLookup::class);
            $registry->register('customers', CustomerLookup::class);
            $registry->register('sales_employees', SalesEmployeeLookup::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        Gate::policy(SalesArea::class, SalesAreaPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
    }
}
