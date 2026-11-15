<?php

namespace App\Providers;

use App\Domain\Employee\Lookup\EmployeeLookup;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Policies\AssignmentPolicy;
use App\Domain\Employee\Policies\EmployeePolicy;
use App\Domain\Employee\Support\NikCipher;
use App\Support\Lookup\LookupRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Pegawai (T-024). Provider terpisah agar modul ini tidak menyentuh registrasi modul lain di
 * AppServiceProvider (sesi agen lain bekerja di berkas itu).
 */
class EmployeeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NikCipher::class);

        $this->app->extend(LookupRegistry::class, function (LookupRegistry $registry) {
            $registry->register('employees', EmployeeLookup::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(Assignment::class, AssignmentPolicy::class);
    }
}
