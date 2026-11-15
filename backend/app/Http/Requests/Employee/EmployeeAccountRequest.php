<?php

namespace App\Http\Requests\Employee;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat akun dari pegawai (05 `admin.employees.account`, FR-016). Nama/HP/email diambil dari data
 * pegawai, jadi yang dikirim hanya username, role, dan cara membuat password awal.
 */
class EmployeeAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:users.create` + EmployeePolicy::createAccount di controller
    }

    protected function prepareForValidation(): void
    {
        $username = $this->input('username');

        $this->merge([
            'username' => is_string($username) ? mb_strtolower(trim($username)) : $username,
            'roles' => is_array($this->input('roles')) ? array_values(array_unique(array_filter($this->input('roles'), 'is_string'))) : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Unik termasuk akun terhapus: index unik username tidak mengenal soft delete.
            'username' => ['required', 'string', 'regex:/^[a-z0-9._-]{3,50}$/', Rule::unique(User::class, 'username')],
            'roles' => ['array', 'max:20'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'password_mode' => ['required', Rule::in(['generate', 'email_link'])],
        ];
    }

    /**
     * @return array{username: string, roles: list<string>, password_mode: 'generate'|'email_link'}
     */
    public function accountData(): array
    {
        return [
            'username' => (string) $this->validated('username'),
            'roles' => array_values($this->validated('roles', [])),
            'password_mode' => $this->validated('password_mode') === 'email_link' ? 'email_link' : 'generate',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => 'Isi username akun.',
            'username.regex' => 'Username 3–50 karakter: huruf kecil, angka, titik, garis bawah, atau tanda hubung.',
            'username.unique' => 'Username ini sudah dipakai akun lain.',
            'roles.*.exists' => 'Role yang dipilih tidak ditemukan.',
            'password_mode.in' => 'Pilih cara membuat password awal.',
        ];
    }
}
