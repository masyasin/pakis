<?php

namespace App\Http\Requests\Inventory;

use App\Support\Money\Qty;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Tambah batch temuan ke lembar hitung (BR-162(7)): produk, nomor batch, ED, rak, qty dihitung.
 * Cakupan & keberadaan rak di lokasi dicek AddFoundBatch di bawah kunci opname.
 */
class FoundBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:stock_opnames.manage`; status & scope dicek Policy
    }

    protected function prepareForValidation(): void
    {
        $qty = $this->input('qty');
        if (is_string($qty) && preg_match('/^[\d.,\s]+$/', trim($qty)) === 1) {
            $this->merge(['qty' => (string) Qty::fromInput($qty)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            'batch_no' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9.\/\-]+$/'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'stock_bin_id' => ['nullable', 'integer', 'min:1'],
            'qty' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array{product_id: int, batch_no: string, expiry_date: string|null, stock_bin_id: int|null, qty: string, note: string|null} */
    public function foundBatch(): array
    {
        $note = trim((string) $this->validated('note', ''));
        $expiry = $this->validated('expiry_date');

        return [
            'product_id' => (int) $this->validated('product_id'),
            'batch_no' => (string) $this->validated('batch_no'),
            'expiry_date' => is_string($expiry) && $expiry !== '' ? $expiry : null,
            'stock_bin_id' => $this->validated('stock_bin_id') === null ? null : (int) $this->validated('stock_bin_id'),
            'qty' => (string) $this->validated('qty'),
            'note' => $note === '' ? null : $note,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'batch_no.regex' => 'Nomor batch hanya huruf, angka, titik, garis miring, dan strip.',
            'qty.regex' => 'Jumlah berupa angka, maks 3 desimal.',
            'qty.not_regex' => 'Jumlah batch temuan harus lebih dari 0.',
        ];
    }
}
