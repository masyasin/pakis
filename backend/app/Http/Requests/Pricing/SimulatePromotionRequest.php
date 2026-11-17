<?php

namespace App\Http\Requests\Pricing;

use App\Domain\Pricing\Models\Promotion;
use App\Support\Money\Qty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Simulasi harga + promo (BR-169): kios opsional, kanal, waktu lokal opsional, ≤ 50 baris. */
class SimulatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:promotions.view`
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');
        if (is_array($lines)) {
            $this->merge(['lines' => array_map(function ($row) {
                if (is_array($row) && is_string($row['qty'] ?? null) && preg_match('/^[\d.,\s]+$/', trim($row['qty'])) === 1) {
                    $row['qty'] = (string) Qty::fromInput($row['qty']);
                }

                return $row;
            }, $lines)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['required', Rule::in(Promotion::CHANNELS)],
            'at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_unit_id' => ['required', 'integer', 'min:1'],
            'lines.*.qty' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/', 'not_regex:/^0+(\.0+)?$/'],
        ];
    }
}
