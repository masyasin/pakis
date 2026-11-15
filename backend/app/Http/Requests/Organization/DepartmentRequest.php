<?php

namespace App\Http\Requests\Organization;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Support\DepartmentHierarchy;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah / ubah departemen (FR-023). Izin `departments.manage` dicek middleware route. Cek siklus
 * di sini untuk pesan yang ramah; UpdateDepartment mengecek ulang di bawah kunci.
 */
class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:departments.manage`
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code')) ? strtoupper(trim($this->input('code'))) : $this->input('code'),
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'parent_id' => filled($this->input('parent_id')) ? $this->input('parent_id') : null,
            'is_active' => $this->boolean('is_active', true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $department = $this->route('department');
        $self = $department instanceof Department ? $department : null;

        return [
            'code' => [
                'required', 'string', 'regex:/^[A-Z0-9-]{2,20}$/',
                // Unik termasuk departemen terhapus: unique key DB tidak mengenal soft delete.
                Rule::unique('departments', 'code')->ignore($self?->id),
            ],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'parent_id' => [
                'nullable', 'integer',
                Rule::notIn($self ? [$self->id] : []),
                // Induk harus aktif & tidak terhapus, kecuali induk yang sudah terpasang dipertahankan.
                Rule::exists('departments', 'id')->where(fn (Builder $q) => $q
                    ->whereNull('deleted_at')
                    ->where(fn (Builder $w) => $w->where('is_active', true)->when($self?->parent_id !== null, fn (Builder $o) => $o->orWhere('id', $self?->parent_id)))),
            ],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $self = $this->route('department');
            $parentId = $this->input('parent_id');

            if ($self instanceof Department && is_numeric($parentId) && ! $v->errors()->has('parent_id')
                && (int) $parentId !== $self->parent_id && DepartmentHierarchy::wouldCycle($self->id, (int) $parentId)) {
                $v->errors()->add('parent_id', 'Induk ini berada di bawah departemen yang sedang diubah, sehingga membentuk lingkaran. Pilih induk lain.');
            }
        });
    }

    /**
     * @return array<string, mixed> kunci = Department::AUDITED
     */
    public function departmentData(): array
    {
        $data = $this->validated();
        $data['parent_id'] = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        return array_intersect_key($data, array_flip(Department::AUDITED));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya huruf kapital, angka, dan tanda hubung (-), 2–20 karakter. Contoh: GUDANG.',
            'code.unique' => 'Kode departemen ini sudah dipakai (termasuk departemen yang sudah dihapus).',
            'parent_id.not_in' => 'Departemen tidak bisa menjadi induk dirinya sendiri.',
            'parent_id.exists' => 'Pilih induk departemen yang aktif.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => 'kode', 'name' => 'nama', 'parent_id' => 'induk', 'is_active' => 'status aktif'];
    }
}
