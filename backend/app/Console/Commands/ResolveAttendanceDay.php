<?php

namespace App\Console\Commands;

use App\Domain\Attendance\AttendanceStatusResolver;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Status harian (BR-068, 03 §4.6, T-037). Tanpa argumen: kemarin & hari ini (tanggal LOKAL), dijadwalkan
 * tiap 15 menit; resolver sendiri yang menentukan kapan hari "ditutup" dari
 * `attendance.daily_close_time`, jadi perubahan setting itu langsung berlaku (keputusan Mas
 * 15 Nov 2026). Dengan tanggal: hitung (ulang) tanggal itu, mis. setelah gangguan server.
 */
class ResolveAttendanceDay extends Command
{
    protected $signature = 'attendance:resolve-day {date? : Tanggal lokal Y-m-d (default: kemarin dan hari ini)}';

    protected $description = 'Hitung status absensi harian (tidak hadir, libur, tidak absen pulang, dst.)';

    public function handle(AttendanceStatusResolver $resolver): int
    {
        $today = DisplayTime::toLocal(now())->toDateString();
        $argument = $this->argument('date');

        if (is_string($argument)) {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $argument);
            } catch (Throwable) {
                $date = null;
            }
            if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $argument) {
                $this->error('Format tanggal harus Y-m-d, mis. 2026-11-15.');

                return self::INVALID;
            }
            if ($argument > $today) {
                $this->error('Tanggal di masa depan tidak bisa dihitung.');

                return self::INVALID;
            }
            $dates = [$argument];
        } else {
            $dates = [DisplayTime::toLocal(now())->subDay()->toDateString(), $today];
        }

        foreach ($dates as $date) {
            $result = $resolver->resolveDate($date);
            $this->info(sprintf('%s: %d dibuat, %d diperbarui, %d dihapus.', $date, $result['created'], $result['updated'], $result['deleted']));
        }

        return self::SUCCESS;
    }
}
