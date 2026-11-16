<?php

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

/** Ajukan/terbitkan versi draf (BR-168). Alasan hanya dipakai bila versi berlaku surut. */
class SubmitPriceVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:prices.manage`
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['backdate_reason' => ['nullable', 'string', 'max:200']];
    }
}
