<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah / ubah lokasi stok (15 §5, FR-A03). Izin `stock_locations.manage` dicek middleware route;
 * lokasi stok di luar scope sudah 404 lewat route binding, TRANSIT 403 lewat Policy.
 *
 * Form hanya untuk gudang & toko (PA-43). Lokasi stok kendaraan hanya bisa diubah `is_sellable`;
 * sisanya mengikuti kendaraannya.
 */
class StockLocationRequest extends FormRequest
{
    /** Awalan kode yang dipakai otomatis oleh sistem (kendaraan canvas, TRANSIT). */
    public const RESERVED_PREFIX = 'KDR-';

    /**
     * Ubah: Policy dicek SEBELUM validasi, sehingga TRANSIT selalu 403 (bukan galat isian) dan
     * pengguna tanpa hak tidak mendapat petunjuk isian. Tambah: middleware `can:stock_locations.manage`.
     */
    public function authorize(): bool
    {
        $current = $this->current();

        return $current === null || ($this->user()?->can('update', $current) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code')) ? strtoupper(trim($this->input('code'))) : $this->input('code'),
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'location_id' => is_numeric($this->input('location_id')) ? (int) $this->input('location_id') : null,
            'is_sellable' => $this->boolean('is_sellable', true),
            'is_active' => $this->boolean('is_active', true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->current();

        if ($current?->type === StockLocationType::Vehicle) {
            return ['is_sellable' => ['boolean']];
        }

        $currentLocation = $current?->location_id;

        return [
            'code' => [
                'required', 'string', 'regex:/^[A-Z0-9-]{2,20}$/',
                // Unik termasuk lokasi stok terhapus: unique key DB tidak mengenal soft delete.
                Rule::unique('stock_locations', 'code')->ignore($current?->id),
            ],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            // Tipe hanya dipilih saat dibuat; vehicle lewat kendaraan, in_transit lewat seeder.
            'type' => $current === null
                ? ['required', Rule::in(array_map(fn (StockLocationType $t) => $t->value, StockLocationType::manual()))]
                : ['nullable', Rule::in([$current->type->value])],
            'location_id' => [
                'required', 'integer',
                // Lokasi nonaktif/terhapus tidak bisa dipilih, tetapi lokasi terpasang boleh dipertahankan.
                Rule::exists('locations', 'id')->where(fn (Builder $q) => $q
                    ->where(fn (Builder $w) => $w->where('is_active', true)->whereNull('deleted_at')
                        ->when($currentLocation !== null, fn (Builder $o) => $o->orWhere('id', $currentLocation)))),
            ],
            'is_sellable' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->current()?->type === StockLocationType::Vehicle) {
                return;
            }

            $code = $this->input('code');
            if (is_string($code) && (str_starts_with($code, self::RESERVED_PREFIX) || $code === StockLocation::TRANSIT_CODE)) {
                $v->errors()->add('code', 'Kode berawalan KDR- dan kode TRANSIT dipakai otomatis oleh sistem. Pilih kode lain.');
            }

            // Pengguna tidak boleh membuat lokasi stok yang lalu tidak bisa ia lihat (SEC-033).
            $location = $this->input('location_id');
            $actor = $this->user();
            if (is_int($location) && $actor instanceof User && ! app(DataScope::class)->allowsStockLocation($actor, $location)) {
                $v->errors()->add('location_id', 'Lokasi tidak ditemukan atau sudah nonaktif.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function stockLocationData(): array
    {
        return $this->safe()->only(['code', 'name', 'type', 'location_id', 'is_sellable', 'is_active']);
    }

    private function current(): ?StockLocation
    {
        $location = $this->route('stockLocation');

        return $location instanceof StockLocation ? $location : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya huruf kapital, angka, dan tanda hubung (-), 2–20 karakter. Contoh: GD-UNDAAN.',
            'code.unique' => 'Kode lokasi stok ini sudah dipakai (termasuk yang sudah dihapus).',
            'type.in' => $this->current() !== null
                ? 'Tipe lokasi stok tidak bisa diubah setelah dibuat.'
                : 'Pilih gudang atau toko. Lokasi stok kendaraan dibuat dari halaman Kendaraan.',
            'location_id.required' => 'Pilih lokasi tempat gudang/toko ini berada.',
            'location_id.exists' => 'Lokasi tidak ditemukan atau sudah nonaktif.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode', 'name' => 'nama', 'type' => 'tipe', 'location_id' => 'lokasi',
            'is_sellable' => 'bisa dijual', 'is_active' => 'status aktif',
        ];
    }
}
