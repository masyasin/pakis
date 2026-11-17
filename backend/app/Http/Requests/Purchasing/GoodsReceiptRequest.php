<?php

namespace App\Http\Requests\Purchasing;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah/ubah draf penerimaan (FR-A25, BR-158). Angka boleh format id-ID dan dinormalkan lewat
 * Qty/UnitCost (tanpa float). Biaya baris TIDAK diterima bila ada PO (dihitung dari PO, SEC-104).
 * `received_at` = waktu lokal kantor `Y-m-d\TH:i` (dikonversi ke UTC di Action). Nomor surat jalan
 * supplier wajib (TA-02). Tanpa PO: supplier & lokasi stok wajib dan lokasi dalam scope Stok.
 */
class GoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:goods_receipts.create`; status dicek Policy & Action
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
            foreach (['qty_received' => Qty::class, 'qty_rejected' => Qty::class, 'unit_cost' => UnitCost::class] as $key => $type) {
                $value = $row[$key] ?? null;
                if (is_string($value) && preg_match('/^[\d.,\s]+$/', trim($value)) === 1) {
                    $row[$key] = (string) $type::fromInput($value);
                }
            }

            return $row;
        }, $lines)]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $withoutPo = $this->input('purchase_order_id') === null || $this->input('purchase_order_id') === '';

        return [
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')],
            'supplier_id' => [$withoutPo ? 'required' : 'nullable', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'stock_location_id' => [$withoutPo ? 'required' : 'nullable', 'integer', Rule::exists('stock_locations', 'id')->whereIn('type', ['warehouse', 'store'])->where('is_active', true)->whereNull('deleted_at')],
            'received_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'supplier_delivery_no' => ['required', 'string', 'max:60'],
            'vehicle_plate' => ['nullable', 'string', 'max:15'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.id' => ['nullable', 'integer', 'min:1'],
            'lines.*.purchase_order_line_id' => [$withoutPo ? 'nullable' : 'required', 'integer', 'min:1'],
            'lines.*.product_unit_id' => [$withoutPo ? 'required' : 'nullable', 'integer', 'min:1'],
            'lines.*.qty_received' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/'],
            'lines.*.qty_rejected' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/'],
            'lines.*.reject_reason_id' => ['nullable', 'integer', Rule::exists('reference_items', 'id')],
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.registration_no_seen' => ['nullable', 'string', 'max:60'],
            'lines.*.stock_bin_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.unit_cost' => [$withoutPo ? 'required' : 'prohibited', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty() || $this->input('purchase_order_id') !== null) {
                return;
            }
            $user = $this->user();
            $location = (int) $this->input('stock_location_id');
            if (! $user instanceof User || ! app(DataScope::class)->forStock(StockLocation::query()->whereKey($location), $user, 'stock_locations.id')->exists()) {
                $v->errors()->add('stock_location_id', 'Lokasi stok tidak ditemukan.');
            }
        }];
    }

    /** @return list<array<string, mixed>> */
    public function lines(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->validated('lines', []);
        $text = fn (array $r, string $k) => isset($r[$k]) && trim((string) $r[$k]) !== '' ? trim((string) $r[$k]) : null;

        return array_values(array_map(fn (array $r) => [
            'id' => isset($r['id']) ? (int) $r['id'] : null,
            'purchase_order_line_id' => isset($r['purchase_order_line_id']) ? (int) $r['purchase_order_line_id'] : null,
            'product_unit_id' => isset($r['product_unit_id']) ? (int) $r['product_unit_id'] : null,
            'qty_received' => (string) $r['qty_received'],
            'qty_rejected' => $text($r, 'qty_rejected') ?? '0',
            'reject_reason_id' => isset($r['reject_reason_id']) ? (int) $r['reject_reason_id'] : null,
            'batch_no' => $text($r, 'batch_no'),
            'expiry_date' => $text($r, 'expiry_date'),
            'registration_no_seen' => $text($r, 'registration_no_seen'),
            'stock_bin_id' => isset($r['stock_bin_id']) ? (int) $r['stock_bin_id'] : null,
            'unit_cost' => $text($r, 'unit_cost'),
        ], $rows));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.required' => 'Isi minimal satu baris produk.',
            'lines.*.qty_received.regex' => 'Jumlah berupa angka, maks 3 desimal.',
            'lines.*.qty_rejected.regex' => 'Jumlah ditolak berupa angka, maks 3 desimal.',
            'lines.*.unit_cost.prohibited' => 'Biaya mengikuti PO dan tidak bisa diubah di penerimaan.',
            'received_at.date_format' => 'Isi tanggal dan jam terima.',
            'supplier_delivery_no.required' => 'Nomor surat jalan supplier wajib diisi.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'purchase_order_id' => 'PO', 'supplier_id' => 'supplier', 'stock_location_id' => 'lokasi stok', 'received_at' => 'waktu terima',
            'supplier_delivery_no' => 'nomor surat jalan', 'vehicle_plate' => 'plat kendaraan', 'lines' => 'baris',
        ];
    }
}
