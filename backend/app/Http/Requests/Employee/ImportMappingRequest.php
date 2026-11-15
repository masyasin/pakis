<?php

namespace App\Http\Requests\Employee;

use App\Domain\Employee\Import\EmployeeImportFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Simpan pemetaan kolom (BR-054). Aturan isi (field wajib, tanpa ganda) ada di SaveImportMapping.
 */
class ImportMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:employees.import` + binding batch yang terlihat
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'columns' => ['present', 'array', 'max:200'],
            'columns.*' => ['nullable', 'string', Rule::in(EmployeeImportFields::keys())],
            'save_profile' => ['boolean'],
            'profile_name' => ['exclude_unless:save_profile,true', 'required', 'string', 'min:2', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'columns.*.in' => 'Field tujuan tidak dikenal.',
            'profile_name.required' => 'Isi nama profil, mis. "Format HRD 2026".',
        ];
    }

    /** @return array<int, string|null> */
    public function columns(): array
    {
        $columns = [];
        foreach ((array) $this->validated('columns') as $index => $field) {
            $columns[(int) $index] = is_string($field) && $field !== '' ? $field : null;
        }

        return $columns;
    }

    public function profileName(): ?string
    {
        return $this->boolean('save_profile') ? trim((string) $this->validated('profile_name')) : null;
    }
}
