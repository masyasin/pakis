<?php

namespace App\Domain\Attendance\Listeners;

use App\Domain\Attendance\Actions\CancelCorrection;
use App\Domain\Employee\Events\EmployeeOffboarded;

/**
 * BR-053: pegawai dinonaktifkan → koreksi `pending` miliknya dibatalkan. Event dikirim setelah
 * transaksi offboarding commit (ShouldDispatchAfterCommit), jadi pembatalan berjalan tepat sesudahnya
 * secara sinkron di request yang sama (keputusan teknis T-040, dicatat di 12).
 */
final class CancelPendingCorrections
{
    public function __construct(private readonly CancelCorrection $cancel) {}

    public function handle(EmployeeOffboarded $event): void
    {
        $this->cancel->allPendingOf($event->employeeId);
    }
}
