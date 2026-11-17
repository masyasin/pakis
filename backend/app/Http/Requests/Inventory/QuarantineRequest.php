<?php

namespace App\Http\Requests\Inventory;

use App\Support\Money\Qty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Karantina manual, pindah rak, dan ajukan lepas karantina (15 §5 `admin.stock.quarantine` /
 * `bin-move` / `release`, BR-164). `action` memilih aturan; qty satuan dasar, format id-ID
 * dinormalkan lewat Qty. Status batch, zona rak, & scope diperiksa Action/controller.
 */
class QuarantineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:stock_quarantine.manage`; scope lokasi batch dicek controller
    }

    protected function prepareForValidation(): void
    {
        $qty = $this->input('qty_base');
        if (is_string($qty) && preg_match('/^[\d.,\s]+$/', trim($qty)) === 1) {
            $this->merge(['qty_base' => (string) Qty::fromInput($qty)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $route = (string) $this->route()?->getName();

        return [
            'stock_batch_id' => [Rule::requiredIf($route !== 'admin.stock.release'), 'nullable', 'integer', 'min:1'],
            'qty_base' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'reason_id' => [Rule::requiredIf($route === 'admin.stock.quarantine'), 'nullable', 'integer', 'min:1'],
            'stock_bin_id' => [Rule::requiredIf($route === 'admin.stock.bin-move'), 'nullable', 'integer', 'min:1'],
            'target_bin_id' => [Rule::requiredIf($route === 'admin.stock.release'), 'nullable', 'integer', 'min:1'],
            'reason' => [Rule::requiredIf($route === 'admin.stock.release'), 'nullable', 'string', 'min:5', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function qty(): Qty
    {
        return Qty::of((string) $this->validated('qty_base'));
    }

    public function note(): ?string
    {
        $note = trim((string) $this->validated('note', ''));

        return $note === '' ? null : $note;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'qty_base.regex' => 'Jumlah berupa angka, maks 3 desimal.',
            'qty_base.not_regex' => 'Jumlah harus lebih dari 0.',
            'reason_id.required' => 'Pilih alasan karantina.',
            'stock_bin_id.required' => 'Pilih rak tujuan.',
            'target_bin_id.required' => 'Pilih rak tujuan.',
            'reason.required' => 'Tulis alasan lepas karantina.',
        ];
    }
}
