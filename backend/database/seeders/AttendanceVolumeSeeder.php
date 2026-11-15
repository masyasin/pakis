<?php

namespace Database\Seeders;

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Data volume DUMMY untuk uji performa Absensi/Presensi Harian (T-038: p95 < 500 ms pada ±200.000
 * baris `attendance_days`). Hanya dijalankan manual di DB uji terpisah, mis.
 * `DB_DATABASE=pakis_one_perf php artisan db:seed --class=AttendanceVolumeSeeder`.
 * Ditolak di produksi. Semua nama/nomor fiktif (prefiks `VOL-`).
 */
class AttendanceVolumeSeeder extends Seeder
{
    private const EMPLOYEES = 550;

    private const DAYS = 365;

    private const CHUNK = 2000;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('AttendanceVolumeSeeder ditolak di produksi: data dummy hanya untuk uji performa lokal.');
        }

        $locations = Location::query()->where('is_active', true)->pluck('id')->all();
        $positions = Position::query()->where('is_active', true)->get(['id', 'department_id']);
        if ($locations === [] || $positions->isEmpty()) {
            throw new RuntimeException('Butuh minimal satu lokasi & jabatan aktif (jalankan DemoSeeder dulu).');
        }

        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $join = $today->subDays(self::DAYS + 10)->toDateString();

        $this->command?->info('Membuat '.self::EMPLOYEES.' pegawai dummy…');
        $staff = [];
        for ($i = 1; $i <= self::EMPLOYEES; $i++) {
            $location = $locations[$i % count($locations)];
            $position = $positions[$i % $positions->count()];
            $employee = Employee::factory()->create([
                'full_name' => sprintf('Pegawai Volume %03d', $i),
                'join_date' => $join,
                'employee_number' => sprintf('VOL-%04d', $i),
            ]);
            Assignment::query()->create([
                'employee_id' => $employee->id, 'position_id' => $position->id, 'location_id' => $location,
                'is_primary' => true, 'start_date' => $join,
            ]);
            $staff[] = [$employee->id, $location, $position->id, $position->department_id];
        }

        $this->command?->info('Membuat ±'.number_format(self::EMPLOYEES * self::DAYS).' baris attendance_days…');
        $buffer = [];
        $now = now();
        foreach ($staff as $n => [$employeeId, $locationId, $positionId, $departmentId]) {
            for ($d = 0; $d < self::DAYS; $d++) {
                $date = $today->subDays($d);
                $roll = ($n * 31 + $d * 7) % 100;
                $status = match (true) {
                    $roll < 70 => AttendanceStatus::Present,
                    $roll < 85 => AttendanceStatus::Late,
                    $roll < 93 => AttendanceStatus::Absent,
                    default => AttendanceStatus::Off,
                };
                $works = in_array($status, [AttendanceStatus::Present, AttendanceStatus::Late], true);
                $late = $status === AttendanceStatus::Late ? 5 + $roll % 40 : 0;
                $in = $date->setTime(8, 0)->addMinutes($late)->utc();

                $buffer[] = [
                    'employee_id' => $employeeId,
                    'work_date' => $date->toDateString(),
                    'location_id' => $locationId,
                    'position_id' => $positionId,
                    'department_id' => $departmentId,
                    'check_in_at' => $works ? $in : null,
                    'check_out_at' => $works ? $in->addHours(8) : null,
                    'late_minutes' => $late,
                    'work_minutes' => $works ? 480 : 0,
                    'status' => $status->value,
                    'is_offline' => $roll % 17 === 0,
                    'has_anomaly' => $roll % 29 === 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($buffer) >= self::CHUNK) {
                    DB::table('attendance_days')->insert($buffer);
                    $buffer = [];
                }
            }
        }
        if ($buffer !== []) {
            DB::table('attendance_days')->insert($buffer);
        }

        $this->command?->info('Selesai: '.number_format(DB::table('attendance_days')->count()).' baris.');
    }
}
