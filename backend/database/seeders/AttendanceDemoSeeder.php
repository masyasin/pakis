<?php

namespace Database\Seeders;

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Models\Employee;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Presensi DEMO satu bulan terakhir untuk pegawai demo (DemoSeeder), agar dasbor, Presensi Harian,
 * dan rekap punya isi saat dicoba. Hanya `local`/`staging`, DITOLAK di produksi (CLAUDE.md §6).
 *
 *     php artisan db:seed --class=AttendanceDemoSeeder
 *
 * - Idempoten: hari yang sudah punya baris (presensi sungguhan dari Pakis Pro) tidak disentuh, jadi
 *   aman dijalankan ulang.
 * - Deterministik: pola tiap pegawai dari id-nya (ada yang selalu tepat waktu, ada yang sering telat),
 *   sehingga grafik berbentuk wajar, bukan acak murni.
 * - Jadwal anggapan 08:00–16:00 jam lokal, Minggu libur. Hari ini hanya pegawai yang jam masuknya
 *   sudah lewat; belum ada presensi pulang.
 * - Baris dibuat tanpa log presensi (`check_in_log_id` kosong), seperti presensi manual lama.
 */
class AttendanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('AttendanceDemoSeeder ditolak di produksi: presensi dummy hanya untuk mencoba tampilan.');
        }

        $zone = DisplayTime::timezone();
        $now = CarbonImmutable::now($zone);
        $today = $now->startOfDay();
        $from = $today->subMonthNoOverflow();

        $employees = Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->with('primaryAssignment.position')
            ->get()
            ->filter(fn (Employee $e) => $e->primaryAssignment !== null);
        if ($employees->isEmpty()) {
            throw new RuntimeException('Belum ada pegawai aktif berpenugasan (jalankan DemoSeeder dulu).');
        }

        $taken = DB::table('attendance_days')
            ->whereBetween('work_date', [$from->toDateString(), $today->toDateString()])
            ->get(['employee_id', 'work_date'])
            ->map(fn (object $r) => $r->employee_id.'|'.substr((string) $r->work_date, 0, 10))
            ->flip();

        $rows = [];
        $stamp = now();
        foreach ($employees as $employee) {
            $assignment = $employee->primaryAssignment;
            // 0 = disiplin, 1 = sesekali telat, 2 = sering telat.
            $habit = $employee->id % 3;

            for ($day = $from; $day->lte($today); $day = $day->addDay()) {
                if ($day->isSunday() || $taken->has($employee->id.'|'.$day->toDateString())) {
                    continue;
                }

                $roll = ($employee->id * 37 + $day->dayOfYear * 11) % 100;
                $isToday = $day->equalTo($today);
                $absent = ! $isToday && $roll < 4;
                $lateChance = [6, 18, 35][$habit];
                $late = ! $absent && $roll >= 4 && $roll < 4 + $lateChance ? 3 + ($roll * 7) % 42 : 0;
                // Tepat waktu menyebar 07:20–07:59, telat sesudah 08:00.
                $start = $day->setTime(8, 0);
                $in = $late > 0 ? $start->addMinutes($late) : $start->subMinutes(1 + ($roll * 13) % 40);

                if ($isToday && ($absent || $in->gt($now))) {
                    continue;  // hari ini: belum datang = belum ada baris
                }

                $end = $day->setTime(16, 0);
                $out = $isToday ? null : $end->addMinutes(($roll * 5) % 50);
                $rows[] = [
                    'employee_id' => $employee->id,
                    'work_date' => $day->toDateString(),
                    'location_id' => $assignment->location_id,
                    'position_id' => $assignment->position_id,
                    'department_id' => $assignment->position?->department_id,
                    'scheduled_start' => $start->utc(),
                    'scheduled_end' => $end->utc(),
                    'check_in_at' => $absent ? null : $in->utc(),
                    'check_out_at' => $absent || $out === null ? null : $out->utc(),
                    'late_minutes' => $late,
                    'work_minutes' => $absent || $out === null ? 0 : (int) $in->diffInMinutes($out),
                    'status' => ($absent ? AttendanceStatus::Absent : ($late > 0 ? AttendanceStatus::Late : AttendanceStatus::Present))->value,
                    'resolved_at' => $isToday ? null : $end->addHours(6)->utc(),
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('attendance_days')->insert($chunk);
        }

        $this->command?->info(sprintf(
            'Presensi demo %s s.d. %s: %d baris baru untuk %d pegawai.',
            $from->toDateString(),
            $today->toDateString(),
            count($rows),
            $employees->count(),
        ));
    }
}
