<?php

namespace App\Http\Requests\Employee;

use App\Domain\Employee\Models\Employee;
use App\Http\Requests\Profile\CurrentPasswordRequest;
use Illuminate\Validation\Validator;

/**
 * Nonaktifkan pegawai (07 §4): tanggal keluar, alasan (referensi), catatan, ketik nama pegawai,
 * dan password pelaku sendiri (aksi yang mematikan akses tidak boleh dilakukan dari sesi tertinggal).
 * Aturan bisnis tanggal & status dicek OffboardEmployee di dalam transaksi.
 */
class OffboardRequest extends CurrentPasswordRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:employees.offboard` + EmployeePolicy::offboard
    }

    protected function prepareForValidation(): void
    {
        $note = $this->input('exit_note');

        $this->merge([
            'exit_note' => is_string($note) && trim($note) !== '' ? trim($note) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'exit_date' => ['required', 'date_format:Y-m-d'],
            'exit_reason_id' => ['required', 'integer'],
            'exit_note' => ['nullable', 'string', 'max:255'],
            'confirm_name' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $employee = $this->route('employee');
            $typed = mb_strtolower(trim((string) $this->input('confirm_name')));

            if ($employee instanceof Employee && $typed !== '' && $typed !== mb_strtolower($employee->full_name)) {
                $v->errors()->add('confirm_name', 'Nama yang diketik tidak sama dengan nama pegawai.');
            }
        });
    }

    /**
     * @return array{exit_date: string, exit_reason_id: int, exit_note: string|null}
     */
    public function offboardData(): array
    {
        return [
            'exit_date' => (string) $this->validated('exit_date'),
            'exit_reason_id' => (int) $this->validated('exit_reason_id'),
            'exit_note' => $this->validated('exit_note'),
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'exit_date.required' => 'Isi tanggal keluar.',
            'exit_date.date_format' => 'Tanggal keluar tidak valid.',
            'exit_reason_id.required' => 'Pilih alasan keluar.',
            'confirm_name.required' => 'Ketik nama pegawai untuk konfirmasi.',
        ];
    }
}
