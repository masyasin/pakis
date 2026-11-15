<?php

namespace App\Providers;

use App\Domain\Attendance\Lookup\ShiftLookup;
use App\Domain\Attendance\Support\ShiftScope;
use App\Support\Lookup\LookupRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Absensi (T-033 shift; jadwal, hari libur, absen menyusul). Provider terpisah agar modul ini tidak
 * menyentuh registrasi modul lain di AppServiceProvider/OrganizationServiceProvider.
 */
class AttendanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Di-memo per request seperti DataScope.
        $this->app->scoped(ShiftScope::class);

        $this->app->extend(LookupRegistry::class, function (LookupRegistry $registry) {
            $registry->register('shifts', ShiftLookup::class);

            return $registry;
        });
    }
}
