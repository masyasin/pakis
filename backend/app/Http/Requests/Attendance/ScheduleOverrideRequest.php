<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\Attendance\Concerns\ValidatesScheduleTargets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Pengecualian jadwal massal per tanggal (05 §4.9 `admin.schedule-overrides.store`): satu tanggal,
 * banyak pegawai, shift pengganti atau libur (`shift_id` kosong). Izin `schedules.manage` dicek
 * middleware route; scope pegawai & shift dicek di sini (ValidatesScheduleTargets).
 */
class ScheduleOverrideRequest extends FormRequest
{
    use ValidatesScheduleTargets;

    public function authorize(): bool
    {
        return true; // middleware `can:schedules.manage`
    }

    protected function prepareForValidation(): void
    {
        $shift = $this->input('shift_id');
        $note = $this->input('note');

        $this->merge([
            'shift_id' => $shift === '' ? null : $shift,
            'note' => is_string($note) && trim($note) !== '' ? trim($note) : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->employeeRules(),
            'date' => ['required', 'date_format:Y-m-d'],
            'shift_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->employeeMessages(),
            'date.required' => 'Isi tanggal pengecualian.',
            'date.date_format' => 'Tanggal tidak valid.',
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->checkEmployees($validator);
                $shift = $this->shiftId();
                $this->checkShifts($validator, $shift === null ? [] : [$shift], 'shift_id');
            },
        ];
    }

    /** Bukan `date()`: nama itu sudah dipakai Request::date($key, ...). */
    public function overrideDate(): string
    {
        return (string) $this->validated('date');
    }

    public function shiftId(): ?int
    {
        $value = $this->input('shift_id');

        return $value === null ? null : (int) $value;
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) ? $note : null;
    }
}
