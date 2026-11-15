<?php

namespace App\Http\Requests\Organization;

use App\Domain\Organization\Enums\AttendanceMode;
use App\Domain\Organization\Models\Position;
use App\Domain\Organization\Support\PositionHierarchy;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah / ubah jabatan (FR-024, BR-042). Izin `positions.manage` dicek middleware route. Cek siklus
 * di sini untuk pesan yang ramah; UpdatePosition mengecek ulang di bawah kunci.
 */
class PositionRequest extends FormRequest
{
    /** Level 1 = tertinggi (04 §2.8); batas atas keputusan teknis T-023 (12). */
    public const LEVEL_MIN = 1;

    public const LEVEL_MAX = 10;

    public function authorize(): bool
    {
        return true; // middleware `can:positions.manage`
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code')) ? strtoupper(trim($this->input('code'))) : $this->input('code'),
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'parent_position_id' => filled($this->input('parent_position_id')) ? $this->input('parent_position_id') : null,
            'is_active' => $this->boolean('is_active', true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $position = $this->route('position');
        $self = $position instanceof Position ? $position : null;

        // Departemen/atasan harus aktif & tidak terhapus, kecuali nilai yang sudah terpasang dipertahankan.
        $activeOrCurrent = fn (?int $current) => fn (Builder $q) => $q
            ->whereNull('deleted_at')
            ->where(fn (Builder $w) => $w->where('is_active', true)->when($current !== null, fn (Builder $o) => $o->orWhere('id', $current)));

        return [
            'code' => [
                'required', 'string', 'regex:/^[A-Z0-9-]{2,20}$/',
                Rule::unique('positions', 'code')->ignore($self?->id),
            ],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where($activeOrCurrent($self?->department_id))],
            'parent_position_id' => [
                'nullable', 'integer',
                Rule::notIn($self ? [$self->id] : []),
                Rule::exists('positions', 'id')->where($activeOrCurrent($self?->parent_position_id)),
            ],
            'level' => ['required', 'integer', 'between:'.self::LEVEL_MIN.','.self::LEVEL_MAX],
            'attendance_mode' => ['required', Rule::enum(AttendanceMode::class)],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $self = $this->route('position');
            $parentId = $this->input('parent_position_id');

            if ($self instanceof Position && is_numeric($parentId) && ! $v->errors()->has('parent_position_id')
                && (int) $parentId !== $self->parent_position_id && PositionHierarchy::wouldCycle($self->id, (int) $parentId)) {
                $v->errors()->add('parent_position_id', 'Jabatan atasan ini berada di bawah jabatan yang sedang diubah, sehingga membentuk lingkaran. Pilih atasan lain.');
            }
        });
    }

    /**
     * @return array<string, mixed> kunci = Position::AUDITED
     */
    public function positionData(): array
    {
        $data = $this->validated();
        $data['department_id'] = (int) $data['department_id'];
        $data['parent_position_id'] = isset($data['parent_position_id']) ? (int) $data['parent_position_id'] : null;
        $data['level'] = (int) $data['level'];
        $data['attendance_mode'] = AttendanceMode::from((string) $data['attendance_mode']);

        return array_intersect_key($data, array_flip(Position::AUDITED));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya huruf kapital, angka, dan tanda hubung (-), 2–20 karakter. Contoh: KA-GDG.',
            'code.unique' => 'Kode jabatan ini sudah dipakai (termasuk jabatan yang sudah dihapus).',
            'department_id.exists' => 'Pilih departemen yang aktif.',
            'parent_position_id.not_in' => 'Jabatan tidak bisa menjadi atasan dirinya sendiri.',
            'parent_position_id.exists' => 'Pilih jabatan atasan yang aktif.',
            'level.between' => 'Level 1 (tertinggi) sampai 10.',
            'attendance_mode.enum' => 'Pilih mode absen yang tersedia.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode', 'name' => 'nama', 'department_id' => 'departemen', 'parent_position_id' => 'jabatan atasan',
            'level' => 'level', 'attendance_mode' => 'mode absen', 'is_active' => 'status aktif',
        ];
    }
}
