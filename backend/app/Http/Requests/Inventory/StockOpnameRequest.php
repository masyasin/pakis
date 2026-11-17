<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Access\DataScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Inventory\Enums\StockOpnameScope;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Buat opname draf (FR-A28, BR-162). Lokasi = gudang/toko aktif dalam scope Stok pengguna. Cakupan
 * selain `full` wajib `scope_ids`: rak milik lokasi itu, kategori, atau produk yang ada. Hitung buta
 * kosong = bawaan `inventory.opname_blind_count_default`.
 */
class StockOpnameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:stock_opnames.manage`; scope lokasi dicek di after()
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'stock_location_id' => ['required', 'integer', Rule::exists('stock_locations', 'id')->whereIn('type', ['warehouse', 'store'])->where('is_active', true)->whereNull('deleted_at')],
            'scope' => ['required', Rule::enum(StockOpnameScope::class)],
            'scope_ids' => ['nullable', 'array', 'max:500', Rule::requiredIf(fn () => $this->input('scope') !== StockOpnameScope::Full->value)],
            'scope_ids.*' => ['integer', 'min:1', 'distinct'],
            'blind_count' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $user = $this->user();
            $location = (int) $this->input('stock_location_id');
            if (! $user instanceof User || ! app(DataScope::class)->forStock(StockLocation::query()->whereKey($location), $user, 'stock_locations.id')->exists()) {
                $v->errors()->add('stock_location_id', 'Lokasi tidak ditemukan.');

                return;
            }
            $ids = $this->scopeIds() ?? [];
            $found = match (StockOpnameScope::from((string) $this->input('scope'))) {
                StockOpnameScope::Full => 0,
                StockOpnameScope::Bin => StockBin::query()->where('stock_location_id', $location)->whereIn('id', $ids)->count(),
                StockOpnameScope::Category => ProductCategory::query()->whereIn('id', $ids)->count(),
                StockOpnameScope::Product => Product::query()->whereIn('id', $ids)->count(),
            };
            if ($ids !== [] && $found !== count($ids)) {
                $v->errors()->add('scope_ids', 'Sebagian pilihan cakupan tidak ditemukan di lokasi ini.');
            }
        }];
    }

    /** @return array{stock_location_id: int, scope: StockOpnameScope, scope_ids: list<int>|null, blind_count: bool, note: string|null} */
    public function document(): array
    {
        $scope = StockOpnameScope::from((string) $this->validated('scope'));
        $note = trim((string) $this->validated('note', ''));
        $blind = $this->validated('blind_count');

        return [
            'stock_location_id' => (int) $this->validated('stock_location_id'),
            'scope' => $scope,
            'scope_ids' => $scope === StockOpnameScope::Full ? null : $this->scopeIds(),
            'blind_count' => $blind === null ? (bool) setting('inventory.opname_blind_count_default') : (bool) $blind,
            'note' => $note === '' ? null : $note,
        ];
    }

    /** @return list<int>|null */
    private function scopeIds(): ?array
    {
        $ids = $this->input('scope_ids');

        return is_array($ids) ? array_values(array_map('intval', $ids)) : null;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['scope_ids.required' => 'Pilih rak, kategori, atau produk yang akan dihitung.'];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['stock_location_id' => 'lokasi', 'scope' => 'cakupan', 'scope_ids' => 'pilihan cakupan', 'note' => 'catatan'];
    }
}
