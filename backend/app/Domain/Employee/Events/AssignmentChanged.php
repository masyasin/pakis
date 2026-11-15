<?php

namespace App\Domain\Employee\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Penugasan pegawai berubah (dibuat, dimutasi, diubah, diakhiri). Dipakai resolver absensi (T-037)
 * untuk menghitung ulang snapshot `attendance_days` mulai `fromDate` (BR-052). Dikirim SETELAH
 * transaksi commit, jadi listener tidak pernah membaca perubahan yang kemudian di-rollback.
 */
final class AssignmentChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  'created'|'mutated'|'updated'|'ended'  $reason
     */
    public function __construct(
        public readonly int $employeeId,
        public readonly string $fromDate,
        public readonly string $reason,
    ) {}
}
