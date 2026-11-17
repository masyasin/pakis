<?php

namespace App\Http\Requests\Inventory;

use App\Support\Money\Qty;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Terima / hitung ulang mutasi (BR-161). Per baris: total qty yang dihitung (satuan dasar), rak
 * tujuan opsional, alasan bila kurang. Batas atas (≤ dikirim → `TRANSFER_OVER_RECEIPT`), rak milik
 * lokasi tujuan, dan alasan wajib saat selisih diperiksa ReceiveStockTransfer di bawah kunci baris.
 */
class ReceiveStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:stock_transfers.receive`; scope tujuan & status dicek Policy
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');
        if (! is_array($lines)) {
            return;
        }

        $this->merge(['lines' => array_map(function ($row) {
            if (is_array($row) && is_string($row['qty_received'] ?? null) && preg_match('/^[\d.,\s]+$/', trim($row['qty_received'])) === 1) {
                $row['qty_received'] = (string) Qty::fromInput($row['qty_received']);
            }

            return $row;
        }, $lines)]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:400'],
            'lines.*.line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.qty_received' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/'],
            'lines.*.dest_bin_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.variance_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return list<array{line_id: int, qty_received: string, dest_bin_id: int|null, variance_reason: string|null}> */
    public function lines(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->validated('lines', []);

        return array_values(array_map(fn (array $r) => [
            'line_id' => (int) $r['line_id'],
            'qty_received' => (string) $r['qty_received'],
            'dest_bin_id' => isset($r['dest_bin_id']) ? (int) $r['dest_bin_id'] : null,
            'variance_reason' => isset($r['variance_reason']) ? (string) $r['variance_reason'] : null,
        ], $rows));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.required' => 'Isi jumlah diterima untuk setiap baris.',
            'lines.*.qty_received.required' => 'Isi jumlah diterima.',
            'lines.*.qty_received.regex' => 'Jumlah berupa angka, maks 3 desimal.',
        ];
    }
}
