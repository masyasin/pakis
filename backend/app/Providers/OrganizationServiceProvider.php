<?php

namespace App\Providers;

use App\Domain\Organization\Lookup\DepartmentLookup;
use App\Domain\Organization\Lookup\LocationLookup;
use App\Domain\Organization\Lookup\PositionLookup;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use App\Domain\Organization\Policies\DepartmentPolicy;
use App\Domain\Organization\Policies\LocationPolicy;
use App\Domain\Organization\Policies\PositionPolicy;
use App\Domain\Organization\Support\LocationScope;
use App\Support\Lookup\LookupRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Organisasi (T-021 lokasi, T-022 departemen, T-023 jabatan). Provider terpisah agar
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
            $registry->register('departments', DepartmentLookup::class);
            $registry->register('positions', PositionLookup::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        Gate::policy(Location::class, LocationPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Position::class, PositionPolicy::class);
    }
}
