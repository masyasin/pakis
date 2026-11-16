<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tambah/ubah supplier (FR-A11, SEC-108). Kode dibuat sistem (dilarang dikirim). Nomor rekening
 * hanya dikirim bila diganti; kosong = tetap. Produk dipasok = daftar berulang.
 */
class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:suppliers.manage`
    }

    protected function prepareForValidation(): void
    {
        $account = $this->input('bank_account_no');
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            // Rekening disimpan digit saja: spasi/titik/strip dari buku tabungan dibuang.
            'bank_account_no' => is_string($account) ? (string) preg_replace('/[\s.\-]+/', '', $account) : $account,
            'tax_id' => is_string($this->input('tax_id')) ? trim($this->input('tax_id')) : $this->input('tax_id'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['prohibited'],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'principal_id' => ['nullable', 'integer', Rule::exists('principals', 'id')->whereNull('deleted_at')],
            // NPWP 15 atau 16 digit (format lama/baru), boleh dengan titik & strip.
            'tax_id' => ['nullable', 'string', 'max:30', 'regex:/^[0-9.\-]{15,30}$/'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+() .\-]{6,20}$/'],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'address' => ['nullable', 'string', 'max:2000'],
            'village_code' => ['nullable', 'string', Rule::exists('villages', 'code')],
            'payment_term_days' => ['required', 'integer', 'min:0', 'max:365'],
            'bank_name' => ['nullable', 'string', 'max:60'],
            'bank_account_no' => ['nullable', 'string', 'regex:/^[0-9]{5,30}$/'],
            'bank_account_name' => ['nullable', 'string', 'max:150'],
            'clear_bank_account' => ['sometimes', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'products' => ['sometimes', 'array', 'max:500'],
            'products.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'products.*.supplier_sku' => ['nullable', 'string', 'max:40'],
            'products.*.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'products.*.is_preferred' => ['required', 'boolean'],
        ];
    }

    /**
     * Produk dipasok yang sudah dirapikan; null bila form tidak mengirim kunci `products`.
     *
     * @return list<array{product_id: int, supplier_sku: string|null, lead_time_days: int|null, is_preferred: bool}>|null
     */
    public function productRows(): ?array
    {
        if (! $this->has('products')) {
            return null;
        }

        $rows = $this->validated('products', []);

        return array_values(array_map(fn (array $r) => [
            'product_id' => (int) $r['product_id'],
            'supplier_sku' => isset($r['supplier_sku']) && trim((string) $r['supplier_sku']) !== '' ? trim((string) $r['supplier_sku']) : null,
            'lead_time_days' => isset($r['lead_time_days']) && $r['lead_time_days'] !== '' ? (int) $r['lead_time_days'] : null,
            'is_preferred' => (bool) $r['is_preferred'],
        ], is_array($rows) ? $rows : []));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tax_id.regex' => 'NPWP berisi 15 atau 16 digit (titik dan strip boleh).',
            'bank_account_no.regex' => 'Nomor rekening hanya angka, 5–30 digit.',
            'phone.regex' => 'Telepon hanya angka, spasi, +, -, dan kurung (6–20 karakter).',
            'products.*.product_id.distinct' => 'Produk yang sama tercantum dua kali.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nama', 'principal_id' => 'prinsipal', 'tax_id' => 'NPWP', 'contact_name' => 'nama kontak',
            'phone' => 'telepon', 'address' => 'alamat', 'village_code' => 'desa', 'payment_term_days' => 'tempo bayar',
            'bank_name' => 'nama bank', 'bank_account_no' => 'nomor rekening', 'bank_account_name' => 'nama pemilik rekening',
            'products.*.product_id' => 'produk', 'products.*.lead_time_days' => 'lead time',
        ];
    }
}
