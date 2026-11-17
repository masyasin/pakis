<?php

namespace App\Http\Requests\Customer;

use App\Domain\Access\DataScope;
use App\Domain\Customer\Models\Customer;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Models\User;
use App\Support\Phone\PhoneNormalizer;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah / ubah kios (15 §5 `admin.customers.store/update`, FR-A13 data inti, T-073). Izin
 * `customers.manage` dicek middleware route; kios di luar scope sudah 404 lewat route binding.
 *
 * - HP pemilik dinormalisasi `62…` oleh normalizer bersama (BR-166(1)). Cek HP ganda, HP pegawai/akun,
 *   dan kandidat duplikat (BR-166(2)/(3), T-074) di Action (DuplicateGuard, di dalam transaksi);
 *   request hanya memvalidasi bentuk konfirmasi `not_duplicate` + `duplicate_reason`.
 * - Area & sales hanya saat tambah (penugasan pertama); setelahnya lewat pengalihan (BR-167).
 * - Kode hanya diketik bila `customer.code_mode = manual`.
 * - Titik kios dari tautan Google Maps / Geolocation browser (LocationPointInput, PA-20): lintang &
 *   bujur selalu berpasangan.
 */
class CustomerRequest extends FormRequest
{
    public const VILLAGE_PATTERN = '/^\d{2}\.\d{2}\.\d{2}\.\d{4}$/';

    public function authorize(): bool
    {
        $current = $this->current();

        return $current === null || ($this->user()?->can('update', $current) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $trim = fn (string $key) => is_string($this->input($key)) ? (trim($this->input($key)) === '' ? null : trim($this->input($key))) : $this->input($key);
        $int = fn (string $key) => is_numeric($this->input($key)) ? (int) $this->input($key) : null;

        $this->merge([
            'code' => is_string($this->input('code')) ? strtoupper(trim($this->input('code'))) : $this->input('code'),
            'store_name' => $trim('store_name'),
            'owner_name' => $trim('owner_name'),
            'owner_phone' => PhoneNormalizer::forInput($this->input('owner_phone')),
            'customer_type_id' => $int('customer_type_id'),
            'address' => $trim('address'),
            'village_code' => $trim('village_code'),
            'latitude' => $trim('latitude'),
            'longitude' => $trim('longitude'),
            'home_stock_location_id' => $int('home_stock_location_id'),
            'price_list_id' => $int('price_list_id'),
            'sales_area_id' => $int('sales_area_id'),
            'assigned_sales_employee_id' => $int('assigned_sales_employee_id'),
            'term_days' => is_numeric($this->input('term_days')) ? (int) $this->input('term_days') : $this->input('term_days'),
            'duplicate_reason' => $trim('duplicate_reason'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->current();
        $creating = $current === null;

        return [
            ...$creating && setting('customer.code_mode') === 'manual' ? [
                // Unik termasuk kios terhapus: unique key DB tidak mengenal soft delete.
                'code' => ['required', 'string', 'regex:/^[A-Z0-9-]{3,20}$/', Rule::unique('customers', 'code')],
            ] : [],
            'store_name' => ['required', 'string', 'min:2', 'max:150'],
            'owner_name' => ['required', 'string', 'min:2', 'max:150'],
            'owner_phone' => ['required', 'string', 'regex:'.PhoneNormalizer::STORED_PATTERN],
            'customer_type_id' => [
                'required', 'integer',
                Rule::exists('reference_items', 'id')->where('group_code', ReferenceGroup::CustomerType->value)
                    ->where(fn (Builder $q) => $q->where('is_active', true)
                        ->when($current !== null, fn (Builder $o) => $o->orWhere('id', $current?->customer_type_id))),
            ],
            'address' => ['nullable', 'string', 'max:1000'],
            'village_code' => ['nullable', 'string', 'regex:'.self::VILLAGE_PATTERN, Rule::exists('villages', 'code')],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'home_stock_location_id' => [
                'nullable', 'integer',
                Rule::exists('stock_locations', 'id')->whereIn('type', ['warehouse', 'store'])
                    ->where(fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('is_active', true)->whereNull('deleted_at')
                        ->when($current?->home_stock_location_id !== null, fn (Builder $o) => $o->orWhere('id', $current?->home_stock_location_id)))),
            ],
            'term_days' => ['required', 'integer', 'min:0', 'max:365'],
            // Daftar harga khusus kios (BR-168 urutan 2): hanya jenis `tier` — daftar harga area & default
            // sudah berlaku otomatis, `store` khusus POS (keputusan Mas 17 Nov 2026, PA-61). Nilai lama
            // yang kini nonaktif tetap sah agar kios bisa disimpan tanpa memaksa ganti.
            'price_list_id' => [
                'nullable', 'integer',
                Rule::exists('price_lists', 'id')->where('kind', 'tier')
                    ->where(fn (Builder $q) => $q->where('is_active', true)
                        ->when($current?->price_list_id !== null, fn (Builder $o) => $o->orWhere('id', $current?->price_list_id))),
            ],
            'not_duplicate' => ['sometimes', 'boolean'],
            'duplicate_reason' => ['nullable', 'string', 'min:5', 'max:255'],
            ...$creating ? [
                'sales_area_id' => ['required', 'integer', Rule::exists('sales_areas', 'id')->where('is_active', true)->whereNull('deleted_at')],
                'assigned_sales_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('status', 'active')->whereNull('deleted_at')],
            ] : [
                // Ketentuan dagang (T-074): hanya saat ubah, opsional (form lama tanpa kolom ini tetap sah).
                'harvest_term_allowed' => ['sometimes', 'boolean'],
                'harvest_term_max_days' => ['nullable', 'integer', 'min:1', 'max:365', Rule::requiredIf(fn () => $this->boolean('harvest_term_allowed'))],
                'restricted_buyer_eligible' => ['sometimes', 'boolean'],
                'restricted_buyer_note' => ['nullable', 'string', 'max:255'],
                'restricted_buyer_valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.DisplayTime::toLocal(now())->toDateString(), Rule::requiredIf(fn () => $this->boolean('restricted_buyer_eligible'))],
                // Jenis & ukuran dari ISI berkas diperiksa SecureUpload (SEC-109).
                'restricted_buyer_document' => ['nullable', 'file'],
            ],
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

            // Pelaku tidak boleh menempatkan kios di area/sales/gudang yang lalu tidak bisa ia lihat
            // (SEC-033; supervisor: hanya area yang ia supervisi + sub-area & sales timnya).
            if ($current === null) {
                $area = $this->input('sales_area_id');
                $allowed = $scope->assignableSalesAreaIds($actor);
                if (is_int($area) && $allowed !== null && ! in_array($area, $allowed, true) && ! $v->errors()->has('sales_area_id')) {
                    $v->errors()->add('sales_area_id', 'Area penjualan tidak ditemukan atau di luar wewenang Anda.');
                }

                $sales = $this->input('assigned_sales_employee_id');
                if (is_int($sales) && ! $scope->allows($actor, $sales)) {
                    $v->errors()->add('assigned_sales_employee_id', 'Pegawai tidak ditemukan atau sudah nonaktif.');
                }
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
    public function customerData(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        foreach (['latitude', 'longitude'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = number_format((float) $data[$key], 7, '.', '');
            }
        }

        return $data;
    }

    private function current(): ?Customer
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer ? $customer : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya huruf kapital, angka, dan tanda hubung (-), 3–20 karakter. Contoh: KIO-UNDAAN-01.',
            'code.unique' => 'Kode kios ini sudah dipakai (termasuk yang sudah dihapus).',
            'owner_phone.regex' => PhoneNormalizer::INVALID_MESSAGE,
            'customer_type_id.exists' => 'Jenis pelanggan tidak ditemukan atau sudah nonaktif.',
            'village_code.regex' => 'Desa tidak dikenal.',
            'village_code.exists' => 'Desa tidak ditemukan.',
            'latitude.required_with' => 'Titik lokasi tidak lengkap. Tempel ulang tautan Google Maps atau ambil lokasi HP.',
            'longitude.required_with' => 'Titik lokasi tidak lengkap. Tempel ulang tautan Google Maps atau ambil lokasi HP.',
            'home_stock_location_id.exists' => 'Gudang asal harus gudang atau toko yang aktif.',
            'duplicate_reason.min' => 'Alasan "bukan duplikat" minimal 5 karakter.',
            'harvest_term_max_days.required' => 'Isi batas hari tempo panen.',
            'restricted_buyer_valid_until.required' => 'Isi masa berlaku dokumen kelayakan pembeli terbatas.',
            'restricted_buyer_valid_until.after_or_equal' => 'Masa berlaku dokumen sudah lewat.',
            'sales_area_id.exists' => 'Area penjualan tidak ditemukan atau sudah nonaktif.',
            'assigned_sales_employee_id.exists' => 'Pegawai tidak ditemukan atau sudah nonaktif.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode',
            'store_name' => 'nama toko',
            'owner_name' => 'nama pemilik',
            'owner_phone' => 'HP pemilik',
            'customer_type_id' => 'jenis pelanggan',
            'address' => 'alamat',
            'village_code' => 'desa',
            'latitude' => 'lintang',
            'longitude' => 'bujur',
            'home_stock_location_id' => 'gudang asal',
            'term_days' => 'termin',
            'sales_area_id' => 'area penjualan',
            'assigned_sales_employee_id' => 'sales',
            'not_duplicate' => 'bukan duplikat',
            'duplicate_reason' => 'alasan bukan duplikat',
            'harvest_term_allowed' => 'tempo panen',
            'harvest_term_max_days' => 'batas hari tempo panen',
            'restricted_buyer_eligible' => 'pembeli pestisida terbatas',
            'restricted_buyer_note' => 'catatan pembeli terbatas',
            'restricted_buyer_valid_until' => 'berlaku sampai',
            'restricted_buyer_document' => 'dokumen kelayakan',
        ];
    }
}
