<?php

namespace App\Http\Requests\Pricing;

use App\Domain\Access\DataScope;
use App\Domain\Pricing\Enums\PromotionFunding;
use App\Domain\Pricing\Enums\PromotionType;
use App\Domain\Pricing\Models\Promotion;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah/ubah promo draf (FR-A20, BR-169). Periode diisi waktu lokal kantor (datetime-local).
 * Dana prinsipal/bersama wajib prinsipal; bersama wajib porsi 0–100 (kriteria T-076). Tier
 * divalidasi per tipe di `after()`.
 */
class PromotionRequest extends FormRequest
{
    private const DECIMAL = '/^\d{1,15}(\.\d{1,4})?$/';

    public function authorize(): bool
    {
        return true; // middleware `can:promotions.manage`; status draf dicek Action
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code')) ? strtoupper(trim($this->input('code'))) : $this->input('code'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $current = $this->route('promo');

        return [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9-]{3,30}$/',
                Rule::unique('promotions', 'code')->ignore($current instanceof Promotion ? $current->id : null)],
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'type' => ['required', Rule::enum(PromotionType::class)],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
            'is_stackable' => ['required', 'boolean'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', 'distinct', Rule::in(Promotion::CHANNELS)],
            'budget_amount' => ['nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'funded_by' => ['required', Rule::enum(PromotionFunding::class)],
            'principal_id' => ['nullable', 'required_unless:funded_by,company', 'integer', Rule::exists('principals', 'id')->whereNull('deleted_at')],
            'principal_share_pct' => ['nullable', 'required_if:funded_by,shared', 'string', 'regex:'.self::DECIMAL],
            'description' => ['nullable', 'string', 'max:500'],
            'targets' => ['required', 'array', 'min:1', 'max:200'],
            'targets.*.type' => ['required', Rule::in(['product', 'category', 'principal'])],
            'targets.*.id' => ['required', 'integer', 'min:1'],
            'scopes' => ['required', 'array', 'min:1', 'max:200'],
            'scopes.*.type' => ['required', Rule::in(['all', 'price_list', 'sales_area', 'customer'])],
            'scopes.*.id' => ['nullable', 'integer', 'min:1'],
            'tiers' => ['required', 'array', 'min:1', 'max:20'],
            'tiers.*.min_qty_base' => ['nullable', 'string', 'regex:'.self::DECIMAL],
            'tiers.*.min_amount' => ['nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'tiers.*.discount_pct' => ['nullable', 'string', 'regex:'.self::DECIMAL],
            'tiers.*.discount_amount' => ['nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'tiers.*.bundle_price' => ['nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'tiers.*.free_product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'tiers.*.free_qty_base' => ['nullable', 'string', 'regex:'.self::DECIMAL],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $share = $this->input('principal_share_pct');
            if ($this->input('funded_by') === 'shared' && is_string($share)
                && (BigDecimal::of($share)->isLessThanOrEqualTo(0) || BigDecimal::of($share)->isGreaterThanOrEqualTo(100))) {
                $v->errors()->add('principal_share_pct', 'Porsi prinsipal harus di atas 0 dan di bawah 100%.');
            }

            /** @var list<array{type: string, id?: int|null}> $scopes */
            $scopes = $this->input('scopes', []);
            $types = array_column($scopes, 'type');
            if (in_array('all', $types, true) && count($types) > 1) {
                $v->errors()->add('scopes', 'Cakupan "semua" tidak boleh digabung dengan cakupan lain.');
            }
            foreach ($scopes as $i => $scope) {
                if ($scope['type'] !== 'all' && empty($scope['id'])) {
                    $v->errors()->add("scopes.{$i}.id", 'Pilih daftar harga, area, atau kios.');
                }
            }

            // Id target & cakupan harus menunjuk data yang ada (tabel berbeda per jenis).
            $tables = [
                'targets' => ['product' => 'products', 'category' => 'product_categories', 'principal' => 'principals'],
                'scopes' => ['price_list' => 'price_lists', 'sales_area' => 'sales_areas', 'customer' => 'customers'],
            ];
            foreach ($tables as $field => $map) {
                /** @var list<array{type: string, id?: int|string|null}> $rows */
                $rows = $this->input($field, []);
                foreach ($rows as $i => $row) {
                    $table = $map[$row['type']] ?? null;
                    if ($table === null || empty($row['id'])) {
                        continue;
                    }
                    $exists = DB::table($table)->where('id', (int) $row['id'])->exists();
                    // Kios di luar scope data pengguna diperlakukan sama dengan tidak ada (IDOR, 06 §5).
                    if ($exists && $row['type'] === 'customer') {
                        $user = $this->user();
                        $exists = $user instanceof User && app(DataScope::class)->allowsCustomer($user, (int) $row['id']);
                    }
                    if (! $exists) {
                        $v->errors()->add("{$field}.{$i}.id", 'Data yang dipilih tidak ditemukan.');
                    }
                }
            }

            $this->validateTiers($v, PromotionType::from((string) $this->input('type')));
        }];
    }

    private function validateTiers(Validator $v, PromotionType $type): void
    {
        /** @var list<array<string, mixed>> $tiers */
        $tiers = $this->input('tiers', []);
        $filled = fn (array $t, string $k) => isset($t[$k]) && $t[$k] !== '';
        $positive = fn (array $t, string $k) => $filled($t, $k) && BigDecimal::of((string) $t[$k])->isPositive();

        if ($type !== PromotionType::TieredDiscount && count($tiers) !== 1) {
            $v->errors()->add('tiers', 'Tipe promo ini memakai tepat satu aturan.');

            return;
        }
        if ($type === PromotionType::TieredDiscount && count($tiers) < 2) {
            $v->errors()->add('tiers', 'Diskon bertingkat butuh minimal dua tingkat.');

            return;
        }

        foreach ($tiers as $i => $t) {
            if (! $positive($t, 'min_qty_base') && ! $positive($t, 'min_amount')) {
                $v->errors()->add("tiers.{$i}.min_qty_base", 'Isi jumlah minimal (satuan dasar) atau belanja minimal.');
            }
            $pctOk = $positive($t, 'discount_pct') && BigDecimal::of((string) $t['discount_pct'])->isLessThan(100);
            match ($type) {
                PromotionType::PercentDiscount => $pctOk ?: $v->errors()->add("tiers.{$i}.discount_pct", 'Diskon harus di atas 0 dan di bawah 100%.'),
                PromotionType::AmountDiscount => $positive($t, 'discount_amount') ?: $v->errors()->add("tiers.{$i}.discount_amount", 'Isi potongan per satuan dasar.'),
                PromotionType::TieredDiscount => ($pctOk xor $positive($t, 'discount_amount')) ?: $v->errors()->add("tiers.{$i}.discount_pct", 'Tiap tingkat berisi diskon persen ATAU potongan nominal.'),
                PromotionType::BundlePrice => ($positive($t, 'bundle_price') && $positive($t, 'min_qty_base')) ?: $v->errors()->add("tiers.{$i}.bundle_price", 'Isi jumlah per bundel (satuan dasar) dan harga bundel.'),
                PromotionType::BuyXGetY => ($positive($t, 'min_qty_base') && $filled($t, 'free_product_id') && $positive($t, 'free_qty_base'))
                    ?: $v->errors()->add("tiers.{$i}.free_product_id", 'Isi jumlah beli (X), produk gratis, dan jumlah gratis (Y).'),
            };
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode 3–30 karakter: huruf kapital, angka, dan tanda hubung. Contoh: PROMO-DES26.',
            'ends_at.after' => 'Waktu selesai harus setelah waktu mulai.',
            'principal_id.required_unless' => 'Promo dana prinsipal atau bersama wajib memilih prinsipal.',
            'principal_share_pct.required_if' => 'Promo dana bersama wajib mengisi porsi prinsipal (%).',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => 'kode', 'name' => 'nama', 'type' => 'tipe', 'starts_at' => 'mulai', 'ends_at' => 'selesai', 'priority' => 'prioritas',
            'channels' => 'kanal', 'budget_amount' => 'anggaran', 'funded_by' => 'sumber dana', 'principal_id' => 'prinsipal',
            'principal_share_pct' => 'porsi prinsipal', 'targets' => 'target', 'scopes' => 'cakupan', 'tiers' => 'aturan',
        ];
    }
}
