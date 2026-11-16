<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Enums\CategoryKind;
use App\Domain\Catalog\Enums\StorageZone;
use App\Domain\Catalog\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah/ubah kategori produk (FR-A06, BR-151). Aturan pohon (siklus, maks 3 tingkat) di
 * CategoryTree karena butuh kunci tabel; di sini bentuk data & keunikan kode.
 */
class ProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:products.manage`
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $category = $this->route('category');
        $id = $category instanceof ProductCategory ? $category->id : null;

        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9._-]+$/', Rule::unique('product_categories', 'code')->ignore($id)],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'parent_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->whereNull('deleted_at')],
            'kind' => ['required', Rule::enum(CategoryKind::class)],
            'requires_batch' => ['required', 'boolean'],
            'requires_expiry' => ['required', 'boolean'],
            'requires_serial' => ['required', 'boolean'],
            'requires_registration' => ['required', 'boolean'],
            'default_bin_zone' => ['required', Rule::enum(StorageZone::class)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            // Sama dengan CHECK `pc_expiry_chk`: ED dicatat per batch, jadi wajib ED ⇒ wajib batch.
            if ($this->boolean('requires_expiry') && ! $this->boolean('requires_batch')) {
                $validator->errors()->add('requires_expiry', 'Wajib tanggal kedaluwarsa hanya bisa bila wajib batch juga dicentang.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['code' => 'kode', 'name' => 'nama', 'parent_id' => 'induk', 'kind' => 'jenis', 'default_bin_zone' => 'zona rak', 'sort_order' => 'urutan'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.regex' => 'Kode hanya huruf besar, angka, titik, garis bawah, dan strip.'];
    }
}
