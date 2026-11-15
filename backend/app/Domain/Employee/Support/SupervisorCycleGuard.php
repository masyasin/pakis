<?php

namespace App\Domain\Employee\Support;

use App\Support\Time\DisplayTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Pencegah siklus atasan pegawai (BR-051): A atasan B dan B atasan A (langsung atau lewat beberapa
 * tingkat) ditolak, begitu pula rantai lebih dari MAX_DEPTH tingkat. Rantai ditelusuri dari calon
 * atasan ke atas lewat penugasan yang masih berlaku atau terjadwal (`end_date` kosong / ≥ hari ini),
 * karena mutasi terjadwal pun nantinya membentuk struktur yang sama.
 *
 * Pemanggil WAJIB berada di dalam transaksi dan memanggil `lock()` lebih dulu: tanpa itu dua
 * perubahan bersamaan (A→B dan B→A) masing-masing lolos cek.
 */
final class SupervisorCycleGuard
{
    public const MAX_DEPTH = 10;

    /** Kunci baris penugasan yang masih berlaku (tabel kecil: ≤ ±150 pegawai). */
    public static function lock(): void
    {
        self::current()->lockForUpdate()->pluck('id');
    }

    /** Pesan galat bila `$supervisorId` tidak boleh menjadi atasan `$employeeId`, atau null bila boleh. */
    public static function violation(int $employeeId, int $supervisorId): ?string
    {
        if ($supervisorId === $employeeId) {
            return 'Pegawai tidak bisa menjadi atasan dirinya sendiri.';
        }

        $level = [$supervisorId];
        $seen = [$supervisorId => true];

        // Kedalaman 1 = calon atasan itu sendiri; tiap putaran naik satu tingkat.
        for ($depth = 1; $level !== []; $depth++) {
            if ($depth > self::MAX_DEPTH) {
                return 'Rantai atasan menjadi lebih dari '.self::MAX_DEPTH.' tingkat. Pilih atasan yang lebih dekat.';
            }

            $next = [];
            foreach (self::supervisorsOf($level) as $id) {
                if ($id === $employeeId) {
                    return 'Atasan ini (langsung atau lewat atasannya) adalah bawahan pegawai yang sedang diubah, sehingga membentuk lingkaran. Pilih atasan lain.';
                }
                if (! isset($seen[$id])) {
                    $seen[$id] = true;
                    $next[] = $id;
                }
            }
            $level = $next;
        }

        return null;
    }

    /**
     * @param  list<int>  $employeeIds
     * @return list<int>
     */
    private static function supervisorsOf(array $employeeIds): array
    {
        return array_values(self::current()
            ->whereIn('employee_id', $employeeIds)
            ->whereNotNull('supervisor_employee_id')
            ->distinct()
            ->pluck('supervisor_employee_id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    private static function current(): Builder
    {
        $today = DisplayTime::toLocal(now())->toDateString();

        return DB::table('assignments')->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today));
    }
}
