<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Reference\Enums\ReferenceGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah/ubah produk (FR-A08, BR-151, BR-112). Aturan penanda vs kategori (pelonggaran butuh
 * alasan) dan kunci setelah mutasi ada di Action karena butuh data kategori & stok terkini.
 */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:products.manage`
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => mb_strtoupper(trim($this->input('sku')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = ! $this->route('product') instanceof Product;
        $manualSku = setting('product.sku_mode') === 'manual';

        return [
            // SKU hanya saat tambah dengan mode manual; mode otomatis memakai NumberGenerator.
            'sku' => $creating && $manualSku
                ? ['required', 'string', 'regex:/^[A-Z0-9-]{3,30}$/', Rule::unique('products', 'sku')]
                : ['prohibited'],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'product_category_id' => ['required', 'integer', Rule::exists('product_categories', 'id')->whereNull('deleted_at')],
            // T-072: prinsipal opsional, hanya yang aktif (produk lama boleh tetap menunjuk prinsipal nonaktif).
            'principal_id' => ['nullable', 'integer', Rule::exists('principals', 'id')->whereNull('deleted_at')],
            'brand' => ['nullable', 'string', 'max:80'],
            'active_ingredient' => ['nullable', 'string', 'max:255'],
            'formulation_id' => ['nullable', 'integer', Rule::exists('reference_items', 'id')->where('group_code', ReferenceGroup::Formulation->value)],
            'package_size' => ['nullable', 'string', 'max:40'],
            'base_unit_id' => ['required', 'integer', Rule::exists('reference_items', 'id')->where('group_code', ReferenceGroup::Unit->value)],
            'description' => ['nullable', 'string', 'max:5000'],
            'usage_note' => ['nullable', 'string', 'max:5000'],
            'tracks_batch' => ['required', 'boolean'],
            'tracks_expiry' => ['required', 'boolean'],
            'tracks_serial' => ['required', 'boolean'],
            'requires_registration' => ['required', 'boolean'],
            'flag_reason' => ['nullable', 'string', 'max:255'],
            'is_restricted' => ['required', 'boolean'],
            'restricted_note' => ['nullable', 'string', 'max:255'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'min_shelf_life_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'is_sellable' => ['required', 'boolean'],
            'is_purchasable' => ['required', 'boolean'],
            'status' => ['required', Rule::enum(ProductStatus::class)],
            // Isi & batas ukuran diperiksa SecureUpload (SEC-109), bukan ekstensi.
            'photo' => ['nullable', 'file'],
            'remove_photo' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            // Sama dengan CHECK `products_expiry_chk`: ED dicatat per batch.
            if ($this->boolean('tracks_expiry') && ! $this->boolean('tracks_batch')) {
                $validator->errors()->add('tracks_expiry', 'Lacak tanggal kedaluwarsa hanya bisa bila lacak nomor batch juga dicentang.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'sku' => 'SKU', 'name' => 'nama', 'product_category_id' => 'kategori', 'base_unit_id' => 'satuan dasar',
            'formulation_id' => 'formulasi', 'package_size' => 'ukuran kemasan', 'active_ingredient' => 'bahan aktif',
            'flag_reason' => 'alasan', 'warranty_months' => 'garansi', 'min_shelf_life_months' => 'sisa umur simpan minimal',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sku.regex' => 'SKU 3–30 karakter: huruf besar, angka, dan strip.',
            'sku.prohibited' => 'SKU tidak bisa diisi atau diubah di sini.',
        ];
    }
}
