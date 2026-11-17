<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\Listings\StockLevelListing;
use App\Support\Money\Qty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Upsert stok minimum/maksimum satu produk di satu lokasi stok (`admin.stock-levels.upsert`, 15 §5).
 * Lokasi wajib gudang/toko aktif DALAM scope Stok pengguna (sama dengan pilihan filter listing),
 * qty satuan dasar format id-ID dinormalkan lewat Qty. Maks < min ditolak SaveStockLevel.
 */
class StockLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:stock_locations.manage`; scope lokasi dicek after()
    }

    protected function prepareForValidation(): void
    {
        foreach (['min_qty_base', 'max_qty_base'] as $key) {
            $value = $this->input($key);
            if (is_string($value) && trim($value) === '') {
                $this->merge([$key => null]);
            } elseif (is_string($value) && preg_match('/^[\d.,\s]+$/', trim($value)) === 1) {
                $this->merge([$key => (string) Qty::fromInput($value)]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'stock_location_id' => ['required', 'integer', 'min:1'],
            'min_qty_base' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/'],
            'max_qty_base' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/'],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            if (! StockLevelListing::locations($this->user())->whereKey((int) $this->input('stock_location_id'))->exists()) {
                $v->errors()->add('stock_location_id', 'Lokasi stok tidak ditemukan.');
            }
        }];
    }

    /** @return array{product_id: int, stock_location_id: int, min: string, max: string|null} */
    public function level(): array
    {
        $max = $this->input('max_qty_base');

        return [
            'product_id' => (int) $this->input('product_id'),
            'stock_location_id' => (int) $this->input('stock_location_id'),
            'min' => (string) $this->input('min_qty_base'),
            'max' => is_string($max) ? $max : null,
        ];
    }
}
