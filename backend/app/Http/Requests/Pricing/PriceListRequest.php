<?php

namespace App\Http\Requests\Pricing;

use App\Domain\Pricing\Enums\PriceListKind;
use App\Domain\Pricing\Models\PriceList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Tambah/ubah daftar harga (FR-A19). Jenis & targetnya hanya diisi saat tambah. */
class PriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:prices.manage`
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code')) ? strtoupper(trim($this->input('code'))) : $this->input('code'),
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $current = $this->route('list');
        $creating = ! $current instanceof PriceList;

        return [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9-]{2,20}$/',
                Rule::unique('price_lists', 'code')->ignore($creating ? null : $current->id)],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'kind' => $creating ? ['required', Rule::enum(PriceListKind::class)] : ['prohibited'],
            'sales_area_id' => $creating
                ? ['nullable', 'required_if:kind,area', 'integer', Rule::exists('sales_areas', 'id')->whereNull('deleted_at')]
                : ['prohibited'],
            'stock_location_id' => $creating
                ? ['nullable', 'required_if:kind,store', 'integer', Rule::exists('stock_locations', 'id')->where('type', 'store')->whereNull('deleted_at')]
                : ['prohibited'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya huruf kapital, angka, dan tanda hubung (-), 2–20 karakter. Contoh: GROSIR.',
            'sales_area_id.required_if' => 'Pilih area untuk daftar harga area.',
            'stock_location_id.required_if' => 'Pilih toko untuk daftar harga toko.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['code' => 'kode', 'name' => 'nama', 'kind' => 'jenis', 'sales_area_id' => 'area', 'stock_location_id' => 'toko', 'description' => 'keterangan'];
    }
}
