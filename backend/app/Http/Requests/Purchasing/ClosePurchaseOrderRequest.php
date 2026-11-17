<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

/** Tutup PO (BR-160): alasan wajib. */
class ClosePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:purchase_orders.create`; status dicek Policy & Action
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
