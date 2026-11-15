<?php

namespace App\Http\Requests\Attendance;

use App\Domain\Attendance\Models\ShiftPattern;
use App\Http\Requests\Attendance\Concerns\ValidatesScheduleTargets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Tetapkan pola mingguan massal (05 §4.9 `admin.schedules.patterns.store`: `employee_ids[]`,
 * `effective_from`, `days{mon..sun}`). Izin `schedules.manage` dicek middleware route; scope
 * pegawai & shift dicek di sini (ValidatesScheduleTargets). Hari tanpa nilai = libur.
 */
class ShiftPatternRequest extends FormRequest
{
    use ValidatesScheduleTargets;

    public function authorize(): bool
    {
        return true; // middleware `can:schedules.manage`
    }

    protected function prepareForValidation(): void
    {
        // "" dari pemilih "Libur" = null; kunci hari yang tidak dikirim juga libur.
        $days = $this->input('days');
        $days = is_array($days) ? $days : [];
        $normalized = [];
        foreach (ShiftPattern::DAYS as $day) {
            $value = $days[$day] ?? null;
            $normalized[$day] = $value === '' ? null : $value;
        }

        $this->merge(['days' => $normalized]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            ...$this->employeeRules(),
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'days' => ['required', 'array'],
        ];
        foreach (ShiftPattern::DAYS as $day) {
            $rules["days.{$day}"] = ['nullable', 'integer'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->employeeMessages(),
            'effective_from.required' => 'Isi tanggal mulai berlaku.',
            'effective_from.date_format' => 'Tanggal tidak valid.',
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
                $this->checkShifts($validator, array_values(array_filter($this->days(), fn (?int $id) => $id !== null)), 'days');
            },
        ];
    }

    public function effectiveFrom(): string
    {
        return (string) $this->validated('effective_from');
    }

    /** @return array<string, int|null> `mon..sun` → shift id (null = libur) */
    public function days(): array
    {
        $days = (array) $this->input('days', []);
        $result = [];
        foreach (ShiftPattern::DAYS as $day) {
            $value = $days[$day] ?? null;
            $result[$day] = $value === null ? null : (int) $value;
        }

        return $result;
    }
}
