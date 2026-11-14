<?php

namespace App\Http\Requests\Reference;

use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tambah / ubah item referensi (BR-026). Izin `references.manage` dicek middleware route.
 * Grup hanya dikirim saat tambah; saat ubah grup diambil dari item (tidak bisa dipindah grup).
 */
class ReferenceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:references.manage`
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code')) ? trim($this->input('code')) : $this->input('code'),
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'description' => is_string($this->input('description')) && trim($this->input('description')) !== ''
                ? trim($this->input('description'))
                : null,
            'is_active' => $this->boolean('is_active', true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $item = $this->route('item');
        $group = $item instanceof ReferenceItem ? $item->group_code : $this->input('group');

        return [
            'group' => $item instanceof ReferenceItem ? ['prohibited'] : ['required', Rule::enum(ReferenceGroup::class)],
            'code' => [
                'required', 'string', 'min:2', 'max:50', 'regex:/^[a-z0-9_]{2,50}$/',
                Rule::unique('reference_items', 'code')
                    ->where('group_code', is_string($group) ? $group : '')
                    ->ignore($item instanceof ReferenceItem ? $item->id : null),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya huruf kecil, angka, dan garis bawah (_), 2–50 karakter. Contoh: gudang_cabang.',
            'code.unique' => 'Kode ini sudah dipakai di grup yang sama.',
            'group.prohibited' => 'Grup item tidak bisa dipindah.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['group' => 'grup', 'code' => 'kode', 'name' => 'nama', 'description' => 'deskripsi', 'is_active' => 'status aktif'];
    }
}
