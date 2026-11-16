<?php

namespace App\Http\Requests\Access;

use App\Domain\Access\DataScope;
use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Support\ScopeResolver;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Phone\PhoneNormalizer;
use Illuminate\Database\Query\Builder;
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
            // Disimpan `62…` lewat normalizer bersama (BR-166(1), addendum T-020 di T-073): HP akun
            // dibandingkan dengan HP kios (T-074), jadi formatnya harus identik dengan Employee/Customer.
            'phone' => PhoneNormalizer::forInput($phone),
            'roles' => is_array($this->input('roles')) ? array_values(array_unique(array_filter($this->input('roles'), 'is_string'))) : [],
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
        ]);

        // Tautan pegawai hanya diubah bila field-nya DIKIRIM: permintaan tanpa `employee_id` tidak
        // boleh diam-diam melepas tautan (akun pegawai nonaktif bisa login lagi, BR-010).
        if ($this->has('employee_id')) {
            $this->merge(['employee_id' => is_numeric($this->input('employee_id')) ? (int) $this->input('employee_id') : null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->route('user');
        $ignore = $target instanceof User ? $target->id : null;
        $creating = $ignore === null;
        $currentEmployee = $target instanceof User ? $target->employee_id : null;

        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'username' => ['required', 'string', 'regex:/^[a-z0-9._-]{3,50}$/', Rule::unique(User::class)->ignore($ignore)->withoutTrashed()],
            'email' => ['nullable', 'string', 'email', 'max:150', Rule::unique(User::class)->ignore($ignore)->withoutTrashed()],
            // HP lama tak sah (dibiarkan migrasi normalisasi) tetap diterima selama tidak diubah, agar
            // mengubah role tidak terhalang data HP lama.
            'phone' => ['nullable', 'string', Rule::when($this->input('phone') !== ($target instanceof User ? $target->phone : null), ['regex:'.PhoneNormalizer::STORED_PATTERN])],
            'roles' => ['array', 'max:20'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            // Pegawai terkait (T-024): pegawai aktif yang belum punya akun lain. Tautan yang sudah ada
            // boleh dipertahankan walau pegawainya kini nonaktif. Unik termasuk akun terhapus
            // (index `users_employee_id_unique` tidak mengenal soft delete).
            'employee_id' => [
                'nullable', 'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at')->where(fn (Builder $q) => $q
                    ->where('status', 'active')->when($currentEmployee !== null, fn (Builder $o) => $o->orWhere('id', $currentEmployee))),
                Rule::unique(User::class, 'employee_id')->ignore($ignore),
            ],
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
            'phone.regex' => PhoneNormalizer::INVALID_MESSAGE,
            'roles.*.exists' => 'Role yang dipilih tidak ditemukan.',
            'employee_id.exists' => 'Pegawai tidak ditemukan atau sudah nonaktif.',
            'employee_id.unique' => 'Pegawai ini sudah punya akun lain.',
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

                $this->checkEmployeeLink($validator);
            },
        ];
    }

    /**
     * Penautan pegawai (T-024). Pegawai menentukan data yang terlihat akun (scope `location`/`team`
     * dihitung dari lokasi & bawahan pegawainya), jadi mengubah tautan sama dengan mengubah hak akses:
     * - hanya pelaku ber-scope `all` (role bawaan yang punya `users.create/update` memang `all`);
     *   pelaku ber-scope sempit bisa menautkan pegawai yang mengepalai lokasi di luar scope-nya;
     * - tautan ke pegawai nonaktif/terhapus tidak bisa diubah: akun pegawai yang sudah keluar
     *   tertolak saat login (BR-010) dan tidak boleh "dibebaskan" dengan melepas tautannya;
     * - pegawai di luar scope pelaku diperlakukan "tidak ditemukan" (SEC-033).
     */
    private function checkEmployeeLink(Validator $validator): void
    {
        if (! $this->has('employee_id') || $validator->errors()->has('employee_id')) {
            return;
        }

        $actor = $this->user();
        $target = $this->route('user');
        $current = $target instanceof User ? $target->employee_id : null;
        $new = $this->input('employee_id');

        if (! $actor instanceof User || $new === $current) {
            return;
        }

        if (ScopeResolver::for($actor) !== Scope::All) {
            $validator->errors()->add('employee_id', 'Hanya pengguna dengan cakupan data "Semua" yang bisa menautkan atau melepas pegawai.');

            return;
        }

        if ($current !== null && ! Employee::query()->whereKey($current)->where('status', 'active')->exists()) {
            $validator->errors()->add('employee_id', 'Pegawai terkait akun ini sudah nonaktif atau dihapus, jadi tautannya tidak bisa diubah. Akun mengikuti status pegawainya.');

            return;
        }

        if (is_int($new) && ! app(DataScope::class)->allows($actor, $new)) {
            $validator->errors()->add('employee_id', 'Pegawai tidak ditemukan atau sudah nonaktif.');
        }
    }

    /**
     * `employee_id` hanya ikut bila dikirim (lihat prepareForValidation).
     *
     * @return array{name: string, username: string, email: string|null, phone: string|null, roles: list<string>, employee_id?: int|null}
     */
    public function accountData(): array
    {
        $link = $this->has('employee_id') ? ['employee_id' => $this->validated('employee_id')] : [];

        /** @var array{name: string, username: string, email: string|null, phone: string|null, roles: list<string>, employee_id?: int|null} */
        return [
            ...$link,
            'name' => (string) $this->validated('name'),
            'username' => (string) $this->validated('username'),
            'email' => $this->validated('email'),
            'phone' => $this->validated('phone'),
            'roles' => array_values($this->validated('roles', [])),
        ];
    }
}
