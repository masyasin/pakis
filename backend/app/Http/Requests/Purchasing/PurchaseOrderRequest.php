<?php

namespace App\Http\Requests\Purchasing;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Money\Qty;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah/ubah PO draf (FR-A24, BR-160). Angka boleh format id-ID ("12.500", "2,5") dan dinormalkan
 * lewat Money/Qty (tanpa float). Total TIDAK diterima dari klien (dihitung SavePurchaseOrder).
 * Gudang tujuan wajib gudang/toko aktif dalam scope Stok pengguna.
 */
class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:purchase_orders.create`; status draf dicek Policy & Action
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');
        if (! is_array($lines)) {
            return;
        }

        $this->merge(['lines' => array_map(function ($row) {
            if (! is_array($row)) {
                return $row;
            }
            foreach (['qty' => Qty::class, 'unit_price' => Money::class] as $key => $type) {
                $value = $row[$key] ?? null;
                if (is_string($value) && preg_match('/^[\d.,\s]+$/', trim($value)) === 1) {
                    $row[$key] = (string) $type::fromInput($value);
                }
            }
            if (is_string($row['discount_pct'] ?? null)) {
                $row['discount_pct'] = str_replace(',', '.', trim($row['discount_pct'])) ?: '0';
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
            'order_date' => ['required', 'date_format:Y-m-d'],
            'expected_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_unit_id' => ['required', 'integer', 'min:1'],
            'lines.*.qty' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.unit_price' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'lines.*.discount_pct' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
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
                $v->errors()->add('stock_location_id', 'Gudang tujuan tidak ditemukan.');
            }
            /** @var array<int, array<string, mixed>> $lines */
            $lines = $this->input('lines', []);
            foreach ($lines as $i => $line) {
                if (BigDecimal::of((string) ($line['discount_pct'] ?? '0'))->isGreaterThanOrEqualTo(100)) {
                    $v->errors()->add("lines.{$i}.discount_pct", 'Diskon harus di bawah 100%.');
                }
            }
        }];
    }

    /** @return list<array{product_unit_id: int, qty: string, unit_price: string, discount_pct: string, note: string|null}> */
    public function lines(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->validated('lines', []);

        return array_values(array_map(fn (array $r) => [
            'product_unit_id' => (int) $r['product_unit_id'],
            'qty' => (string) $r['qty'],
            'unit_price' => (string) $r['unit_price'],
            'discount_pct' => ($d = (string) ($r['discount_pct'] ?? '')) === '' ? '0' : $d,
            'note' => isset($r['note']) && trim((string) $r['note']) !== '' ? trim((string) $r['note']) : null,
        ], $rows));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.required' => 'Isi minimal satu baris produk.',
            'lines.*.qty.regex' => 'Jumlah berupa angka, maks 3 desimal.',
            'lines.*.qty.not_regex' => 'Jumlah harus lebih dari 0.',
            'lines.*.unit_price.regex' => 'Harga berupa angka, maks 2 desimal.',
            'expected_date.after_or_equal' => 'Perkiraan datang tidak boleh sebelum tanggal PO.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'supplier_id' => 'supplier', 'stock_location_id' => 'gudang tujuan', 'order_date' => 'tanggal PO',
            'expected_date' => 'perkiraan datang', 'payment_term_days' => 'tempo bayar', 'lines' => 'baris',
        ];
    }
}
