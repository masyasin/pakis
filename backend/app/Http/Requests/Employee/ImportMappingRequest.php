<?php

namespace App\Http\Requests\Employee;

use App\Support\Import\ImportBatch;
use App\Support\Import\ImportRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Simpan pemetaan kolom (BR-054, BR-170). Field tujuan = field tipe impor batch; aturan isi (field
 * wajib, tanpa ganda) ada di SaveImportMapping.
 */
class ImportMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:manage,batch` (ImportBatchPolicy) + binding batch yang terlihat
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'columns' => ['present', 'array', 'max:200'],
            'columns.*' => ['nullable', 'string', Rule::in($this->fieldKeys())],
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

    /** @return list<string> */
    private function fieldKeys(): array
    {
        $batch = $this->route('batch');

        return $batch instanceof ImportBatch ? ImportRegistry::forBatch($batch)->fields()->keys() : [];
    }

    public function profileName(): ?string
    {
        return $this->boolean('save_profile') ? trim((string) $this->validated('profile_name')) : null;
    }
}
