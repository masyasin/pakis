<?php

namespace App\Http\Requests\Attendance;

use App\Domain\Access\DataScope;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Closure;

/**
 * Isi presensi manual dari web (keputusan Mas 16 Nov 2026, BR-069, T-041). Bentuk data sama dengan
 * pengajuan koreksi (StoreCorrectionRequest) tanpa lampiran, ditambah pegawai yang dipilih. Pegawai
 * di luar scope / nonaktif = galat umum "tidak ditemukan" (SEC-033); wewenang memutuskan 06 §6
 * dicek Action. Izin `attendance_corrections.approve` dicek middleware route.
 */
class ManualAttendanceRequest extends StoreCorrectionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['attachment']);

        return [
            'employee_id' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                $user = $this->user();
                $visible = $user instanceof User && is_numeric($value) && app(DataScope::class)->apply(
                    Employee::query()->whereKey((int) $value)->where('status', EmployeeStatus::Active),
                    $user,
                    'employees.id',
                )->exists();
                if (! $visible) {
                    $fail('Pegawai tidak ditemukan.');
                }
            }],
            ...$rules,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'employee_id.required' => 'Pilih pegawai.',
            'work_date.required' => 'Isi tanggal.',
            'reason_code.required' => 'Pilih alasan.',
            'note.required' => 'Isi catatan.',
            'note.min' => 'Catatan minimal 10 karakter.',
        ];
    }

    public function employeeId(): int
    {
        return (int) $this->validated('employee_id');
    }
}
