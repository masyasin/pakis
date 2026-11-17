<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

/** Proses impor (BR-054): opsi "Perbarui data yang sudah ada" + konfirmasi password bila tipe memintanya (T-085). */
class ImportProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:manage,batch` (ImportBatchPolicy: izin tipe batch) + binding batch yang terlihat
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'update_existing' => ['sometimes', 'boolean'],
            // Hanya bila tipe impor memintanya (kios aktif-terverifikasi, T-085); diperiksa controller.
            'current_password' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function updateExisting(): bool
    {
        return $this->boolean('update_existing');
    }
}
