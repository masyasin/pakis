<?php

namespace App\Providers;

use App\Domain\Attendance\Lookup\ShiftLookup;
use App\Domain\Attendance\Support\BasicDayStatus;
use App\Domain\Attendance\Support\DayStatusResolver;
use App\Domain\Attendance\Support\ShiftScope;
use App\Support\Lookup\LookupRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Absensi (T-033 shift, T-035 jadwal, T-036 absen). Provider terpisah agar modul ini tidak
 * menyentuh registrasi modul lain di AppServiceProvider/OrganizationServiceProvider.
 */
class AttendanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Di-memo per request seperti DataScope.
        $this->app->scoped(ShiftScope::class);

        // Status harian (BR-066/BR-068): T-036 dasar; T-037 mengganti binding ini dengan resolver lengkap.
        $this->app->bind(DayStatusResolver::class, BasicDayStatus::class);

        $this->app->extend(LookupRegistry::class, function (LookupRegistry $registry) {
            $registry->register('shifts', ShiftLookup::class);

            return $registry;
        });
    }
}
