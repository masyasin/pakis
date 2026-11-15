<?php

namespace App\Http\Requests\Access;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Data akun dari form Pengguna (07 §4, 04 §2.2). Izin `users.create`/`users.update` dicek
 * middleware route, wewenang atas akun target & batas delegasi role di controller/Action.
 */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:users.*` + UserPolicy di controller
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $phone = $this->input('phone');
        $username = $this->input('username');

        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            // Username disimpan huruf kecil (04 §2.2); spasi di tepi bukan bagian username.
            'username' => is_string($username) ? mb_strtolower(trim($username)) : $username,
            'email' => is_string($email) && trim($email) !== '' ? mb_strtolower(trim($email)) : null,
            // Spasi, titik, tanda hubung, kurung hanya pemisah tampilan nomor HP (sama dengan Profil).
            'phone' => is_string($phone) && trim($phone) !== '' ? (string) preg_replace('/[\s.\-()]/', '', $phone) : null,
            'roles' => is_array($this->input('roles')) ? array_values(array_unique(array_filter($this->input('roles'), 'is_string'))) : [],
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->route('user');
        $ignore = $target instanceof User ? $target->id : null;
        $creating = $ignore === null;

        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'username' => ['required', 'string', 'regex:/^[a-z0-9._-]{3,50}$/', Rule::unique(User::class)->ignore($ignore)->withoutTrashed()],
            'email' => ['nullable', 'string', 'email', 'max:150', Rule::unique(User::class)->ignore($ignore)->withoutTrashed()],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{8,20}$/'],
            'roles' => ['array', 'max:20'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            // TODO(T-024): tautkan pegawai (lookup `employees`, hanya yang belum punya akun).
            'employee_id' => ['prohibited'],
            ...$creating ? [
                'is_active' => ['boolean'],
                'password_mode' => ['required', Rule::in(['generate', 'email_link'])],
            ] : [],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => 'Username 3–50 karakter: huruf kecil, angka, titik, garis bawah, atau tanda hubung.',
            'username.unique' => 'Username ini sudah dipakai akun lain.',
            'email.unique' => 'Email ini sudah dipakai akun lain.',
            'phone.regex' => 'Nomor HP hanya angka (boleh diawali +), 8–20 digit.',
            'roles.*.exists' => 'Role yang dipilih tidak ditemukan.',
            'employee_id.prohibited' => 'Penautan pegawai tersedia setelah modul Pegawai aktif.',
            'password_mode.in' => 'Pilih cara membuat password awal.',
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function ($validator) {
                if ($this->input('password_mode') === 'email_link' && $this->input('email') === null) {
                    $validator->errors()->add('email', 'Isi email untuk mengirim tautan atur password.');
                }
            },
        ];
    }

    /**
     * @return array{name: string, username: string, email: string|null, phone: string|null, roles: list<string>}
     */
    public function accountData(): array
    {
        /** @var array{name: string, username: string, email: string|null, phone: string|null, roles: list<string>} */
        return [
            'name' => (string) $this->validated('name'),
            'username' => (string) $this->validated('username'),
            'email' => $this->validated('email'),
            'phone' => $this->validated('phone'),
            'roles' => array_values($this->validated('roles', [])),
        ];
    }
}
