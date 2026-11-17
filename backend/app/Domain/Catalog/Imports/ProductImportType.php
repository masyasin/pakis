<?php

namespace App\Domain\Catalog\Imports;

use App\Domain\Catalog\Actions\CreateProduct;
use App\Domain\Catalog\Actions\SaveProductUnit;
use App\Domain\Catalog\Actions\UpdateProduct;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Support\ProductFlags;
use App\Domain\Purchasing\Models\Principal;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Import\AppliedRow;
use App\Support\Import\Cells;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportFields;
use App\Support\Import\ImportOrder;
use App\Support\Import\ImportPhase;
use App\Support\Import\ImportRowStatus;
use App\Support\Import\ImportType;
use App\Support\Import\NormalizedRow;
use App\Support\Import\ValidatedRow;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use stdClass;

/**
 * Impor master produk (T-085, FR-A30, BR-170). Kunci idempoten = SKU (dipakai walau mode SKU otomatis,
 * PA-70). Rujukan berupa kode: kategori, prinsipal, formulasi, satuan (kode atau nama referensi).
 * Penanda lacak kosong = ikut kategori; menonaktifkan penanda yang diwajibkan kategori butuh alasan
 * (BR-151). Satuan kemasan (opsional) dibuat/diperbarui sebagai satuan beli bawaan. Produk yang sudah
 * ada & identik → dilewati (impor ulang = 0 perubahan).
 */
final class ProductImportType implements ImportType
{
    public const KEY = 'products';

    private const BOOLS = ['tracks_batch', 'tracks_expiry', 'tracks_serial', 'requires_registration', 'is_restricted', 'is_sellable', 'is_purchasable'];

    public function __construct(
        private readonly CreateProduct $create,
        private readonly UpdateProduct $update,
        private readonly SaveProductUnit $units,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Produk';
    }

    public static function routeSlug(): string
    {
        return 'produk';
    }

    public function permission(): string
    {
        return 'products.manage';
    }

    public function updatePermission(): string
    {
        return 'products.manage';
    }

    public function fields(): ImportFields
    {
        $yes = 'ya/tidak; kosong = ikut kategori';

        return new ImportFields([
            'sku' => ['label' => 'SKU', 'required' => true, 'hint' => '3–30 huruf besar, angka, strip', 'synonyms' => ['sku', 'kode produk', 'kode barang', 'kode item']],
            'name' => ['label' => 'Nama Produk', 'required' => true, 'hint' => '', 'synonyms' => ['nama produk', 'nama barang', 'nama item', 'nama', 'produk']],
            'category' => ['label' => 'Kode Kategori', 'required' => true, 'hint' => 'Kode di menu Kategori', 'synonyms' => ['kategori', 'kode kategori', 'golongan']],
            'principal' => ['label' => 'Kode Prinsipal', 'required' => false, 'hint' => '', 'synonyms' => ['prinsipal', 'kode prinsipal', 'pabrik']],
            'base_unit' => ['label' => 'Satuan Dasar', 'required' => true, 'hint' => 'Kode/nama satuan, mis. botol', 'synonyms' => ['satuan', 'satuan dasar', 'unit', 'satuan kecil']],
            'brand' => ['label' => 'Merek', 'required' => false, 'hint' => '', 'synonyms' => ['merek', 'merk', 'brand']],
            'active_ingredient' => ['label' => 'Bahan Aktif', 'required' => false, 'hint' => '', 'synonyms' => ['bahan aktif', 'zat aktif']],
            'formulation' => ['label' => 'Formulasi', 'required' => false, 'hint' => 'Kode, mis. EC', 'synonyms' => ['formulasi', 'formula']],
            'package_size' => ['label' => 'Ukuran Kemasan', 'required' => false, 'hint' => 'mis. 500 ml', 'synonyms' => ['ukuran kemasan', 'isi kemasan', 'ukuran', 'volume']],
            'tracks_batch' => ['label' => 'Lacak Batch', 'required' => false, 'hint' => $yes, 'synonyms' => ['lacak batch', 'batch']],
            'tracks_expiry' => ['label' => 'Lacak ED', 'required' => false, 'hint' => $yes, 'synonyms' => ['lacak ed', 'lacak kedaluwarsa', 'ed']],
            'tracks_serial' => ['label' => 'Lacak No. Seri', 'required' => false, 'hint' => $yes, 'synonyms' => ['lacak nomor seri', 'lacak no seri', 'nomor seri', 'serial']],
            'requires_registration' => ['label' => 'Wajib Izin Edar', 'required' => false, 'hint' => $yes, 'synonyms' => ['wajib izin edar', 'izin edar', 'wajib izin']],
            'flag_reason' => ['label' => 'Alasan Penanda', 'required' => false, 'hint' => 'Wajib bila penanda kategori dimatikan', 'synonyms' => ['alasan penanda', 'alasan']],
            'is_restricted' => ['label' => 'Pestisida Terbatas', 'required' => false, 'hint' => 'ya/tidak (bawaan tidak)', 'synonyms' => ['terbatas', 'pestisida terbatas']],
            'is_sellable' => ['label' => 'Dijual', 'required' => false, 'hint' => 'ya/tidak (bawaan ya)', 'synonyms' => ['dijual', 'bisa dijual']],
            'is_purchasable' => ['label' => 'Dibeli', 'required' => false, 'hint' => 'ya/tidak (bawaan ya)', 'synonyms' => ['dibeli', 'bisa dibeli']],
            'min_shelf_life_months' => ['label' => 'Sisa Umur Min (bulan)', 'required' => false, 'hint' => '0–120', 'synonyms' => ['sisa umur min', 'sisa umur minimal', 'umur simpan minimal']],
            'pack_unit' => ['label' => 'Satuan Kemasan', 'required' => false, 'hint' => 'mis. dus (satuan beli)', 'synonyms' => ['satuan kemasan', 'satuan besar', 'satuan beli', 'kemasan']],
            'pack_factor' => ['label' => 'Isi per Kemasan', 'required' => false, 'hint' => 'Jumlah satuan dasar, mis. 24', 'synonyms' => ['isi per kemasan', 'isi kemasan', 'isi', 'konversi']],
        ]);
    }

    public function context(User $actor, ImportBatch $batch, ImportPhase $phase): object
    {
        $ctx = new stdClass;
        $ctx->categories = ProductCategory::query()->get()->keyBy(fn (ProductCategory $c) => mb_strtoupper($c->code));
        $ctx->principals = Principal::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtoupper((string) $code) => (int) $id])->all();
        $ctx->units = self::references(ReferenceGroup::Unit);
        $ctx->formulations = self::references(ReferenceGroup::Formulation);

        return $ctx;
    }

    public function normalize(array $raw, object $context): NormalizedRow
    {
        $values = [];
        $data = [];
        $errors = [];
        $take = function (string $field, callable $parse) use ($raw, &$values, &$data, &$errors): void {
            if (! array_key_exists($field, $raw)) {
                return;
            }
            try {
                $data[$field] = $parse($raw[$field]);
                $values[$field] = is_bool($data[$field]) ? ($data[$field] ? 'ya' : 'tidak') : $data[$field];
            } catch (InvalidArgumentException $e) {
                $errors[$field][] = $e->getMessage();
                $values[$field] = Cells::text($raw[$field], 10_000);
            }
        };

        $take('sku', Cells::code(...));
        $take('name', fn ($v) => Cells::text($v, 150));
        foreach (['category', 'principal', 'base_unit', 'formulation', 'pack_unit'] as $f) {
            $take($f, Cells::code(...));
        }
        $take('brand', fn ($v) => Cells::text($v, 80));
        $take('active_ingredient', fn ($v) => Cells::text($v, 255));
        $take('package_size', fn ($v) => Cells::text($v, 40));
        $take('flag_reason', fn ($v) => Cells::text($v, 255));
        foreach (self::BOOLS as $f) {
            $take($f, Cells::bool(...));
        }
        $take('min_shelf_life_months', fn ($v) => Cells::int($v, 120));
        $take('pack_factor', Cells::qty(...));

        foreach ($this->fields()->required() as $f) {
            if (($data[$f] ?? null) === null && ! isset($errors[$f])) {
                $errors[$f][] = 'Wajib diisi.';
            }
        }

        return new NormalizedRow($values, $data, $errors);
    }

    public function validate(NormalizedRow $row, object $context): ValidatedRow
    {
        $data = $row->data;
        $errors = $row->errors;
        $err = function (string $f, string $m) use (&$errors): void {
            $errors[$f][] = $m;
        };

        $sku = $data['sku'] ?? null;
        if (is_string($sku) && preg_match('/^[A-Z0-9-]{3,30}$/', $sku) !== 1) {
            $err('sku', 'SKU 3–30 karakter: huruf besar, angka, dan strip.');
        }
        $category = is_string($data['category'] ?? null) ? ($context->categories[$data['category']] ?? null) : null;
        if (is_string($data['category'] ?? null) && $category === null) {
            $err('category', 'Kode kategori tidak dikenal.');
        }
        $resolved = ['principal_id' => null, 'base_unit_id' => null, 'formulation_id' => null, 'pack_unit_id' => null];
        foreach (['principal' => ['principals', 'principal_id', 'Kode prinsipal'], 'base_unit' => ['units', 'base_unit_id', 'Satuan'],
            'formulation' => ['formulations', 'formulation_id', 'Formulasi'], 'pack_unit' => ['units', 'pack_unit_id', 'Satuan kemasan']] as $field => [$map, $key, $label]) {
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $resolved[$key] = $context->{$map}[$value] ?? null;
                if ($resolved[$key] === null) {
                    $err($field, "{$label} tidak dikenal.");
                }
            }
        }

        if ($category instanceof ProductCategory) {
            // Penanda kosong = ikut kategori (BR-151).
            foreach (Product::FLAGS as $flag => $categoryFlag) {
                if (($data[$flag] ?? null) === null) {
                    $data[$flag] = (bool) $category->{$categoryFlag};
                }
            }
            $relaxed = ProductFlags::relaxed($category, array_intersect_key($data, Product::FLAGS));
            if ($relaxed !== [] && trim((string) ($data['flag_reason'] ?? '')) === '') {
                $err($relaxed[0], 'Kategori mewajibkan '.implode(', ', array_map(ProductFlags::label(...), $relaxed)).'. Isi kolom Alasan Penanda bila produk ini tidak mewajibkannya.');
            }
        }
        if (($data['tracks_expiry'] ?? false) === true && ($data['tracks_batch'] ?? false) !== true) {
            $err('tracks_expiry', 'Lacak ED hanya bisa bila lacak batch juga ya.');
        }

        $hasPackUnit = ($data['pack_unit'] ?? null) !== null;
        $hasPackFactor = ($data['pack_factor'] ?? null) !== null;
        if ($hasPackUnit !== $hasPackFactor) {
            $err($hasPackUnit ? 'pack_factor' : 'pack_unit', 'Satuan kemasan dan isinya diisi berpasangan.');
        } elseif ($hasPackFactor && BigDecimal::of((string) $data['pack_factor'])->isLessThanOrEqualTo(1)) {
            $err('pack_factor', 'Isi per kemasan harus lebih dari 1.');
        } elseif ($hasPackUnit && $resolved['pack_unit_id'] !== null && $resolved['pack_unit_id'] === $resolved['base_unit_id']) {
            $err('pack_unit', 'Satuan kemasan harus beda dengan satuan dasar.');
        }

        return new ValidatedRow($row->values, [...Cells::display($row->values), 'parsed' => [...$data, ...$resolved, 'product_category_id' => $category?->id]], $errors);
    }

    public function rowKey(ValidatedRow $row): ?string
    {
        $sku = $row->data['parsed']['sku'] ?? null;

        return is_string($sku) && $sku !== '' ? 'sku:'.$sku : null;
    }

    public function describeKey(string $key): string
    {
        return 'SKU sama';
    }

    public function processOrder(array $raws): ImportOrder
    {
        return ImportOrder::asIs($raws);
    }

    public function apply(User $actor, ValidatedRow $row, array $raw, bool $updateExisting, object $context): AppliedRow
    {
        $d = $row->data['parsed'];
        $values = [
            'name' => $d['name'],
            'product_category_id' => $d['product_category_id'],
            'principal_id' => $d['principal_id'],
            'brand' => $d['brand'] ?? null,
            'active_ingredient' => $d['active_ingredient'] ?? null,
            'formulation_id' => $d['formulation_id'],
            'package_size' => $d['package_size'] ?? null,
            'base_unit_id' => $d['base_unit_id'],
            'tracks_batch' => (bool) ($d['tracks_batch'] ?? false),
            'tracks_expiry' => (bool) ($d['tracks_expiry'] ?? false),
            'tracks_serial' => (bool) ($d['tracks_serial'] ?? false),
            'requires_registration' => (bool) ($d['requires_registration'] ?? false),
            'is_restricted' => (bool) ($d['is_restricted'] ?? false),
            'is_sellable' => (bool) ($d['is_sellable'] ?? true),
            'is_purchasable' => (bool) ($d['is_purchasable'] ?? true),
            'min_shelf_life_months' => $d['min_shelf_life_months'] ?? null,
        ];
        // Produk baru memakai nilai lengkap (penanda kosong = kategori). Produk lama hanya dibandingkan &
        // diubah pada kolom yang dipetakan, agar kolom yang tidak ada di berkas tidak tertimpa bawaan.
        $mappedValues = array_filter($values, fn ($v, $k) => $this->mapped($raw, $k), ARRAY_FILTER_USE_BOTH);
        $pack = isset($d['pack_unit_id']) ? ['unit_id' => (int) $d['pack_unit_id'], 'factor' => (string) $d['pack_factor']] : null;

        $product = Product::withTrashed()->where('sku', $d['sku'])->first();
        if ($product === null) {
            $created = $this->create->handle([
                ...$values,
                'sku' => $d['sku'],
                'status' => ProductStatus::Active->value,
                'flag_reason' => $d['flag_reason'] ?? null,
            ], null, $actor->id, keepSku: true);
            if ($pack !== null) {
                $this->units->handle($created, null, [...$pack, 'is_default_sales' => false, 'is_default_purchase' => true, 'is_active' => true], $actor->id);
            }

            return new AppliedRow(ImportRowStatus::Created, ['product_id' => $created->id, 'warnings' => []]);
        }

        $packUnit = $pack === null ? null : ProductUnit::query()->where('product_id', $product->id)->where('unit_id', $pack['unit_id'])->first();
        $changed = $this->changes($product, $mappedValues);
        $packChanged = $pack !== null && ($packUnit === null || ! BigDecimal::of($packUnit->factor)->isEqualTo($pack['factor']));
        if ($changed === [] && ! $packChanged) {
            return new AppliedRow(ImportRowStatus::Skipped, ['product_id' => $product->id, 'warnings' => ['Sudah ada dan sama; tidak diubah.']]);
        }
        if (! $updateExisting) {
            return new AppliedRow(ImportRowStatus::Skipped, ['product_id' => $product->id, 'warnings' => ['SKU sudah ada dengan data berbeda; tidak diubah (centang "Perbarui data yang sudah ada" untuk menimpa).']]);
        }

        if ($changed !== []) {
            $current = array_intersect_key($product->only(Product::AUDITED), array_flip(Product::AUDITED));
            $this->update->handle($product, [...$current, ...$mappedValues, 'status' => $product->status->value, 'flag_reason' => $d['flag_reason'] ?? null], null, false, $actor->id);
        }
        if ($packChanged) {
            $this->units->handle($product, $packUnit, [...$pack, 'is_default_sales' => (bool) ($packUnit->is_default_sales ?? false), 'is_default_purchase' => true, 'is_active' => true], $actor->id);
        }

        return new AppliedRow(ImportRowStatus::Updated, ['product_id' => $product->id, 'warnings' => []]);
    }

    public function committed(object $context, array $raw, AppliedRow $row): void {}

    public function errorField(string $key): string
    {
        return match ($key) {
            'product_category_id' => 'category',
            'principal_id' => 'principal',
            'base_unit_id' => 'base_unit',
            'formulation_id' => 'formulation',
            'unit_id' => 'pack_unit',
            'factor' => 'pack_factor',
            default => $key,
        };
    }

    public function requiresPassword(ImportBatch $batch, User $actor): bool
    {
        return false;
    }

    /**
     * Kode & nama referensi aktif → id (huruf besar), agar "botol", "BOTOL", dan kode `bottle`
     * sama-sama dikenali.
     *
     * @return array<string, int>
     */
    private static function references(ReferenceGroup $group): array
    {
        $map = [];
        foreach (ReferenceItem::query()->forGroup($group)->get(['id', 'code', 'name']) as $item) {
            $map[mb_strtoupper($item->name)] = $item->id;
            $map[mb_strtoupper($item->code)] = $item->id;
        }

        return $map;
    }

    /**
     * Field produk ini berasal dari kolom yang dipetakan di berkas.
     *
     * @param  array<string, mixed>  $raw
     */
    private function mapped(array $raw, string $column): bool
    {
        $field = match ($column) {
            'product_category_id' => 'category',
            'principal_id' => 'principal',
            'base_unit_id' => 'base_unit',
            'formulation_id' => 'formulation',
            default => $column,
        };

        return in_array($column, ['name', 'product_category_id', 'base_unit_id'], true) || array_key_exists($field, $raw);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> kolom yang berbeda dari produk tersimpan
     */
    private function changes(Product $product, array $values): array
    {
        $diff = [];
        foreach ($values as $column => $value) {
            $current = $product->getAttribute($column);
            $same = is_bool($value) ? (bool) $current === $value : (string) ($current ?? '') === (string) ($value ?? '');
            if (! $same) {
                $diff[$column] = $value;
            }
        }

        return $diff;
    }

    public function ensureAvailable(): void {}

    public function requiresBusinessDate(): bool
    {
        return false;
    }

    public function summary(ImportBatch $batch): array
    {
        return [];
    }
}
