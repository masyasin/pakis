<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tambah/ubah izin edar (FR-A10, BR-152). Nomor izin dari Kementan memuat huruf, angka, spasi,
 * titik, garis miring, dan strip. Nomor ganda per produk & isi berkas diperiksa Action/SecureUpload.
 */
class ProductRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:product_registrations.manage`
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'registration_no' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\/\-]{2,59}$/'],
            'holder_name' => ['nullable', 'string', 'max:150'],
            'issued_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:valid_until'],
            'valid_until' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
            // Isi & batas ukuran diperiksa SecureUpload (SEC-109), bukan ekstensi.
            'document' => ['nullable', 'file'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'registration_no' => 'nomor izin',
            'holder_name' => 'pemegang izin',
            'issued_at' => 'tanggal terbit',
            'valid_until' => 'berlaku sampai',
            'document' => 'berkas izin',
        ];
    }
}
