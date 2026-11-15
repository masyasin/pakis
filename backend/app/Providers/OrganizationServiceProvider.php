<?php

namespace App\Providers;

use App\Domain\Organization\Lookup\LocationLookup;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Policies\LocationPolicy;
use App\Domain\Organization\Support\LocationScope;
use App\Support\Lookup\LookupRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Organisasi (T-021: lokasi; T-022/T-023: departemen & jabatan menyusul). Provider terpisah agar
 * modul ini tidak menyentuh registrasi modul lain di AppServiceProvider.
 */
class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Di-memo per request seperti DataScope.
        $this->app->scoped(LocationScope::class);

        $this->app->extend(LookupRegistry::class, function (LookupRegistry $registry) {
            $registry->register('locations', LocationLookup::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        Gate::policy(Location::class, LocationPolicy::class);
    }
}
