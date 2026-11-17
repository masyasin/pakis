<?php

namespace App\Http\Requests\Customer;

use App\Domain\Access\DataScope;
use App\Domain\Customer\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Ajukan gabung kios (15 §5 `admin.customers.merge`, BR-166(5)). Kios di URL = sumber; kios tujuan
 * wajib dalam scope pengaju. Tujuan di luar scope dijawab sama dengan "tidak ada" (SEC-033), agar
 * id kios lain tidak bisa ditebak lewat pesan galat.
 */
class MergeCustomerRequest extends FormRequest
{
    private const NOT_FOUND = 'Kios tujuan tidak ditemukan.';

    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer && ($this->user()?->can('update', $customer) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'target_customer_id' => is_numeric($this->input('target_customer_id')) ? (int) $this->input('target_customer_id') : $this->input('target_customer_id'),
            'reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'target_customer_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $user = $this->user();
            $id = $this->input('target_customer_id');
            if ($v->errors()->has('target_customer_id') || ! $user instanceof User || ! is_int($id)) {
                return;
            }
            if (! Customer::query()->whereKey($id)->exists() || ! app(DataScope::class)->allowsCustomer($user, $id)) {
                $v->errors()->add('target_customer_id', self::NOT_FOUND);
            }
        });
    }

    public function target(): Customer
    {
        return Customer::query()->findOrFail((int) $this->validated()['target_customer_id']);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'target_customer_id.required' => 'Pilih kios tujuan (kios yang dipertahankan).',
            'reason.min' => 'Alasan minimal 5 karakter.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['target_customer_id' => 'kios tujuan', 'reason' => 'alasan'];
    }
}
