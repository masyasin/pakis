<?php

namespace App\Providers;

use App\Domain\Attendance\AttendanceStatusResolver;
use App\Domain\Attendance\Events\ScheduleChanged;
use App\Domain\Attendance\Listeners\RecomputeAttendanceDays;
use App\Domain\Attendance\Lookup\ShiftLookup;
use App\Domain\Attendance\Support\DayStatusResolver;
use App\Domain\Attendance\Support\ShiftScope;
use App\Domain\Employee\Events\AssignmentChanged;
use App\Support\Lookup\LookupRegistry;
use Illuminate\Support\Facades\Event;
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

        // Status harian lengkap (BR-066/BR-068, T-037). Bind (bukan singleton): resolver menyimpan
        // jadwal batch sementara, jadi tiap pemakai mendapat instance sendiri.
        $this->app->bind(DayStatusResolver::class, AttendanceStatusResolver::class);

        $this->app->extend(LookupRegistry::class, function (LookupRegistry $registry) {
            $registry->register('shifts', ShiftLookup::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        // Hitung ulang status harian yang sudah ada (BR-052 mutasi, OQ-021 jadwal/libur lampau).
        Event::listen(AssignmentChanged::class, [RecomputeAttendanceDays::class, 'assignmentChanged']);
        Event::listen(ScheduleChanged::class, [RecomputeAttendanceDays::class, 'scheduleChanged']);
    }
}
