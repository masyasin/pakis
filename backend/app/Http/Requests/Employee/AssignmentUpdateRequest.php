<?php

namespace App\Http\Requests\Employee;

use App\Domain\Access\DataScope;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Organization\Enums\AttendanceMode;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Ubah penugasan berjalan (05 `admin.assignments.update`): atasan, mode absen, catatan saja.
 * Field lain yang dikirim tidak ikut `validated()` (SEC-034).
 */
class AssignmentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:assignments.manage` + AssignmentPolicy
    }

    protected function prepareForValidation(): void
    {
        $text = fn (mixed $v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        $this->merge([
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
        $assignment = $this->route('assignment');
        $current = $assignment instanceof Assignment ? $assignment->supervisor_employee_id : null;

        return [
            // Atasan yang sudah terpasang boleh dipertahankan walau kini nonaktif.
            'supervisor_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('status', 'active')->when($current !== null, fn ($o) => $o->orWhere('id', $current)))],
            'attendance_mode' => ['nullable', Rule::enum(AttendanceMode::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $user = $this->user();
            $supervisor = $this->input('supervisor_employee_id');
            $assignment = $this->route('assignment');
            $unchanged = $assignment instanceof Assignment && (int) $supervisor === $assignment->supervisor_employee_id;

            if ($user instanceof User && is_numeric($supervisor) && ! $unchanged && ! app(DataScope::class)->allows($user, (int) $supervisor)) {
                $v->errors()->add('supervisor_employee_id', 'Atasan tidak ditemukan.');
            }
        });
    }

    /**
     * @return array{supervisor_employee_id: int|null, attendance_mode: string|null, note: string|null}
     */
    public function assignmentData(): array
    {
        return [
            'supervisor_employee_id' => $this->validated('supervisor_employee_id') !== null ? (int) $this->validated('supervisor_employee_id') : null,
            'attendance_mode' => $this->validated('attendance_mode'),
            'note' => $this->validated('note'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['supervisor_employee_id.exists' => 'Atasan harus pegawai yang masih aktif.'];
    }
}
