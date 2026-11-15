<?php

namespace App\Http\Requests\Employee;

use App\Domain\Access\DataScope;
use App\Domain\Organization\Enums\AttendanceMode;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Support\LocationScope;
use App\Http\Requests\Profile\CurrentPasswordRequest;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Aktifkan kembali pegawai (06 §7.3): tanggal aktif kembali + penugasan utama baru (wajib) +
 * centang aktifkan akun + password pelaku. Lokasi & atasan di luar scope = "tidak ditemukan".
 */
class RehireRequest extends CurrentPasswordRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:employees.offboard` + EmployeePolicy::rehire
    }

    protected function prepareForValidation(): void
    {
        $text = fn (mixed $v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        $this->merge([
            'activate_account' => $this->boolean('activate_account'),
            'supervisor_employee_id' => $text((string) $this->input('supervisor_employee_id', '')),
            'attendance_mode' => $text((string) $this->input('attendance_mode', '')),
            'note' => $text($this->input('note')),
        ]);
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'rehire_date' => ['required', 'date_format:Y-m-d'],
            'position_id' => ['required', 'integer', Rule::exists('positions', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'supervisor_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'attendance_mode' => ['nullable', Rule::enum(AttendanceMode::class)],
            'note' => ['nullable', 'string', 'max:255'],
            'activate_account' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
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
     * @return array{rehire_date: string, position_id: int, location_id: int, supervisor_employee_id: int|null, attendance_mode: string|null, note: string|null}
     */
    public function rehireData(): array
    {
        return [
            'rehire_date' => (string) $this->validated('rehire_date'),
            'position_id' => (int) $this->validated('position_id'),
            'location_id' => (int) $this->validated('location_id'),
            'supervisor_employee_id' => $this->validated('supervisor_employee_id') !== null ? (int) $this->validated('supervisor_employee_id') : null,
            'attendance_mode' => $this->validated('attendance_mode'),
            'note' => $this->validated('note'),
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'rehire_date.required' => 'Isi tanggal aktif kembali.',
            'position_id.required' => 'Pilih jabatan.',
            'position_id.exists' => 'Jabatan tidak ditemukan atau sudah nonaktif.',
            'location_id.required' => 'Pilih lokasi tugas.',
            'location_id.exists' => 'Lokasi tidak ditemukan atau sudah nonaktif.',
            'supervisor_employee_id.exists' => 'Atasan harus pegawai yang masih aktif.',
        ];
    }
}
