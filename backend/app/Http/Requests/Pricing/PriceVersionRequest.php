<?php

namespace App\Http\Requests\Pricing;

use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Versi baru daftar harga (FR-A19): kosong, salin, atau salin ±%. `effective_from` waktu lokal
 * kantor dari input `datetime-local` (YYYY-MM-DDTHH:MM). Persen -100 s.d. 1000, maks 2 desimal.
 */
class PriceVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:prices.manage`
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('percent'))) {
            $this->merge(['percent' => str_replace(',', '.', trim($this->input('percent')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['empty', 'copy', 'copy_percent'])],
            'source_version_id' => ['nullable', 'required_unless:mode,empty', 'integer', Rule::exists('price_list_versions', 'id')],
            'percent' => ['nullable', 'required_if:mode,copy_percent', 'string', 'regex:/^-?\d{1,4}(\.\d{1,2})?$/'],
            'effective_from' => ['required', 'date_format:Y-m-d\TH:i'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function ($validator) {
            $percent = $this->input('percent');
            if ($this->input('mode') === 'copy_percent' && is_string($percent) && preg_match('/^-?\d{1,4}(\.\d{1,2})?$/', $percent) === 1
                && (BigDecimal::of($percent)->isLessThan(-100) || BigDecimal::of($percent)->isGreaterThan(1000))) {
                $validator->errors()->add('percent', 'Persen harus antara -100 dan 1000.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'source_version_id.required_unless' => 'Pilih versi yang disalin.',
            'percent.required_if' => 'Isi persen kenaikan/penurunan.',
            'percent.regex' => 'Persen berupa angka, mis. 5 atau -2,5.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['mode' => 'cara membuat', 'source_version_id' => 'versi sumber', 'percent' => 'persen', 'effective_from' => 'berlaku mulai', 'note' => 'catatan'];
    }
}
