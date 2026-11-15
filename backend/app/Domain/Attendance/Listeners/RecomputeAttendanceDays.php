<?php

namespace App\Domain\Attendance\Listeners;

use App\Domain\Attendance\AttendanceStatusResolver;
use App\Domain\Attendance\Events\ScheduleChanged;
use App\Domain\Employee\Events\AssignmentChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Hitung ulang status harian yang SUDAH ada setelah penugasan berubah (BR-052: snapshot lokasi/
 * jabatan/departemen) atau jadwal/libur berubah (OQ-021). Lewat antrean: hari libur nasional bisa
 * menyentuh semua pegawai, dan request admin tidak perlu menunggu.
 */
class RecomputeAttendanceDays implements ShouldQueue
{
    public function __construct(private readonly AttendanceStatusResolver $resolver) {}

    public function assignmentChanged(AssignmentChanged $event): void
    {
        $this->resolver->recompute([$event->employeeId], $event->fromDate);
    }

    public function scheduleChanged(ScheduleChanged $event): void
    {
        $this->resolver->recompute($event->employeeIds, $event->from, $event->to);
    }
}
