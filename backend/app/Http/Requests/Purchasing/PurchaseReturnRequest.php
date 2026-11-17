<?php

namespace App\Http\Requests\Purchasing;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use App\Support\Money\Qty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah/ubah draf retur (FR-A26). Qty dasar boleh format id-ID (dinormalkan Qty, tanpa float). Biaya
 * TIDAK diterima dari klien (biaya beli batch, BR-159). Lokasi stok wajib gudang/toko aktif dalam scope
 * Stok pengguna (di luar scope dianggap tidak ada).
 */
class PurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:purchase_returns.manage`; status dicek Policy & Action
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');
        if (! is_array($lines)) {
            return;
        }
        $this->merge(['lines' => array_map(function ($row) {
            if (is_array($row) && is_string($row['qty_base'] ?? null) && preg_match('/^[\d.,\s]+$/', trim($row['qty_base'])) === 1) {
                $row['qty_base'] = (string) Qty::fromInput($row['qty_base']);
            }

            return $row;
        }, $lines)]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'stock_location_id' => ['required', 'integer', Rule::exists('stock_locations', 'id')->whereIn('type', ['warehouse', 'store'])->where('is_active', true)->whereNull('deleted_at')],
            'reason_id' => ['required', 'integer', Rule::exists('reference_items', 'id')],
            'goods_receipt_id' => ['nullable', 'integer', Rule::exists('goods_receipts', 'id')],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.stock_batch_id' => ['required', 'integer', 'min:1'],
            'lines.*.qty_base' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/', 'not_regex:/^0+(\.0+)?$/'],
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
                $v->errors()->add('stock_location_id', 'Lokasi stok tidak ditemukan.');
            }
        }];
    }

    /** @return list<array{stock_batch_id: int, qty_base: string}> */
    public function lines(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->validated('lines', []);

        return array_values(array_map(fn (array $r) => ['stock_batch_id' => (int) $r['stock_batch_id'], 'qty_base' => (string) $r['qty_base']], $rows));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.required' => 'Isi minimal satu baris batch.',
            'lines.*.qty_base.regex' => 'Jumlah berupa angka, maks 3 desimal.',
            'lines.*.qty_base.not_regex' => 'Jumlah harus lebih dari 0.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['supplier_id' => 'supplier', 'stock_location_id' => 'lokasi stok', 'reason_id' => 'alasan', 'goods_receipt_id' => 'penerimaan', 'lines' => 'baris'];
    }
}
