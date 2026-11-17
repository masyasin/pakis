<?php

namespace App\Http\Requests\Customer;

use App\Domain\Customer\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ajukan limit kredit (15 §5 `admin.customers.credit-limit`, BR-125). Hanya bentuk isian; kelayakan
 * kios (terverifikasi, status) diperiksa Action di bawah kunci (`CUSTOMER_NOT_VERIFIED`).
 */
class CreditLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer && ($this->user()?->can('update', $customer) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $limit = $this->input('credit_limit');
        $this->merge([
            'credit_limit' => is_string($limit) && trim($limit) !== '' ? str_replace([' ', ','], ['', '.'], trim($limit)) : (is_int($limit) ? (string) $limit : $limit),
            'reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'credit_limit' => ['required', 'string', 'regex:'.SubmitVerificationRequest::MONEY],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'credit_limit.required' => 'Isi limit kredit baru (0 = tanpa limit).',
            'credit_limit.regex' => 'Limit kredit harus angka rupiah, mis. 5000000.',
            'reason.min' => 'Alasan minimal 5 karakter.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['credit_limit' => 'limit kredit', 'reason' => 'alasan'];
    }
}
