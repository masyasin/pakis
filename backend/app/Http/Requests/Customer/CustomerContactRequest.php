<?php

namespace App\Http\Requests\Customer;

use App\Domain\Customer\Models\Customer;
use App\Support\Phone\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Tambah/ubah kontak kios (15 §5 `admin.customers.contacts.*`, T-074). HP opsional, dinormalisasi
 * `62…` seperti HP pemilik agar perbandingan konsisten.
 */
class CustomerContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer && ($this->user()?->can('manageContacts', $customer) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $trim = fn (string $key) => is_string($this->input($key)) ? (trim($this->input($key)) === '' ? null : trim($this->input($key))) : $this->input($key);
        $this->merge([
            'name' => $trim('name'),
            'role' => $trim('role'),
            'phone' => PhoneNormalizer::forInput($this->input('phone')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'role' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'regex:'.PhoneNormalizer::STORED_PATTERN],
        ];
    }

    /** @return array{name: string, role: string|null, phone: string|null} */
    public function contactData(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return [
            'name' => (string) $data['name'],
            'role' => isset($data['role']) ? (string) $data['role'] : null,
            'phone' => isset($data['phone']) ? (string) $data['phone'] : null,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.regex' => PhoneNormalizer::INVALID_MESSAGE];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nama kontak', 'role' => 'peran', 'phone' => 'HP kontak'];
    }
}
