<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Enums\DisposalMethod;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use App\Support\Money\Qty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Buat berita acara draf (BR-163). Lokasi gudang/toko aktif dalam scope Stok; alasan
 * `disposal_reason`; metode; baris batch + qty satuan dasar (status karantina/ED dicek Action).
 */
class DisposalReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:stock_disposals.manage`; scope lokasi dicek di after()
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
            'stock_location_id' => ['required', 'integer', Rule::exists('stock_locations', 'id')->whereIn('type', ['warehouse', 'store'])->whereNull('deleted_at')],
            'reason_id' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum(DisposalMethod::class)],
            'planned_date' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.stock_batch_id' => ['required', 'integer', 'min:1', 'distinct'],
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
                $v->errors()->add('stock_location_id', 'Lokasi tidak ditemukan.');
            }
        }];
    }

    /** @return array{stock_location_id: int, reason_id: int, method: DisposalMethod, planned_date: string|null, note: string|null} */
    public function document(): array
    {
        $note = trim((string) $this->validated('note', ''));
        $planned = $this->validated('planned_date');

        return [
            'stock_location_id' => (int) $this->validated('stock_location_id'),
            'reason_id' => (int) $this->validated('reason_id'),
            'method' => DisposalMethod::from((string) $this->validated('method')),
            'planned_date' => is_string($planned) && $planned !== '' ? $planned : null,
            'note' => $note === '' ? null : $note,
        ];
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
            'lines.required' => 'Isi minimal satu batch.',
            'lines.*.qty_base.regex' => 'Jumlah berupa angka, maks 3 desimal.',
            'lines.*.qty_base.not_regex' => 'Jumlah harus lebih dari 0.',
        ];
    }
}
