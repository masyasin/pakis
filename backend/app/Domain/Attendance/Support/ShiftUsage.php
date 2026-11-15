<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Support\Time\DisplayTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Apakah shift sudah dipakai data lain (05 §4.9: hapus ditolak bila dipakai, nonaktifkan saja).
 * Tabel pemakai: pola & pengecualian jadwal (T-035) dan absensi (T-036); yang belum ada dilewati. Setelah ada, FK di DB juga menolak penghapusan — cek ini memberi pesan yang
 * ramah. Pola sama dengan LocationUsage (T-021).
 */
class ShiftUsage
{
    public const PATTERN_TABLE = 'employee_shift_patterns';

    /** Kolom pola mingguan (04 §2.13): NULL = libur pada hari itu. */
    public const PATTERN_COLUMNS = ['mon_shift_id', 'tue_shift_id', 'wed_shift_id', 'thu_shift_id', 'fri_shift_id', 'sat_shift_id', 'sun_shift_id'];

    /** @return list<array{0: string, 1: string}> [tabel, kolom] dari 04 */
    public static function usages(): array
    {
        return [
            ...array_map(fn (string $column) => [self::PATTERN_TABLE, $column], self::PATTERN_COLUMNS),
            ['schedule_overrides', 'shift_id'],
            ['attendance_days', 'shift_id'],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>|null  $usages  pemakai eksplisit (untuk test); null = usages()
     */
    public function __construct(private readonly ?array $usages = null) {}

    public function isUsed(Shift $shift): bool
    {
        // Pemakai eksplisit (test) tidak dicek keberadaan tabelnya: MariaDB tidak menampilkan
        // TEMPORARY TABLE di information_schema sehingga Schema::hasTable() selalu false untuknya.
        $usages = $this->usages ?? array_filter(self::usages(), fn (array $usage) => Schema::hasTable($usage[0]));

        foreach ($usages as [$table, $column]) {
            if (DB::table($table)->where($column, $shift->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Jumlah pegawai AKTIF yang pola jadwal BERLAKU HARI INI (`effective_from` terakhir ≤ hari ini,
     * BR-061) memakai tiap shift di salah satu hari (kolom daftar 07 §3.4). Pola lama (riwayat) dan
     * pola terjadwal tidak dihitung. Null bila tabel pola belum ada.
     *
     * @param  list<int>  $shiftIds
     * @param  list<int>|null  $employeeIds  batas scope pelaku (null = semua pegawai)
     * @return array<int, int>|null shift id → jumlah pegawai
     */
    public function employeeCounts(array $shiftIds, ?array $employeeIds = null): ?array
    {
        if ($shiftIds === [] || ! Schema::hasTable(self::PATTERN_TABLE)) {
            return $shiftIds === [] ? [] : null;
        }

        $counts = array_fill_keys($shiftIds, 0);
        if ($employeeIds === []) {
            return $counts;
        }

        $today = DisplayTime::toLocal(now())->toDateString();
        $latest = DB::table(self::PATTERN_TABLE)
            ->select('employee_id', DB::raw('MAX(effective_from) AS effective_from'))
            ->where('effective_from', '<=', $today)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->groupBy('employee_id');

        // Satu query: pola berlaku per pegawai aktif, lalu dihitung per shift di memori
        // (satu pegawai dihitung sekali per shift walau shift itu dipakai di beberapa hari).
        $rows = DB::table(self::PATTERN_TABLE.' AS p')
            ->joinSub($latest, 'cur', fn ($join) => $join->on('cur.employee_id', '=', 'p.employee_id')->on('cur.effective_from', '=', 'p.effective_from'))
            ->join('employees AS e', 'e.id', '=', 'p.employee_id')
            ->where('e.status', EmployeeStatus::Active->value)
            ->whereNull('e.deleted_at')
            ->get(array_map(fn (string $c) => "p.{$c}", self::PATTERN_COLUMNS));

        foreach ($rows as $row) {
            $used = [];
            foreach (self::PATTERN_COLUMNS as $column) {
                $id = $row->{$column};
                if ($id !== null && array_key_exists((int) $id, $counts)) {
                    $used[(int) $id] = true;
                }
            }
            foreach (array_keys($used) as $id) {
                $counts[$id]++;
            }
        }

        return $counts;
    }
}
