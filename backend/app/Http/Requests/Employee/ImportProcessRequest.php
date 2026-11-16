<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

/** Proses impor (BR-054): satu opsi, "Perbarui data yang sudah ada". */
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
        ];
    }

    public function updateExisting(): bool
    {
        return $this->boolean('update_existing');
    }
}
