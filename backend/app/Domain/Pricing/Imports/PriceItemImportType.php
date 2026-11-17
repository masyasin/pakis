<?php

namespace App\Domain\Pricing\Imports;

use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Pricing\Actions\CreatePriceVersion;
use App\Domain\Pricing\Actions\UpsertPriceItems;
use App\Domain\Pricing\Enums\PriceVersionStatus;
use App\Domain\Pricing\Models\PriceList;
use App\Domain\Pricing\Models\PriceListItem;
use App\Domain\Pricing\Models\PriceListVersion;
use App\Models\User;
use App\Support\Api\ApiError;
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
use App\Support\Time\DisplayTime;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Impor harga ke daftar harga (T-085, FR-A30, BR-170, PA-70). Baris masuk ke versi DRAF terbaru
 * daftar itu; bila belum ada, dibuat satu versi draf kosong (berlaku besok 00.00 lokal) sekali per
 * daftar per batch. Versi terbit tidak pernah disentuh (trigger DB juga menolaknya). Kunci idempoten =
 * versi + satuan + min qty: impor ulang berkas yang sama ke draf yang sama = 0 perubahan.
 */
final class PriceItemImportType implements ImportType
{
    public const KEY = 'price_items';

    public function __construct(
        private readonly CreatePriceVersion $versions,
        private readonly UpsertPriceItems $items,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Harga';
    }

    public static function routeSlug(): string
    {
        return 'harga';
    }

    public function permission(): string
    {
        return 'prices.manage';
    }

    public function updatePermission(): string
    {
        return 'prices.manage';
    }

    public function fields(): ImportFields
    {
        return new ImportFields([
            'price_list' => ['label' => 'Kode Daftar Harga', 'required' => true, 'hint' => 'Kode di menu Daftar Harga', 'synonyms' => ['kode daftar harga', 'daftar harga', 'price list', 'kode harga']],
            'sku' => ['label' => 'SKU', 'required' => true, 'hint' => '', 'synonyms' => ['sku', 'kode produk', 'kode barang']],
            'unit' => ['label' => 'Satuan', 'required' => true, 'hint' => 'Kode/nama satuan produk, mis. dus', 'synonyms' => ['satuan', 'unit', 'kemasan']],
            'min_qty' => ['label' => 'Min Qty', 'required' => false, 'hint' => 'Kosong = 1 (harga grosir: jumlah minimal)', 'synonyms' => ['min qty', 'minimal', 'jumlah minimal', 'qty minimal']],
            'price' => ['label' => 'Harga', 'required' => true, 'hint' => 'mis. 1.250.000', 'synonyms' => ['harga', 'harga jual', 'price']],
        ]);
    }

    public function context(User $actor, ImportBatch $batch, ImportPhase $phase): object
    {
        return new PriceItemImportContext(
            PriceList::query()->where('is_active', true)->get()->keyBy(fn (PriceList $l) => mb_strtoupper($l->code))->all(),
            $batch->id,
        );
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
                $data[$field] = $values[$field] = $parse($raw[$field]);
            } catch (InvalidArgumentException $e) {
                $errors[$field][] = $e->getMessage();
                $values[$field] = Cells::text($raw[$field], 10_000);
            }
        };
        $take('price_list', Cells::code(...));
        $take('sku', Cells::code(...));
        $take('unit', Cells::code(...));
        $take('min_qty', Cells::qty(...));
        $take('price', Cells::money(...));

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
        $list = is_string($data['price_list'] ?? null) ? ($context->lists[$data['price_list']] ?? null) : null;
        if (is_string($data['price_list'] ?? null) && $list === null) {
            $errors['price_list'][] = 'Kode daftar harga tidak dikenal atau nonaktif.';
        }
        $minQty = $data['min_qty'] ?? '1.000';
        if (BigDecimal::of($minQty)->isZero()) {
            $errors['min_qty'][] = 'Min qty harus lebih dari 0.';
        }

        $unitId = null;
        if (is_string($data['sku'] ?? null) && is_string($data['unit'] ?? null)) {
            $unit = ProductUnit::query()->with('unit:id,code,name')
                ->whereHas('product', fn ($q) => $q->where('sku', $data['sku']))
                ->where('is_active', true)->get()
                ->first(fn (ProductUnit $u) => in_array($data['unit'], [mb_strtoupper($u->unit->code), mb_strtoupper($u->unit->name)], true));
            if ($unit === null) {
                $errors[ProductUnit::query()->whereHas('product', fn ($q) => $q->where('sku', $data['sku']))->exists() ? 'unit' : 'sku'][]
                    = 'Produk atau satuannya tidak ditemukan / nonaktif.';
            }
            $unitId = $unit?->id;
        }

        return new ValidatedRow($row->values, [...Cells::display($row->values), 'parsed' => [
            ...$data, 'min_qty' => (string) $minQty, 'price_list_id' => $list?->id, 'product_unit_id' => $unitId,
        ]], $errors);
    }

    public function rowKey(ValidatedRow $row): ?string
    {
        $p = $row->data['parsed'];
        if (($p['price_list_id'] ?? null) === null || ($p['product_unit_id'] ?? null) === null) {
            return null;
        }

        return $p['price_list_id'].'|'.$p['product_unit_id'].'|'.BigDecimal::of($p['min_qty'])->toScale(3);
    }

    public function describeKey(string $key): string
    {
        return 'daftar harga, produk, satuan, dan min qty sama';
    }

    public function processOrder(array $raws): ImportOrder
    {
        return ImportOrder::asIs($raws);
    }

    public function apply(User $actor, ValidatedRow $row, array $raw, bool $updateExisting, object $context): AppliedRow
    {
        $p = $row->data['parsed'];
        $version = $this->draftFor((int) $p['price_list_id'], $actor, $context);

        $existing = PriceListItem::query()->where('price_list_version_id', $version->id)
            ->where('product_unit_id', $p['product_unit_id'])->where('min_qty', $p['min_qty'])->first();
        if ($existing !== null && BigDecimal::of($existing->price)->isEqualTo($p['price'])) {
            return new AppliedRow(ImportRowStatus::Skipped, ['warnings' => ['Harga sama di versi draf ini; tidak diubah.'], 'version_id' => $version->id]);
        }
        if ($existing !== null && ! $updateExisting) {
            return new AppliedRow(ImportRowStatus::Skipped, ['warnings' => ['Sudah ada di versi draf dengan harga berbeda; tidak diubah (centang "Perbarui data yang sudah ada").'], 'version_id' => $version->id]);
        }

        try {
            $this->items->handle($version, [['product_unit_id' => (int) $p['product_unit_id'], 'min_qty' => (string) $p['min_qty'], 'price' => (string) $p['price']]], [], $actor->id);
        } catch (ApiError $e) {
            throw ValidationException::withMessages(['price_list' => $e->publicMessage()]);
        }

        return new AppliedRow($existing === null ? ImportRowStatus::Created : ImportRowStatus::Updated, ['warnings' => [], 'version_id' => $version->id]);
    }

    public function committed(object $context, array $raw, AppliedRow $row): void {}

    public function errorField(string $key): string
    {
        return match (true) {
            str_ends_with($key, 'product_unit_id') => 'unit',
            str_ends_with($key, 'min_qty') => 'min_qty',
            str_ends_with($key, 'price') => 'price',
            default => $key,
        };
    }

    public function requiresPassword(ImportBatch $batch, User $actor): bool
    {
        return false;
    }

    /**
     * Versi draf tujuan daftar ini: draf terbaru, atau draf kosong baru (berlaku besok 00.00 lokal) bila
     * belum ada. Diingat per batch agar semua baris satu daftar masuk ke versi yang sama.
     */
    private function draftFor(int $listId, User $actor, object $context): PriceListVersion
    {
        if (! $context instanceof PriceItemImportContext) {
            throw new LogicException('Context impor harga tidak sah.');
        }
        // Draf yang dibuat di transaksi baris yang kemudian gagal ikut ter-rollback: cek masih ada.
        $cached = isset($context->drafts[$listId]) ? PriceListVersion::query()->find((int) $context->drafts[$listId]) : null;
        if ($cached !== null) {
            return $cached;
        }
        $draft = PriceListVersion::query()->where('price_list_id', $listId)->where('status', PriceVersionStatus::Draft->value)->orderByDesc('version_no')->first();
        if ($draft === null) {
            $list = PriceList::query()->findOrFail($listId);
            $draft = DB::transaction(fn () => $this->versions->handle($list, [
                'mode' => 'empty',
                'effective_from' => DisplayTime::toLocal(now())->addDay()->startOfDay()->format('Y-m-d\TH:i'),
                'note' => "Impor harga #{$context->batchId}",
            ], $actor->id));
        }
        $context->drafts[$listId] = $draft->id;

        return $draft;
    }
}
