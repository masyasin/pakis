<?php

namespace App\Domain\Attendance\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Jadwal efektif berubah untuk rentang tanggal: pola shift, pengecualian, atau hari libur
 * (BR-061/BR-062). Status harian yang SUDAH ada di rentang itu dihitung ulang (T-037, keputusan Mas
 * 15 Nov 2026, OQ-021). Dikirim setelah commit agar listener tidak membaca perubahan yang batal.
 */
final class ScheduleChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>|null  $employeeIds  null = semua pegawai (hari libur)
     * @param  string|null  $to  null = terbuka (pola berlaku sampai diganti)
     */
    public function __construct(
        public readonly ?array $employeeIds,
        public readonly string $from,
        public readonly ?string $to = null,
    ) {}
}
