<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\Shift;
use Carbon\CarbonImmutable;

/**
 * Hari kerja tujuan sebuah absen (BR-063): `work_date` lokal, shift (null = tanpa jadwal, hanya
 * bila `attendance.allow_unscheduled`), dan jadwal mulai/selesai dalam UTC.
 */
final readonly class WorkDateCandidate
{
    public function __construct(
        public string $workDate,
        public ?Shift $shift,
        public ?CarbonImmutable $scheduledStart,
        public ?CarbonImmutable $scheduledEnd,
    ) {}

    /** Batas akhir absen pulang (BR-060): selesai + `checkout_close_min`. */
    public function checkoutClose(): ?CarbonImmutable
    {
        return $this->shift === null ? null : $this->scheduledEnd?->addMinutes($this->shift->checkout_close_min);
    }

    /** Awal jendela masuk (BR-060): mulai − `checkin_open_min`. */
    public function checkinOpen(): ?CarbonImmutable
    {
        return $this->shift === null ? null : $this->scheduledStart?->subMinutes($this->shift->checkin_open_min);
    }
}
