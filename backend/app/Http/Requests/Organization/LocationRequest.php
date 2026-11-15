<?php

namespace App\Http\Requests\Organization;

use App\Domain\Access\DataScope;
use App\Domain\Organization\Models\Location;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah / ubah lokasi (FR-022, BR-041). Izin `locations.manage` dicek middleware route; lokasi
 * di luar scope sudah 404 lewat route binding.
 */
class LocationRequest extends FormRequest
{
    /** Batas wilayah Indonesia (04 §2.8, juga CHECK di DB). */
    public const LAT_MIN = -11;

    public const LAT_MAX = 6;

    public const LNG_MIN = 94;

    public const LNG_MAX = 142;

    public function authorize(): bool
    {
        return true; // middleware `can:locations.manage`
    }

    protected function prepareForValidation(): void
    {
        $text = fn (string $key) => is_string($this->input($key)) && trim($this->input($key)) !== '' ? trim($this->input($key)) : null;

        $this->merge([
            'code' => is_string($this->input('code')) ? strtoupper(trim($this->input('code'))) : $this->input('code'),
            'name' => $text('name'),
            'address' => $text('address'),
            'village_code' => $text('village_code'),
            'phone' => is_string($this->input('phone')) && trim($this->input('phone')) !== '' ? (string) preg_replace('/[\s-]/', '', $this->input('phone')) : null,
            'latitude' => $text('latitude'),
            'longitude' => $text('longitude'),
            'attendance_radius_m' => $text('attendance_radius_m'),
            'is_active' => $this->boolean('is_active', true),
            'head_employee_id' => is_numeric($this->input('head_employee_id')) ? (int) $this->input('head_employee_id') : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $location = $this->route('location');
        $currentType = $location instanceof Location ? $location->location_type_id : null;
        $currentHead = $location instanceof Location ? $location->head_employee_id : null;

        return [
            'code' => [
                'required', 'string', 'regex:/^[A-Z0-9-]{2,20}$/',
                // Unik termasuk lokasi terhapus: unique key DB tidak mengenal soft delete.
                Rule::unique('locations', 'code')->ignore($location instanceof Location ? $location->id : null),
            ],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'location_type_id' => [
                'required', 'integer',
                // Tipe nonaktif tidak bisa dipilih untuk lokasi baru, tetapi tipe yang sudah terpasang boleh dipertahankan.
                Rule::exists('reference_items', 'id')->where(fn (Builder $q) => $q
                    ->where('group_code', ReferenceGroup::LocationType->value)
                    ->where(fn (Builder $w) => $w->where('is_active', true)->when($currentType !== null, fn (Builder $o) => $o->orWhere('id', $currentType)))),
            ],
            'address' => ['nullable', 'string', 'max:500'],
            'village_code' => ['nullable', 'string', 'regex:/^\d{2}\.\d{2}\.\d{2}\.\d{4}$/', Rule::exists('villages', 'code')],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:'.self::LAT_MIN.','.self::LAT_MAX, 'decimal:0,7'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:'.self::LNG_MIN.','.self::LNG_MAX, 'decimal:0,7'],
            'attendance_radius_m' => ['nullable', 'integer', 'between:20,5000'],
            'phone' => ['nullable', 'string', 'regex:/^(\+62|62|0)\d{7,13}$/'],
            // Kepala lokasi (T-024): pegawai aktif; kepala yang sudah terpasang boleh dipertahankan.
            'head_employee_id' => [
                'nullable', 'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at')->where(fn (Builder $q) => $q
                    ->where('status', 'active')->when($currentHead !== null, fn (Builder $o) => $o->orWhere('id', $currentHead))),
            ],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Koordinat yang lolos rentang tapi tepat 0,0 hampir pasti salah ketik / belum diisi.
        $validator->after(function (Validator $v) {
            if ((string) $this->input('latitude') === '0' && (string) $this->input('longitude') === '0') {
                $v->errors()->add('latitude', 'Koordinat 0,0 bukan lokasi di Indonesia.');
            }

            // Pegawai di luar scope pelaku diperlakukan "tidak ditemukan" (SEC-033).
            $head = $this->input('head_employee_id');
            $actor = $this->user();
            if (is_int($head) && $actor instanceof User && ! app(DataScope::class)->allows($actor, $head)) {
                $v->errors()->add('head_employee_id', 'Pegawai tidak ditemukan atau sudah nonaktif.');
            }
        });
    }

    /**
     * @return array<string, mixed> kunci = Location::AUDITED
     */
    public function locationData(): array
    {
        $data = $this->validated();
        $data['attendance_radius_m'] = isset($data['attendance_radius_m']) ? (int) $data['attendance_radius_m'] : null;
        $data['location_type_id'] = (int) $data['location_type_id'];
        $data['head_employee_id'] = $data['head_employee_id'] ?? null;

        return array_intersect_key($data, array_flip(Location::AUDITED));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya huruf kapital, angka, dan tanda hubung (-), 2–20 karakter. Contoh: GD-UNDAAN.',
            'code.unique' => 'Kode lokasi ini sudah dipakai (termasuk lokasi yang sudah dihapus).',
            'location_type_id.exists' => 'Pilih tipe lokasi yang aktif.',
            'village_code.regex' => 'Pilih desa/kelurahan dari daftar.',
            'village_code.exists' => 'Desa/kelurahan tidak ditemukan.',
            'latitude.between' => 'Latitude di luar wilayah Indonesia (−11 sampai 6).',
            'longitude.between' => 'Longitude di luar wilayah Indonesia (94 sampai 142).',
            'latitude.required_with' => 'Isi latitude dan longitude bersamaan.',
            'longitude.required_with' => 'Isi latitude dan longitude bersamaan.',
            'attendance_radius_m.between' => 'Radius presensi 20–5.000 meter. Kosongkan untuk memakai radius bawaan.',
            'phone.regex' => 'Nomor telepon tidak valid. Contoh: 0291 123456 atau 0812-3456-7890.',
            'head_employee_id.exists' => 'Pegawai tidak ditemukan atau sudah nonaktif.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode', 'name' => 'nama', 'location_type_id' => 'tipe lokasi', 'address' => 'alamat',
            'village_code' => 'desa/kelurahan', 'latitude' => 'latitude', 'longitude' => 'longitude',
            'attendance_radius_m' => 'radius presensi', 'phone' => 'telepon', 'head_employee_id' => 'kepala lokasi', 'is_active' => 'status aktif',
        ];
    }
}
