<?php

namespace App\Http\Requests\Employee;

use App\Domain\Access\DataScope;
use App\Domain\Organization\Enums\AttendanceMode;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Support\LocationScope;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Penugasan baru dari detail pegawai (05 `admin.assignments.store`): `is_primary` = Mutasi
 * (BR-052), selain itu penugasan tambahan / rangkap (BR-051). Aturan bisnis yang butuh data
 * terkunci (siklus atasan, tanggal mutasi vs penugasan lama) dicek di Action.
 */
class AssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:assignments.manage` + EmployeePolicy::manageAssignments
    }

    protected function prepareForValidation(): void
    {
        $text = fn (mixed $v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        $this->merge([
            'is_primary' => $this->boolean('is_primary'),
            'supervisor_employee_id' => $text((string) $this->input('supervisor_employee_id', '')),
            'attendance_mode' => $text((string) $this->input('attendance_mode', '')),
            'note' => $text($this->input('note')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_primary' => ['boolean'],
            'position_id' => ['required', 'integer', Rule::exists('positions', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'supervisor_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'attendance_mode' => ['nullable', Rule::enum(AttendanceMode::class)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Lokasi & atasan di luar scope pelaku diperlakukan "tidak ditemukan" (SEC-033).
        $validator->after(function (Validator $v) {
            $user = $this->user();
            if (! $user instanceof User) {
                return;
            }

            $location = is_numeric($this->input('location_id')) ? Location::query()->find((int) $this->input('location_id')) : null;
            if ($location !== null && ! app(LocationScope::class)->allows($user, $location)) {
                $v->errors()->add('location_id', 'Lokasi tidak ditemukan.');
            }

            $supervisor = $this->input('supervisor_employee_id');
            if (is_numeric($supervisor) && ! app(DataScope::class)->allows($user, (int) $supervisor)) {
                $v->errors()->add('supervisor_employee_id', 'Atasan tidak ditemukan.');
            }
        });
    }

    /**
     * @return array{position_id: int, location_id: int, supervisor_employee_id: int|null, attendance_mode: string|null, start_date: string, note: string|null, is_primary: bool}
     */
    public function assignmentData(): array
    {
        return [
            'position_id' => (int) $this->validated('position_id'),
            'location_id' => (int) $this->validated('location_id'),
            'supervisor_employee_id' => $this->validated('supervisor_employee_id') !== null ? (int) $this->validated('supervisor_employee_id') : null,
            'attendance_mode' => $this->validated('attendance_mode'),
            'start_date' => (string) $this->validated('start_date'),
            'note' => $this->validated('note'),
            'is_primary' => (bool) $this->validated('is_primary'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'position_id.required' => 'Pilih jabatan.',
            'position_id.exists' => 'Jabatan tidak ditemukan atau sudah nonaktif.',
            'location_id.required' => 'Pilih lokasi tugas.',
            'location_id.exists' => 'Lokasi tidak ditemukan atau sudah nonaktif.',
            'supervisor_employee_id.exists' => 'Atasan harus pegawai yang masih aktif.',
            'start_date.required' => 'Isi tanggal mulai.',
            'start_date.date_format' => 'Tanggal mulai tidak valid.',
        ];
    }
}
