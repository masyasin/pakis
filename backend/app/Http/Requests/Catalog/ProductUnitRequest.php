<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Reference\Enums\ReferenceGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Tambah/ubah satuan produk (BR-150). Faktor string desimal kanonik ("24", "0.5"), maks 3 desimal, > 0. */
class ProductUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:products.manage`
    }

    protected function prepareForValidation(): void
    {
        // Ketikan id-ID ("0,5") → kanonik ("0.5"); ribuan bertitik tidak lazim untuk faktor.
        if (is_string($this->input('factor'))) {
            $this->merge(['factor' => str_replace(',', '.', trim($this->input('factor')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->route('unit') === null;

        return [
            'unit_id' => $creating
                ? ['required', 'integer', Rule::exists('reference_items', 'id')->where('group_code', ReferenceGroup::Unit->value)]
                : ['prohibited'],
            'factor' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'is_default_sales' => ['required', 'boolean'],
            'is_default_purchase' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'factor.regex' => 'Isi dalam satuan dasar harus angka lebih dari 0, maksimal 3 angka di belakang koma.',
            'factor.not_regex' => 'Isi dalam satuan dasar harus lebih dari 0.',
            'unit_id.prohibited' => 'Satuan tidak bisa diganti; tambahkan satuan baru.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['unit_id' => 'satuan', 'factor' => 'isi dalam satuan dasar'];
    }
}
