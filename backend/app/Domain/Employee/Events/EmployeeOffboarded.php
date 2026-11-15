<?php

namespace App\Domain\Employee\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pegawai dinonaktifkan (BR-053). Dikirim SETELAH commit. Listener menyusul di modul lain:
 * T-040 membatalkan koreksi absen `pending` milik pegawai ini.
 */
final class EmployeeOffboarded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $employeeId,
        public readonly string $exitDate,
    ) {}
}
