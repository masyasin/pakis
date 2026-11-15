<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\Shift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Apakah shift sudah dipakai data lain (05 §4.9: hapus ditolak bila dipakai, nonaktifkan saja).
 * Tabel pemakai dibuat di T-035 (pola & pengecualian jadwal) dan T-036 (absensi); sebelum ada,
 * pemakai itu dilewati. Setelah ada, FK di DB juga menolak penghapusan — cek ini memberi pesan yang
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
     * Jumlah pegawai yang pola jadwalnya memakai tiap shift (kolom daftar 07 §3.4), atau null bila
     * tabel pola belum ada (T-035).
     * TODO(T-035): hanya pola yang berlaku hari ini (effective_from terakhir per pegawai).
     *
     * @param  list<int>  $shiftIds
     * @return array<int, int>|null shift id → jumlah pegawai
     */
    public function employeeCounts(array $shiftIds): ?array
    {
        if ($shiftIds === [] || ! Schema::hasTable(self::PATTERN_TABLE)) {
            return $shiftIds === [] ? [] : null;
        }

        $counts = [];
        foreach ($shiftIds as $id) {
            $counts[$id] = (int) DB::table(self::PATTERN_TABLE)
                ->where(function ($q) use ($id) {
                    foreach (self::PATTERN_COLUMNS as $column) {
                        $q->orWhere($column, $id);
                    }
                })
                ->distinct()
                ->count('employee_id');
        }

        return $counts;
    }
}
