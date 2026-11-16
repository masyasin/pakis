<?php

namespace App\Http\Requests\Pricing;

use App\Support\Money\Money;
use App\Support\Money\Qty;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Isi/ubah item versi draf (FR-A19, BR-150/168). Harga & qty boleh format id-ID ("12.500",
 * "1,5"); dinormalkan lewat Money/Qty (tanpa float) sebelum divalidasi. `min_qty` > 0, harga ≥ 0.
 */
class PriceItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:prices.manage`; status draf dicek Policy & Action
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');
        if (! is_array($items)) {
            return;
        }

        $this->merge(['items' => array_map(function ($row) {
            if (! is_array($row)) {
                return $row;
            }
            foreach (['min_qty' => Qty::class, 'price' => Money::class] as $key => $type) {
                $value = $row[$key] ?? null;
                if (is_string($value) && preg_match('/^[\d.,\s]+$/', trim($value)) === 1) {
                    $row[$key] = (string) $type::fromInput($value);
                }
            }

            return $row;
        }, $items)]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:500'],
            'items.*.product_unit_id' => ['required', 'integer'],
            'items.*.min_qty' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'items.*.price' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'remove' => ['sometimes', 'array', 'max:500'],
            'remove.*' => ['integer'],
        ];
    }

    /**
     * @return list<array{product_unit_id: int, min_qty: string, price: string}>
     */
    public function rows(): array
    {
        /** @var array<int, array<string, mixed>> $items */
        $items = $this->validated('items', []);

        return array_values(array_map(fn (array $r) => [
            'product_unit_id' => (int) $r['product_unit_id'],
            'min_qty' => (string) $r['min_qty'],
            'price' => (string) $r['price'],
        ], $items));
    }

    /** @return list<int> */
    public function removeIds(): array
    {
        /** @var array<int, mixed> $ids */
        $ids = $this->validated('remove', []);

        return array_values(array_map('intval', $ids));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.*.min_qty.regex' => 'Jumlah minimal berupa angka, maks 3 desimal.',
            'items.*.min_qty.not_regex' => 'Jumlah minimal harus lebih dari 0.',
            'items.*.price.regex' => 'Harga berupa angka, maks 2 desimal.',
        ];
    }
}
