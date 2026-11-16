<?php

namespace App\Http\Requests\Customer;

use App\Domain\Customer\Enums\VerificationMethod;
use App\Domain\Customer\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kirim verifikasi aktivasi kios (15 §5 `admin.customers.verify`, BR-165, T-074). Izin
 * `customers.manage` dicek middleware; kios di luar scope 404 lewat binding.
 *
 * - Foto toko: jenis & ukuran diperiksa dari ISI berkas oleh SecureUpload (SEC-109), di sini hanya
 *   "ada berkas".
 * - Titik: lintang/bujur + akurasi GPS ≤ `customer.verify_max_accuracy_m` (akurasi tidak disimpan).
 * - Limit diminta: rupiah ≥ 0 (string desimal, tanpa float), kosong = 0.
 * - Kode OTP wajib untuk metode `otp`, panjang `auth.otp_length`.
 */
class SubmitVerificationRequest extends FormRequest
{
    public const MONEY = '/^\d{1,16}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer && ($this->user()?->can('submitVerification', $customer) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $limit = $this->input('requested_credit_limit');
        $this->merge([
            'requested_credit_limit' => is_string($limit) && trim($limit) !== '' ? str_replace([' ', ','], ['', '.'], trim($limit))
                : (is_int($limit) ? (string) $limit : '0'),
            'otp_code' => is_string($this->input('otp_code')) ? trim($this->input('otp_code')) : $this->input('otp_code'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $length = (int) setting('auth.otp_length');

        return [
            'method' => ['required', 'string', Rule::enum(VerificationMethod::class)],
            'photo' => ['required', 'file'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['required', 'numeric', 'min:0', 'max:'.(int) setting('customer.verify_max_accuracy_m')],
            'requested_credit_limit' => ['required', 'string', 'regex:'.self::MONEY],
            'otp_code' => ['nullable', Rule::requiredIf($this->input('method') === VerificationMethod::Otp->value), 'string', 'regex:/^\d{'.$length.'}$/'],
        ];
    }

    /**
     * @return array{method: string, latitude: string, longitude: string, requested_credit_limit: string, otp_code: string|null}
     */
    public function verificationData(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return [
            'method' => (string) $data['method'],
            'latitude' => number_format((float) $data['latitude'], 7, '.', ''),
            'longitude' => number_format((float) $data['longitude'], 7, '.', ''),
            'requested_credit_limit' => (string) $data['requested_credit_limit'],
            'otp_code' => isset($data['otp_code']) ? (string) $data['otp_code'] : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $max = (int) setting('customer.verify_max_accuracy_m');

        return [
            'photo.required' => 'Foto toko wajib diambil.',
            'latitude.required' => 'Titik lokasi toko wajib diambil dari HP.',
            'longitude.required' => 'Titik lokasi toko wajib diambil dari HP.',
            'accuracy_m.required' => 'Akurasi lokasi tidak terbaca. Ambil ulang lokasi dari HP.',
            'accuracy_m.max' => "Akurasi lokasi belum cukup (lebih dari {$max} m). Pindah ke tempat terbuka lalu ambil ulang lokasi.",
            'requested_credit_limit.regex' => 'Limit kredit harus angka rupiah, mis. 5000000.',
            'otp_code.required' => 'Masukkan kode OTP dari pemilik kios.',
            'otp_code.regex' => 'Kode OTP tidak sesuai format.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'method' => 'cara verifikasi',
            'photo' => 'foto toko',
            'latitude' => 'lintang',
            'longitude' => 'bujur',
            'accuracy_m' => 'akurasi lokasi',
            'requested_credit_limit' => 'limit kredit',
            'otp_code' => 'kode OTP',
        ];
    }
}
