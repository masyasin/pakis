<?php

namespace App\Http\Requests\Purchasing;

use App\Domain\Purchasing\Models\Principal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Tambah/ubah prinsipal (FR-A11). Kode huruf besar, unik termasuk yang terhapus. */
class PrincipalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:suppliers.manage`
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code')) ? strtoupper(trim($this->input('code'))) : $this->input('code'),
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $current = $this->route('principal');

        return [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9-]{2,20}$/',
                Rule::unique('principals', 'code')->ignore($current instanceof Principal ? $current->id : null)],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            // Telepon kantor atau HP: format tampilan bebas (angka, spasi, +, -, kurung).
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+() .\-]{6,20}$/'],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'address' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'string', 'max:150', 'regex:/^(https?:\/\/)?[A-Za-z0-9.\-]+\.[A-Za-z]{2,}(\/\S*)?$/'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya huruf kapital, angka, dan tanda hubung (-), 2–20 karakter. Contoh: AGRO-DEMO.',
            'phone.regex' => 'Telepon hanya angka, spasi, +, -, dan kurung (6–20 karakter).',
            'website.regex' => 'Alamat situs tidak sah, mis. www.contoh.co.id.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['code' => 'kode', 'name' => 'nama', 'contact_name' => 'nama kontak', 'phone' => 'telepon', 'address' => 'alamat', 'website' => 'situs'];
    }
}
