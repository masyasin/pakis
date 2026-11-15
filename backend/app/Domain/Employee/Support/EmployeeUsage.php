<?php

namespace App\Domain\Employee\Support;

use App\Domain\Employee\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Syarat hapus pegawai (BR-055). Hapus hanya untuk salah input: pegawai yang sudah absen, akunnya
 * pernah dipakai login, atau dirujuk sebagai atasan / kepala lokasi harus dinonaktifkan (T-026)
 * agar riwayatnya tetap utuh.
 */
class EmployeeUsage
{
    /**
     * @return list<string> alasan penolakan (kosong = boleh dihapus)
     */
    public function blockers(Employee $employee): array
    {
        $reasons = [];

        // Tabel absensi baru ada di T-036; sebelum itu pemakai ini dilewati.
        if (Schema::hasTable('attendance_logs') && DB::table('attendance_logs')->where('employee_id', $employee->id)->exists()) {
            $reasons[] = 'sudah punya data presensi';
        }

        if (DB::table('users')->where('employee_id', $employee->id)->whereNotNull('last_login_at')->exists()) {
            $reasons[] = 'akunnya sudah pernah dipakai login';
        }

        if (DB::table('assignments')->where('supervisor_employee_id', $employee->id)->exists()) {
            $reasons[] = 'tercatat sebagai atasan pegawai lain';
        }

        if (DB::table('locations')->where('head_employee_id', $employee->id)->whereNull('deleted_at')->exists()) {
            $reasons[] = 'menjadi kepala lokasi';
        }

        return $reasons;
    }

    public function canDelete(Employee $employee): bool
    {
        return $this->blockers($employee) === [];
    }
}
