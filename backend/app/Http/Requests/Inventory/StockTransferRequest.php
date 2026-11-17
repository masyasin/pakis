<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use App\Support\Money\Qty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah/ubah mutasi draf (FR-A27, BR-161). Asal & tujuan hanya gudang/toko aktif (keputusan Mas:
 * tujuan kendaraan = muat canvas Paket C), berbeda satu sama lain; asal wajib dalam scope Stok
 * pengguna (pengirim bekerja di lokasi asal). Tujuan boleh di luar scope. Qty satuan dasar, format
 * id-ID ("2,5") dinormalkan lewat Qty (tanpa float). Kendaraan hanya informasi pengangkut.
 */
class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:stock_transfers.create`; status draf & scope dicek Policy/Action
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
        $location = fn () => Rule::exists('stock_locations', 'id')->whereIn('type', ['warehouse', 'store'])->where('is_active', true)->whereNull('deleted_at');

        return [
            'from_stock_location_id' => ['required', 'integer', $location()],
            'to_stock_location_id' => ['required', 'integer', 'different:from_stock_location_id', $location()],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', 'min:1'],
            'lines.*.qty_base' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.source_batch_id' => ['nullable', 'integer', 'min:1'],
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
            $from = (int) $this->input('from_stock_location_id');
            if (! $user instanceof User || ! app(DataScope::class)->forStock(StockLocation::query()->whereKey($from), $user, 'stock_locations.id')->exists()) {
                $v->errors()->add('from_stock_location_id', 'Lokasi asal tidak ditemukan.');
            }
            /** @var array<int, array<string, mixed>> $lines */
            $lines = $this->input('lines', []);
            $batches = array_filter(array_map(fn ($l) => $l['source_batch_id'] ?? null, $lines));
            if (count($batches) !== count(array_unique($batches))) {
                $v->errors()->add('lines', 'Satu batch hanya boleh muncul di satu baris.');
            }
        }];
    }

    /** @return array{from_stock_location_id: int, to_stock_location_id: int, vehicle_id: int|null, note: string|null} */
    public function document(): array
    {
        $note = trim((string) $this->validated('note', ''));

        return [
            'from_stock_location_id' => (int) $this->validated('from_stock_location_id'),
            'to_stock_location_id' => (int) $this->validated('to_stock_location_id'),
            'vehicle_id' => $this->validated('vehicle_id') === null ? null : (int) $this->validated('vehicle_id'),
            'note' => $note === '' ? null : $note,
        ];
    }

    /** @return list<array{product_id: int, qty_base: string, source_batch_id: int|null}> */
    public function lines(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->validated('lines', []);

        return array_values(array_map(fn (array $r) => [
            'product_id' => (int) $r['product_id'],
            'qty_base' => (string) $r['qty_base'],
            'source_batch_id' => isset($r['source_batch_id']) ? (int) $r['source_batch_id'] : null,
        ], $rows));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.required' => 'Isi minimal satu baris produk.',
            'lines.*.qty_base.regex' => 'Jumlah berupa angka, maks 3 desimal.',
            'lines.*.qty_base.not_regex' => 'Jumlah harus lebih dari 0.',
            'to_stock_location_id.different' => 'Lokasi tujuan harus berbeda dari lokasi asal.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'from_stock_location_id' => 'lokasi asal', 'to_stock_location_id' => 'lokasi tujuan',
            'vehicle_id' => 'kendaraan', 'note' => 'catatan', 'lines' => 'baris',
        ];
    }
}
