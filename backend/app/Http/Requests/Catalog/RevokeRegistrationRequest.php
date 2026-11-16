<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/** Cabut izin edar (BR-152): alasan wajib, tercatat di izin & audit. */
class RevokeRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:product_registrations.manage`
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:255']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reason' => 'alasan'];
    }
}
