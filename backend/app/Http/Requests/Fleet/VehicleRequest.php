<?php

namespace App\Http\Requests\Fleet;

use App\Domain\Access\DataScope;
use App\Domain\Fleet\Enums\VehicleType;
use App\Domain\Fleet\Models\Vehicle;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah / ubah kendaraan (15 §5 `admin.vehicles.*`, FR-A05). Izin `vehicles.manage` dicek
 * middleware route; kendaraan di luar scope sudah 404 lewat route binding.
 */
class VehicleRequest extends FormRequest
{
    /** Ubah: Policy sebelum validasi. Tambah: middleware `can:vehicles.manage`. */
    public function authorize(): bool
    {
        $current = $this->current();

        return $current === null || ($this->user()?->can('update', $current) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'plate_no' => is_string($this->input('plate_no')) ? Vehicle::normalizePlate($this->input('plate_no')) : $this->input('plate_no'),
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'home_location_id' => is_numeric($this->input('home_location_id')) ? (int) $this->input('home_location_id') : null,
            'is_canvas' => $this->boolean('is_canvas'),
            'is_active' => $this->boolean('is_active', true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->current();
        $currentHome = $current?->home_location_id;

        return [
            'plate_no' => [
                'required', 'string', 'regex:/^[A-Z0-9]{2,15}$/',
                // Unik termasuk kendaraan terhapus (unique key DB).
                Rule::unique('vehicles', 'plate_no')->ignore($current?->id),
            ],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'vehicle_type' => ['required', Rule::enum(VehicleType::class)],
            'is_canvas' => ['boolean'],
            // Canvas wajib punya lokasi asal: lokasi stok kendaraan memakai lokasi ini sebagai
            // `location_id` (scope & induk persetujuan, PA-43).
            'home_location_id' => [
                'nullable', 'required_if:is_canvas,true', 'integer',
                Rule::exists('locations', 'id')->where(fn (Builder $q) => $q
                    ->where(fn (Builder $w) => $w->where('is_active', true)->whereNull('deleted_at')
                        ->when($currentHome !== null, fn (Builder $o) => $o->orWhere('id', $currentHome)))),
            ],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $actor = $this->user();
            $home = $this->input('home_location_id');
            if (is_int($home) && $actor instanceof User && ! app(DataScope::class)->allowsStockLocation($actor, $home)) {
                $v->errors()->add('home_location_id', 'Lokasi tidak ditemukan atau sudah nonaktif.');
            }

            // Kode lokasi stok canvas (`KDR-{plat}`) tidak boleh bentrok dengan lokasi stok lain.
            $plate = $this->input('plate_no');
            if ($this->boolean('is_canvas') && is_string($plate) && ! $v->errors()->has('plate_no')) {
                $taken = StockLocation::withTrashed()
                    ->where('code', StockLocation::vehicleCode($plate))
                    ->where(fn ($q) => $q->whereNull('vehicle_id')->when($this->current(), fn ($o, Vehicle $c) => $o->orWhere('vehicle_id', '!=', $c->id)))
                    ->exists();
                if ($taken) {
                    $v->errors()->add('plate_no', 'Kode lokasi stok '.StockLocation::vehicleCode($plate).' sudah dipakai lokasi stok lain.');
                }
            }
        });
    }

    /**
     * @return array<string, mixed> kunci = Vehicle::AUDITED
     */
    public function vehicleData(): array
    {
        $data = $this->safe()->only(Vehicle::AUDITED);
        $data['home_location_id'] ??= null;

        return $data;
    }

    private function current(): ?Vehicle
    {
        $vehicle = $this->route('vehicle');

        return $vehicle instanceof Vehicle ? $vehicle : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plate_no.regex' => 'Plat nomor hanya huruf dan angka (spasi dibuang otomatis), 2–15 karakter. Contoh: K 9021 AB.',
            'plate_no.unique' => 'Plat nomor ini sudah terdaftar (termasuk kendaraan yang sudah dihapus).',
            'home_location_id.required_if' => 'Kendaraan canvas wajib punya lokasi asal.',
            'home_location_id.exists' => 'Lokasi tidak ditemukan atau sudah nonaktif.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'plate_no' => 'plat nomor', 'name' => 'nama', 'vehicle_type' => 'jenis', 'is_canvas' => 'canvas',
            'home_location_id' => 'lokasi asal', 'is_active' => 'status aktif',
        ];
    }
}
