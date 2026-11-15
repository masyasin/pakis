<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Unggah berkas impor (BR-054, SEC-050). Di sini hanya ekstensi & ukuran; isi berkas diperiksa
 * StoreImportUpload (ZIP workbook / teks) karena ekstensi bisa dipalsukan.
 */
class ImportUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:employees.import`
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx,csv', 'max:'.((int) setting('import.max_file_mb') * 1024)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Pilih berkas Excel (.xlsx) atau CSV.',
            'file.extensions' => 'Berkas harus Excel (.xlsx) atau CSV.',
            'file.max' => 'Ukuran berkas maksimal '.setting('import.max_file_mb').' MB.',
            'file.uploaded' => 'Berkas gagal diunggah. Periksa ukurannya (maks. '.setting('import.max_file_mb').' MB).',
        ];
    }
}
