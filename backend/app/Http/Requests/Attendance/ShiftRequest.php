<?php

namespace App\Http\Requests\Attendance;

use App\Domain\Attendance\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Data shift (FR-031, 04 §2.13). Izin `shifts.manage` dicek middleware route. Aturan jam BR-060:
 * shift biasa berakhir setelah mulai; shift lintas hari berakhir di hari berikutnya sebelum jam
 * mulai (durasi < 24 jam). CHECK `shifts_midnight_chk` di DB menjaga aturan pertama.
 */
class ShiftRequest extends FormRequest
{
    /** Batas wajar isian menit (1 hari), bukan parameter bisnis. */
    private const MAX_MINUTES = 1440;

    public function authorize(): bool
    {
        return true; // middleware `can:shifts.manage`
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');
        $color = $this->input('color');

        $this->merge([
            // Kode tampil di kalender jadwal (07 §3.4) → seragam huruf besar.
            'code' => is_string($code) ? mb_strtoupper(trim($code)) : $code,
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'color' => is_string($color) ? mb_strtoupper(trim($color)) : $color,
            'crosses_midnight' => $this->boolean('crosses_midnight'),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $shift = $this->route('shift');

        return [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9._-]{1,20}$/', Rule::unique('shifts', 'code')->ignore($shift instanceof Shift ? $shift->id : null)->withoutTrashed()],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'crosses_midnight' => ['boolean'],
            'late_tolerance_min' => ['required', 'integer', 'min:0', 'max:'.self::MAX_MINUTES],
            'checkin_open_min' => ['required', 'integer', 'min:0', 'max:'.self::MAX_MINUTES],
            'checkout_close_min' => ['required', 'integer', 'min:0', 'max:'.self::MAX_MINUTES],
            'color' => ['required', 'string', 'regex:/^#[0-9A-F]{6}$/'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode 1–20 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.',
            'code.unique' => 'Kode ini sudah dipakai shift lain.',
            'start_time.date_format' => 'Jam masuk harus berformat JJ:MM.',
            'end_time.date_format' => 'Jam pulang harus berformat JJ:MM.',
            'color.regex' => 'Warna harus kode heksadesimal, mis. #2F6B3B.',
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['start_time', 'end_time'])) {
                    return;
                }

                $start = (string) $this->input('start_time');
                $end = (string) $this->input('end_time');
                $crosses = $this->boolean('crosses_midnight');

                if (! $crosses && $end <= $start) {
                    $validator->errors()->add('end_time', 'Jam pulang harus setelah jam masuk. Untuk shift malam, centang "Lintas hari".');
                } elseif ($crosses && $end > $start) {
                    $validator->errors()->add('end_time', 'Shift lintas hari berakhir di hari berikutnya, jadi jam pulang harus lebih awal dari jam masuk (mis. 22.00 → 06.00).');
                }
            },
        ];
    }

    /**
     * Data siap simpan; jam dinormalkan `HH:MM:SS` agar sama dengan nilai TIME dari DB (diff audit
     * tidak menganggap `07:30` ≠ `07:30:00` sebagai perubahan).
     *
     * @return array<string, mixed>
     */
    public function shiftData(): array
    {
        return [
            'code' => (string) $this->validated('code'),
            'name' => (string) $this->validated('name'),
            'start_time' => $this->validated('start_time').':00',
            'end_time' => $this->validated('end_time').':00',
            'crosses_midnight' => (bool) $this->validated('crosses_midnight'),
            'late_tolerance_min' => (int) $this->validated('late_tolerance_min'),
            'checkin_open_min' => (int) $this->validated('checkin_open_min'),
            'checkout_close_min' => (int) $this->validated('checkout_close_min'),
            'color' => (string) $this->validated('color'),
            'is_active' => (bool) $this->validated('is_active'),
        ];
    }
}
