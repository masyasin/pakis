<?php

namespace App\Providers;

use App\Domain\Customer\Lookup\CustomerLookup;
use App\Domain\Customer\Lookup\SalesAreaLookup;
use App\Domain\Customer\Lookup\SalesEmployeeLookup;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\SalesArea;
use App\Domain\Customer\Policies\CustomerPolicy;
use App\Domain\Customer\Policies\SalesAreaPolicy;
use App\Models\User;
use App\Support\Lookup\LookupRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

    /** Cek duplikat per pengguna per menit (batas teknis anti enumerasi, pola DeviceServiceProvider). */
    private const CHECK_PER_MINUTE = 30;

    public function boot(): void
    {
        Gate::policy(SalesArea::class, SalesAreaPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);

        // Cek duplikat menjawab "HP ini milik pegawai/kios lain?" — dibatasi per pengguna agar tidak
        // dipakai menyisir nomor (enumerasi). Pemakaian form normal hanya beberapa kali per menit.
        RateLimiter::for('customer-check', function (Request $request) {
            $user = $request->user();

            return Limit::perMinute(self::CHECK_PER_MINUTE)->by($user instanceof User ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip());
        });
    }
}
