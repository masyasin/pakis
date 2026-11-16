<?php

namespace App\Http\Requests\Customer;

use App\Domain\Access\DataScope;
use App\Domain\Customer\Models\SalesArea;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah / ubah area penjualan (15 §5 `admin.sales-areas.store/update`, FR-A12). Izin
 * `sales_areas.manage` dicek middleware route. Siklus & kedalaman induk dicek SaveSalesArea di
 * dalam kunci tabel (bukan di sini, agar dua perubahan bersamaan tidak lolos).
 */
class SalesAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $current = $this->current();

        return $current === null || ($this->user()?->can('update', $current) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $districts = $this->input('district_codes');

        $this->merge([
            'code' => is_string($this->input('code')) ? strtoupper(trim($this->input('code'))) : $this->input('code'),
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'parent_id' => is_numeric($this->input('parent_id')) ? (int) $this->input('parent_id') : null,
            'supervisor_employee_id' => is_numeric($this->input('supervisor_employee_id')) ? (int) $this->input('supervisor_employee_id') : null,
            'home_stock_location_id' => is_numeric($this->input('home_stock_location_id')) ? (int) $this->input('home_stock_location_id') : null,
            'district_codes' => is_array($districts) ? array_values(array_unique(array_filter($districts, 'is_string'))) : [],
            'is_active' => $this->boolean('is_active', true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->current();
        $keep = fn (?int $id) => fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('is_active', true)->whereNull('deleted_at')
            ->when($id !== null, fn (Builder $o) => $o->orWhere('id', $id)));

        return [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9-]{2,20}$/', Rule::unique('sales_areas', 'code')->ignore($current?->id)],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            // Induk nonaktif tidak bisa dipilih, tetapi induk terpasang boleh dipertahankan.
            'parent_id' => ['nullable', 'integer', Rule::exists('sales_areas', 'id')->where($keep($current?->parent_id))],
            'supervisor_employee_id' => [
                'nullable', 'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at')->where(fn (Builder $q) => $q->where('status', 'active')
                    ->when($current?->supervisor_employee_id !== null, fn (Builder $o) => $o->orWhere('id', $current?->supervisor_employee_id))),
            ],
            // Gudang asal: gudang/toko saja (bukan kendaraan/TRANSIT).
            'home_stock_location_id' => [
                'nullable', 'integer',
                Rule::exists('stock_locations', 'id')->whereIn('type', ['warehouse', 'store'])->where($keep($current?->home_stock_location_id)),
            ],
            'district_codes' => ['array', 'max:'.SalesArea::MAX_DISTRICTS],
            'district_codes.*' => ['string', 'regex:/^\d{2}\.\d{2}\.\d{2}$/', Rule::exists('districts', 'code')],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $actor = $this->user();
            if (! $actor instanceof User) {
                return;
            }
            $scope = app(DataScope::class);
            $current = $this->current();

            $supervisor = $this->input('supervisor_employee_id');
            if (is_int($supervisor) && $supervisor !== $current?->supervisor_employee_id && ! $scope->allows($actor, $supervisor)) {
                $v->errors()->add('supervisor_employee_id', 'Pegawai tidak ditemukan atau sudah nonaktif.');
            }

            $home = $this->input('home_stock_location_id');
            if (is_int($home) && $home !== $current?->home_stock_location_id
                && ! $scope->forStock(DB::table('stock_locations')->where('id', $home), $actor, 'stock_locations.id')->exists()) {
                $v->errors()->add('home_stock_location_id', 'Gudang asal tidak ditemukan atau sudah nonaktif.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function salesAreaData(): array
    {
        return $this->safe()->only(['code', 'name', 'parent_id', 'supervisor_employee_id', 'home_stock_location_id', 'district_codes', 'is_active']);
    }

    private function current(): ?SalesArea
    {
        $area = $this->route('salesArea');

        return $area instanceof SalesArea ? $area : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya huruf kapital, angka, dan tanda hubung (-), 2–20 karakter. Contoh: AR-KUDUS.',
            'code.unique' => 'Kode area ini sudah dipakai (termasuk yang sudah dihapus).',
            'parent_id.exists' => 'Area induk tidak ditemukan atau sudah nonaktif.',
            'supervisor_employee_id.exists' => 'Pegawai tidak ditemukan atau sudah nonaktif.',
            'home_stock_location_id.exists' => 'Gudang asal harus gudang atau toko yang aktif.',
            'district_codes.max' => 'Maksimal '.SalesArea::MAX_DISTRICTS.' kecamatan per area.',
            'district_codes.*.regex' => 'Kode kecamatan tidak dikenal.',
            'district_codes.*.exists' => 'Kecamatan tidak ditemukan.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode',
            'name' => 'nama',
            'parent_id' => 'induk',
            'supervisor_employee_id' => 'supervisor',
            'home_stock_location_id' => 'gudang asal',
            'district_codes' => 'kecamatan',
        ];
    }
}
