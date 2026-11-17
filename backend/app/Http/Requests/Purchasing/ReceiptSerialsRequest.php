<?php

namespace App\Http\Requests\Purchasing;

use App\Domain\Inventory\Enums\SerialInputMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isi nomor seri satu baris penerimaan (BR-144, SEC-109): `serials` = daftar lengkap nomor baris
 * (scan satu-satu / tempel satu per baris), `method` = cara input nomor BARU di kiriman ini;
 * `manual` wajib `photo` (jenis & ukuran diperiksa SecureUpload dari isi berkas, bukan MIME klien).
 */
class ReceiptSerialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:goods_receipts.create`; status & scope dicek controller/Policy
    }

    protected function prepareForValidation(): void
    {
        // Daftar tempel: satu nomor per baris / dipisah koma atau titik koma.
        $pasted = $this->input('serials_text');
        if (is_string($pasted) && ! is_array($this->input('serials'))) {
            $this->merge(['serials' => array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $pasted) ?: []), fn (string $s) => $s !== ''))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Daftar kosong (hapus semua) lewat FormData tidak mengirim kunci sama sekali, jadi tidak `present`.
            'serials' => ['sometimes', 'array', 'max:1000'],
            'serials.*' => ['string', 'max:120'],
            'method' => ['required', Rule::enum(SerialInputMethod::class)],
            'photo' => [Rule::requiredIf(fn () => $this->input('method') === SerialInputMethod::Manual->value), 'nullable', 'file'],
        ];
    }

    /** @return list<string> */
    public function serials(): array
    {
        /** @var array<int, string> $rows */
        $rows = $this->validated('serials', []);

        return array_values(array_map('strval', $rows));
    }

    /** Bukan `method()`: nama itu milik Request (metode HTTP). */
    public function inputMethod(): SerialInputMethod
    {
        return SerialInputMethod::from((string) $this->validated('method'));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photo.required' => 'Input manual wajib menyertakan foto label nomor seri.',
            'serials.max' => 'Maksimal 1.000 nomor seri per baris.',
        ];
    }
}
