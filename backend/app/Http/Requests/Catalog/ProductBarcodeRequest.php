<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/** Tambah barcode (FR-A09): EAN/UPC/Code128 umum → huruf, angka, strip, titik; unik global dicek Action. */
class ProductBarcodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:products.manage`
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_unit_id' => ['required', 'integer'],
            'barcode' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9.\-]{4,64}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['barcode.regex' => 'Barcode 4–64 karakter: huruf, angka, titik, atau strip.'];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['product_unit_id' => 'satuan'];
    }
}
