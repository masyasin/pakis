<?php

namespace App\Domain\Inventory\Imports;

use App\Domain\Access\DataScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\SaveStockLevel;
use App\Domain\Inventory\Models\ProductStockLevel;
use App\Domain\Inventory\Models\StockLocation;
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
 * Impor stok minimum/maksimum per produk per lokasi stok (T-085, FR-A30, BR-170; 15 §5
 * `admin.stock-levels.*` "Impor via master_imports tipe stock_levels"). Kunci idempoten = produk +
 * lokasi stok. Lokasi wajib gudang/toko aktif dalam scope Stok pengguna. Qty satuan dasar.
 */
final class StockLevelImportType implements ImportType
{
    public const KEY = 'stock_levels';

    public function __construct(
        private readonly SaveStockLevel $save,
        private readonly DataScope $scope,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Stok Minimum';
    }

    public static function routeSlug(): string
    {
        return 'stok-minimum';
    }

    public function permission(): string
    {
        return 'stock_locations.manage';
    }

    public function updatePermission(): string
    {
        return 'stock_locations.manage';
    }

    public function fields(): ImportFields
    {
        return new ImportFields([
            'stock_location' => ['label' => 'Kode Lokasi Stok', 'required' => true, 'hint' => 'Gudang/toko', 'synonyms' => ['kode lokasi stok', 'lokasi stok', 'gudang', 'lokasi']],
            'sku' => ['label' => 'SKU', 'required' => true, 'hint' => '', 'synonyms' => ['sku', 'kode produk', 'kode barang']],
            'min' => ['label' => 'Stok Minimum', 'required' => true, 'hint' => 'Satuan dasar', 'synonyms' => ['stok minimum', 'minimum', 'min', 'stok min', 'minimal']],
            'max' => ['label' => 'Stok Maksimum', 'required' => false, 'hint' => 'Kosong = tanpa batas', 'synonyms' => ['stok maksimum', 'maksimum', 'maks', 'max', 'stok maks']],
        ]);
    }

    public function context(User $actor, ImportBatch $batch, ImportPhase $phase): object
    {
        $ctx = new stdClass;
        $ctx->locations = $this->scope->forStock(StockLocation::query()->whereIn('type', ['warehouse', 'store'])->where('is_active', true), $actor, 'stock_locations.id')
            ->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtoupper((string) $code) => (int) $id])->all();

        return $ctx;
    }

    public function normalize(array $raw, object $context): NormalizedRow
    {
        $values = [];
        $data = [];
        $errors = [];
        foreach (['stock_location' => Cells::code(...), 'sku' => Cells::code(...), 'min' => Cells::qty(...), 'max' => Cells::qty(...)] as $field => $parse) {
            if (! array_key_exists($field, $raw)) {
                continue;
            }
            try {
                $data[$field] = $values[$field] = $parse($raw[$field]);
            } catch (InvalidArgumentException $e) {
                $errors[$field][] = $e->getMessage();
                $values[$field] = Cells::text($raw[$field], 10_000);
            }
        }
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
        $locationId = is_string($data['stock_location'] ?? null) ? ($context->locations[$data['stock_location']] ?? null) : null;
        if (is_string($data['stock_location'] ?? null) && $locationId === null) {
            $errors['stock_location'][] = 'Lokasi stok tidak dikenal, bukan gudang/toko aktif, atau di luar wilayah Anda.';
        }
        $productId = is_string($data['sku'] ?? null) ? Product::query()->where('sku', $data['sku'])->value('id') : null;
        if (is_string($data['sku'] ?? null) && $productId === null) {
            $errors['sku'][] = 'SKU tidak dikenal.';
        }
        if (is_string($data['min'] ?? null) && is_string($data['max'] ?? null) && BigDecimal::of($data['max'])->isLessThan($data['min'])) {
            $errors['max'][] = 'Stok maksimum tidak boleh lebih kecil dari minimum.';
        }

        return new ValidatedRow($row->values, [...Cells::display($row->values), 'parsed' => [
            ...$data, 'stock_location_id' => $locationId, 'product_id' => $productId === null ? null : (int) $productId,
        ]], $errors);
    }

    public function rowKey(ValidatedRow $row): ?string
    {
        $p = $row->data['parsed'];

        return ($p['product_id'] ?? null) === null || ($p['stock_location_id'] ?? null) === null ? null : $p['stock_location_id'].'|'.$p['product_id'];
    }

    public function describeKey(string $key): string
    {
        return 'lokasi stok dan SKU sama';
    }

    public function processOrder(array $raws): ImportOrder
    {
        return ImportOrder::asIs($raws);
    }

    public function apply(User $actor, ValidatedRow $row, array $raw, bool $updateExisting, object $context): AppliedRow
    {
        $p = $row->data['parsed'];
        $existing = ProductStockLevel::query()->where('product_id', $p['product_id'])->where('stock_location_id', $p['stock_location_id'])->exists();
        if ($existing && ! $updateExisting) {
            $level = ProductStockLevel::query()->where('product_id', $p['product_id'])->where('stock_location_id', $p['stock_location_id'])->firstOrFail();
            $same = BigDecimal::of($level->min_qty_base)->isEqualTo($p['min'])
                && (($level->max_qty_base === null && ($p['max'] ?? null) === null) || ($level->max_qty_base !== null && ($p['max'] ?? null) !== null && BigDecimal::of($level->max_qty_base)->isEqualTo($p['max'])));

            return new AppliedRow(ImportRowStatus::Skipped, ['warnings' => [$same ? 'Sudah ada dan sama; tidak diubah.' : 'Sudah ada dengan nilai berbeda; tidak diubah (centang "Perbarui data yang sudah ada").']]);
        }

        $result = $this->save->handle((int) $p['product_id'], (int) $p['stock_location_id'], (string) $p['min'], $p['max'] ?? null, $actor->id);

        return new AppliedRow(match (true) {
            $result['created'] => ImportRowStatus::Created,
            $result['changed'] => ImportRowStatus::Updated,
            default => ImportRowStatus::Skipped,
        }, ['warnings' => $result['changed'] ? [] : ['Sudah ada dan sama; tidak diubah.']]);
    }

    public function committed(object $context, array $raw, AppliedRow $row): void {}

    public function errorField(string $key): string
    {
        return match ($key) {
            'min_qty_base' => 'min',
            'max_qty_base' => 'max',
            default => $key,
        };
    }

    public function requiresPassword(ImportBatch $batch, User $actor): bool
    {
        return false;
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
