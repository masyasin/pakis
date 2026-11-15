<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\NikCipher;
use App\Models\User;

/**
 * Buka NIK lengkap (SEC-010, `employees.view_sensitive`). Satu-satunya jalan NIK plaintext keluar
 * dari server; SETIAP pembukaan tercatat sebagai event keamanan `sensitive_data_viewed` dan audit
 * `employee.nik_viewed` — keduanya tanpa nilai NIK.
 */
final class RevealNik
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecurityLogger $security,
        private readonly NikCipher $nik,
    ) {}

    /** @return string|null NIK lengkap, null bila pegawai tidak punya NIK (tidak dicatat: tidak ada yang dibuka) */
    public function handle(User $actor, Employee $employee): ?string
    {
        if ($employee->nik_enc === null) {
            return null;
        }

        $nik = $this->nik->decrypt($employee->nik_enc);

        $this->security->record(SecurityEventType::SensitiveDataViewed, SecurityChannel::Web, $actor, context: [
            'field' => 'nik',
            'employee_id' => $employee->id,
        ]);
        $this->audit->record('employee.nik_viewed', $employee);

        return $nik;
    }
}
