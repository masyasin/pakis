<?php

namespace App\Http\Requests\Access;

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Data role (BR-030). Izin `roles.manage` dicek middleware route; aturan yang bergantung pada
 * keadaan (role terkunci, role sendiri, kode role sistem) ada di SaveRole.
 */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:roles.manage`
    }

    protected function prepareForValidation(): void
    {
        $description = $this->input('description');

        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'label' => is_string($this->input('label')) ? trim($this->input('label')) : $this->input('label'),
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            'mfa_required' => $this->boolean('mfa_required'),
            'permissions' => is_array($this->input('permissions')) ? array_values(array_unique($this->input('permissions'))) : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => [
                'required', 'string', 'regex:/^[a-z0-9_]{3,40}$/',
                Rule::unique(config('permission.table_names.roles'), 'name')
                    ->where('guard_name', 'web')
                    ->ignore($role instanceof Role ? $role->id : null),
            ],
            'label' => ['required', 'string', 'min:3', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'data_scope' => ['required', Rule::enum(DataScope::class)],
            'mfa_required' => ['boolean'],
            'permissions' => ['array'],
            // BR-030: hanya permission yang ada di katalog; UI tidak bisa membuat permission baru.
            'permissions.*' => ['string', Rule::in(PermissionCatalog::names())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Kode role 3–40 karakter: huruf kecil, angka, atau garis bawah (contoh: kepala_toko).',
            'name.unique' => 'Kode role ini sudah dipakai.',
            'label.min' => 'Nama role minimal 3 karakter.',
            'label.max' => 'Nama role maksimal 60 karakter.',
            'permissions.*.in' => 'Permission tidak dikenal.',
        ];
    }

    /**
     * @return array{name: string, label: string, description: string|null, data_scope: string, mfa_required: bool, permissions: list<string>}
     */
    public function roleData(): array
    {
        $description = $this->validated('description');

        return [
            'name' => (string) $this->validated('name'),
            'label' => (string) $this->validated('label'),
            'description' => is_string($description) ? $description : null,
            'data_scope' => (string) $this->validated('data_scope'),
            'mfa_required' => (bool) $this->validated('mfa_required'),
            'permissions' => array_values(array_map('strval', (array) $this->validated('permissions', []))),
        ];
    }
}
