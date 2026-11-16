<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\Enums\BinZone;
use App\Domain\Inventory\Models\StockBin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah / ubah rak (15 §5 `admin.stock-bins.*`, FR-A04). Lokasi stok (tambah) diselesaikan
 * controller lewat query ber-scope (di luar scope → 404); di sini bentuk isian & kunci Q-01.
 */
class StockBinRequest extends FormRequest
{
    /** Ubah: Policy sebelum validasi (403 tanpa petunjuk isian). Tambah: Policy di controller setelah lokasi diselesaikan. */
    public function authorize(): bool
    {
        $bin = $this->current();

        return $bin === null || ($this->user()?->can('update', $bin) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        $this->merge([
            'code' => is_string($this->input('code')) ? strtoupper(trim($this->input('code'))) : $this->input('code'),
            'name' => is_string($name) && trim($name) !== '' ? trim($name) : null,
            'is_active' => $this->boolean('is_active', true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $bin = $this->current();
        $stockLocationId = $bin !== null ? $bin->stock_location_id : $this->input('stock_location_id');

        return [
            'stock_location_id' => $bin === null ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'code' => [
                'required', 'string', 'regex:/^[A-Z0-9-]{1,20}$/',
                Rule::unique('stock_bins', 'code')
                    ->where('stock_location_id', is_numeric($stockLocationId) ? (int) $stockLocationId : 0)
                    ->ignore($bin?->id),
            ],
            'name' => ['nullable', 'string', 'max:100'],
            'zone' => ['required', Rule::enum(BinZone::class)],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $bin = $this->current();
            if ($bin === null || ! $bin->isDefaultQuarantine()) {
                return;
            }

            // Q-01 = tujuan karantina otomatis (15 §4.3, T-078/T-084): selalu ada, aktif, zona karantina.
            if ($this->input('code') !== StockBin::QUARANTINE_CODE) {
                $v->errors()->add('code', 'Kode rak karantina bawaan Q-01 tidak bisa diubah.');
            }
            if ($this->input('zone') !== BinZone::Quarantine->value) {
                $v->errors()->add('zone', 'Zona rak karantina bawaan Q-01 tidak bisa diubah.');
            }
            if (! $this->boolean('is_active')) {
                $v->errors()->add('is_active', 'Rak karantina bawaan Q-01 tidak bisa dinonaktifkan.');
            }
        });
    }

    private function current(): ?StockBin
    {
        $bin = $this->route('bin');

        return $bin instanceof StockBin ? $bin : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode rak hanya huruf kapital, angka, dan tanda hubung (-), maksimal 20 karakter. Contoh: R2-04.',
            'code.unique' => 'Kode rak ini sudah dipakai di lokasi stok yang sama.',
            'stock_location_id.prohibited' => 'Rak tidak bisa dipindah ke lokasi stok lain.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['stock_location_id' => 'lokasi stok', 'code' => 'kode', 'name' => 'nama', 'zone' => 'zona', 'is_active' => 'status aktif'];
    }
}
