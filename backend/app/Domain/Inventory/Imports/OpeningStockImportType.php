<?php

namespace App\Domain\Inventory\Imports;

use App\Domain\Access\DataScope;
use App\Domain\Accounting\Outbox\AccountingOutbox;
use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\EventSource;
use App\Domain\Accounting\Period\PeriodGuard;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Import\Actions\ProcessImportBatch;
use App\Support\Import\AppliedRow;
use App\Support\Import\Cells;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportFields;
use App\Support\Import\ImportOrder;
use App\Support\Import\ImportPhase;
use App\Support\Import\ImportRow;
use App\Support\Import\ImportRowStatus;
use App\Support\Import\ImportType;
use App\Support\Import\NormalizedRow;
use App\Support\Import\ValidatedRow;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Impor saldo awal stok (T-086, FR-A30, BR-170): per lokasi stok + SKU + batch + ED + rak + qty + biaya.
 * Kunci idempoten = lokasi + SKU + batch + rak; mutasi `opening_balance` yang sudah ada untuk kunci itu
 * dilewati (impor ulang = 0 perubahan). Hanya selama `inventory.opening_balance_open` menyala dan hanya
 * ke lokasi yang belum punya mutasi lain (`LOCATION_HAS_MOVEMENTS`). Tanggal bisnis = tanggal cutover
 * yang diisi saat Proses (`PeriodGuard`). Tiap baris: mutasi `opening_balance` lewat `StockLedger::receive`
 * (HPP = rata-rata tertimbang biaya berkas) + event `inventory.opening_balance` (D inventory · K
 * opening_balance_equity) di transaksi yang sama; tidak pernah `inventory.goods_received` (15 §11.4).
 */
final class OpeningStockImportType implements ImportType
{
    public const KEY = 'opening_stock';

    /** Sumber mutasi & event: satu baris impor (id `import_rows`), agar kartu stok bisa menelusur ke berkasnya. */
    public const SOURCE_TYPE = 'opening_stock';

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly DataScope $scope,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Saldo Awal Stok';
    }

    public static function routeSlug(): string
    {
        return 'saldo-awal';
    }

    /** Berkas memuat biaya (HPP), jadi hanya yang boleh melihat biaya stok (keputusan Mas, T-086). */
    public function permission(): string
    {
        return 'stock.view_cost';
    }

    /** Saldo awal tidak pernah menimpa: baris yang sudah ada selalu dilewati. */
    public function updatePermission(): ?string
    {
        return null;
    }

    public function fields(): ImportFields
    {
        return new ImportFields([
            'stock_location' => ['label' => 'Kode Lokasi Stok', 'required' => true, 'hint' => 'Gudang/toko yang belum bertransaksi', 'synonyms' => ['kode lokasi stok', 'lokasi stok', 'gudang', 'lokasi', 'kode gudang']],
            'sku' => ['label' => 'SKU', 'required' => true, 'hint' => '', 'synonyms' => ['sku', 'kode produk', 'kode barang']],
            'batch_no' => ['label' => 'No. Batch', 'required' => false, 'hint' => 'Wajib untuk produk ber-batch', 'synonyms' => ['no batch', 'nomor batch', 'batch', 'lot', 'no lot']],
            'expiry_date' => ['label' => 'Tanggal ED', 'required' => false, 'hint' => 'mis. 17/08/2027; wajib untuk produk ber-ED', 'synonyms' => ['tanggal ed', 'ed', 'kedaluwarsa', 'tgl ed', 'expired', 'exp']],
            'bin' => ['label' => 'Kode Rak', 'required' => false, 'hint' => 'Kosong = tanpa rak', 'synonyms' => ['kode rak', 'rak', 'bin', 'lokasi rak']],
            'qty' => ['label' => 'Qty', 'required' => true, 'hint' => 'Satuan dasar, mis. 1.250', 'synonyms' => ['qty', 'jumlah', 'stok', 'kuantitas', 'stok fisik']],
            'unit_cost' => ['label' => 'Biaya per Satuan', 'required' => true, 'hint' => 'Per satuan dasar, mis. 12.500', 'synonyms' => ['biaya per satuan', 'biaya', 'hpp', 'harga pokok', 'harga beli', 'cost']],
        ]);
    }

    public function context(User $actor, ImportBatch $batch, ImportPhase $phase): object
    {
        $locations = $this->scope->forStock(StockLocation::query()->whereIn('type', ['warehouse', 'store'])->where('is_active', true), $actor, 'stock_locations.id')
            ->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtoupper((string) $code) => (int) $id])->all();
        $locked = StockMovement::query()->where('movement_type', '!=', MovementType::OpeningBalance->value)
            ->whereIn('stock_location_id', array_values($locations))->distinct()->pluck('stock_location_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
        $products = Product::query()->get(['id', 'sku', 'tracks_batch', 'tracks_expiry', 'tracks_serial'])
            ->mapWithKeys(fn (Product $p) => [mb_strtoupper($p->sku) => [
                'id' => $p->id, 'tracks_batch' => $p->tracks_batch, 'tracks_expiry' => $p->tracks_expiry, 'tracks_serial' => $p->tracks_serial,
            ]])->all();
        $bins = StockBin::query()->whereIn('stock_location_id', array_values($locations))->where('is_active', true)->get(['id', 'stock_location_id', 'code'])
            ->mapWithKeys(fn (StockBin $b) => [$b->stock_location_id.'|'.mb_strtoupper($b->code) => $b->id])->all();

        return new OpeningStockImportContext($locations, $locked, $products, $bins, $batch->id);
    }

    public function normalize(array $raw, object $context): NormalizedRow
    {
        $values = [];
        $data = [];
        $errors = [];
        $parsers = [
            'stock_location' => Cells::code(...), 'sku' => Cells::code(...), 'batch_no' => fn ($v) => Cells::text($v, 60),
            'expiry_date' => Cells::date(...), 'bin' => Cells::code(...), 'qty' => Cells::qty(...), 'unit_cost' => Cells::unitCost(...),
        ];
        foreach ($parsers as $field => $parse) {
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
        if (is_string($data['qty'] ?? null) && Qty::of($data['qty'])->isZero()) {
            $errors['qty'][] = 'Qty harus lebih dari 0.';
        }

        return new NormalizedRow($values, $data, $errors);
    }

    public function validate(NormalizedRow $row, object $context): ValidatedRow
    {
        $ctx = self::ctx($context);
        $data = $row->data;
        $errors = $row->errors;

        $locationId = is_string($data['stock_location'] ?? null) ? ($ctx->locations[$data['stock_location']] ?? null) : null;
        if (is_string($data['stock_location'] ?? null) && $locationId === null) {
            $errors['stock_location'][] = 'Lokasi stok tidak dikenal, bukan gudang/toko aktif, atau di luar wilayah Anda.';
        } elseif ($locationId !== null && isset($ctx->lockedLocations[$locationId])) {
            $errors['stock_location'][] = ErrorCode::LOCATION_HAS_MOVEMENTS->message();
        }

        $product = is_string($data['sku'] ?? null) ? ($ctx->products[$data['sku']] ?? null) : null;
        if (is_string($data['sku'] ?? null) && $product === null) {
            $errors['sku'][] = 'SKU tidak dikenal.';
        }
        $batchNo = $data['batch_no'] ?? null;
        $expiry = $data['expiry_date'] ?? null;
        if ($product !== null) {
            if ($product['tracks_serial']) {
                // PA-71: nomor seri butuh daftar per unit yang tidak ada di format baris ini.
                $errors['sku'][] = 'Produk bernomor seri tidak bisa diimpor sebagai saldo awal. Catat lewat penerimaan barang beserta nomor serinya.';
            }
            if ($product['tracks_batch'] && $batchNo === null && ! isset($errors['batch_no'])) {
                $errors['batch_no'][] = 'Wajib diisi untuk produk yang dilacak per batch.';
            }
            if ($product['tracks_expiry'] && $expiry === null && ! isset($errors['expiry_date'])) {
                $errors['expiry_date'][] = 'Wajib diisi untuk produk yang dilacak tanggal ED-nya.';
            }
            // Produk tanpa pelacakan batch selalu memakai batch kosong agar FEFO & stoknya satu baris.
            if (! $product['tracks_batch']) {
                $batchNo = null;
            }
        }

        $binId = null;
        if (is_string($data['bin'] ?? null) && $locationId !== null) {
            $binId = $ctx->bins[$locationId.'|'.$data['bin']] ?? null;
            if ($binId === null) {
                $errors['bin'][] = 'Kode rak tidak ada atau nonaktif di lokasi ini.';
            }
        }

        return new ValidatedRow($row->values, [...Cells::display($row->values), 'parsed' => [
            'stock_location' => $data['stock_location'] ?? null,
            'stock_location_id' => $locationId,
            'product_id' => $product['id'] ?? null,
            'batch_no' => $batchNo,
            'expiry_date' => $expiry,
            'bin_id' => $binId,
            'qty' => $data['qty'] ?? null,
            'unit_cost' => $data['unit_cost'] ?? null,
        ]], $errors);
    }

    public function rowKey(ValidatedRow $row): ?string
    {
        $p = $row->data['parsed'];
        if (($p['stock_location_id'] ?? null) === null || ($p['product_id'] ?? null) === null) {
            return null;
        }

        return $p['stock_location_id'].'|'.$p['product_id'].'|'.mb_strtoupper((string) ($p['batch_no'] ?? BatchKey::NO_BATCH)).'|'.($p['bin_id'] ?? '-');
    }

    public function describeKey(string $key): string
    {
        return 'lokasi stok, SKU, batch, dan rak sama';
    }

    public function processOrder(array $raws): ImportOrder
    {
        return ImportOrder::asIs($raws);
    }

    public function apply(User $actor, ValidatedRow $row, array $raw, bool $updateExisting, object $context): AppliedRow
    {
        $ctx = self::ctx($context);
        $p = $row->data['parsed'];
        $batch = ImportBatch::query()->findOrFail($ctx->batchId);
        $date = ProcessImportBatch::businessDate($batch) ?? throw new LogicException('Tanggal cutover saldo awal belum diisi.');
        $rowId = ImportRow::query()->where('import_batch_id', $ctx->batchId)->where('row_key', hash('sha256', (string) $this->rowKey($row)))->value('id');
        if ($rowId === null) {
            throw new LogicException('Baris impor saldo awal tidak ditemukan.');
        }

        try {
            // Diperiksa ulang di transaksi baris: setting bisa ditutup, lokasi bisa bertransaksi, atau
            // tanggal kunci bisa maju selama batch besar berjalan di antrean.
            $this->ensureAvailable();
            $location = StockLocation::query()->lockForUpdate()->findOrFail((int) $p['stock_location_id']);
            if (StockMovement::query()->where('stock_location_id', $location->id)->where('movement_type', '!=', MovementType::OpeningBalance->value)->exists()) {
                throw new ApiError(ErrorCode::LOCATION_HAS_MOVEMENTS);
            }
            PeriodGuard::assertOpen($date);
        } catch (ApiError $e) {
            throw ValidationException::withMessages([$e->errorCode === ErrorCode::LOCATION_HAS_MOVEMENTS ? 'stock_location' : '_row' => $e->publicMessage()]);
        }

        $key = new BatchKey($p['batch_no'] ?? BatchKey::NO_BATCH, $p['expiry_date'] ?? null, $p['bin_id'] ?? null);
        if ($this->alreadyImported($location->id, (int) $p['product_id'], $key)) {
            return new AppliedRow(ImportRowStatus::Skipped, ['warnings' => ['Saldo awal batch ini sudah ada; tidak diubah.']]);
        }

        $product = Product::query()->findOrFail((int) $p['product_id']);
        $source = new Source(self::SOURCE_TYPE, (int) $rowId);
        $result = $this->ledger->receive($location, $product, $key, Qty::of((string) $p['qty']), UnitCost::of((string) $p['unit_cost']), $source, $date, MovementType::OpeningBalance);

        $value = (string) $result->value();
        $dims = ['stock_location_id' => $location->id, 'product_id' => $product->id];
        AccountingOutbox::record(EventCode::InventoryOpeningBalance, new EventSource(self::SOURCE_TYPE, (int) $rowId), $date, [
            'lines' => [
                ['role' => 'inventory', 'debit' => $value, ...$dims],
                ['role' => 'opening_balance_equity', 'credit' => $value, ...$dims],
            ],
            'memo' => "Saldo awal impor #{$ctx->batchId}",
        ]);

        return new AppliedRow(ImportRowStatus::Created, ['warnings' => [], 'stock_batch_id' => $result->movement()->stock_batch_id]);
    }

    public function committed(object $context, array $raw, AppliedRow $row): void {}

    public function errorField(string $key): string
    {
        return $key;
    }

    public function requiresPassword(ImportBatch $batch, User $actor): bool
    {
        return false;
    }

    public function ensureAvailable(): void
    {
        if (setting('inventory.opening_balance_open') !== true) {
            throw new ApiError(ErrorCode::OPENING_BALANCE_CLOSED);
        }
    }

    public function requiresBusinessDate(): bool
    {
        return true;
    }

    /** Ringkasan baris valid sebelum Proses: jumlah baris, total qty per lokasi (satuan dasar), total nilai. */
    public function summary(ImportBatch $batch): array
    {
        $perLocation = [];
        $total = BigDecimal::zero();
        $rows = 0;
        ImportRow::query()->where('import_batch_id', $batch->id)->where('status', ImportRowStatus::Valid->value)
            ->select(['id', 'data'])->orderBy('id')->chunk(2000, function ($chunk) use (&$perLocation, &$total, &$rows) {
                foreach ($chunk as $r) {
                    $p = $r->data['parsed'] ?? [];
                    if (! is_string($p['qty'] ?? null) || ! is_string($p['unit_cost'] ?? null)) {
                        continue;
                    }
                    $code = (string) ($p['stock_location'] ?? '-');
                    $perLocation[$code] = ($perLocation[$code] ?? BigDecimal::zero())->plus($p['qty']);
                    $total = $total->plus(BigDecimal::of($p['qty'])->multipliedBy($p['unit_cost'])->toScale(2, RoundingMode::HalfUp));
                    $rows++;
                }
            });
        ksort($perLocation);

        return [
            ['label' => 'Baris valid', 'value' => self::number(BigDecimal::of($rows))],
            ...array_map(fn (string $code, BigDecimal $qty) => ['label' => "Total qty {$code}", 'value' => self::number($qty)], array_keys($perLocation), $perLocation),
            ['label' => 'Total nilai', 'value' => 'Rp. '.self::number($total->toScale(2, RoundingMode::HalfUp), 2)],
        ];
    }

    private function alreadyImported(int $locationId, int $productId, BatchKey $key): bool
    {
        return StockMovement::query()->where('movement_type', MovementType::OpeningBalance->value)
            ->whereHas('batch', fn ($q) => $q->where('stock_location_id', $locationId)->where('product_id', $productId)
                ->where('batch_no', $key->batchNo)
                ->when($key->binId === null, fn ($b) => $b->whereNull('stock_bin_id'), fn ($b) => $b->where('stock_bin_id', $key->binId)))
            ->exists();
    }

    private static function ctx(object $context): OpeningStockImportContext
    {
        if (! $context instanceof OpeningStockImportContext) {
            throw new LogicException('Context impor saldo awal tidak sah.');
        }

        return $context;
    }

    /** Angka gaya id-ID ("1.250,5"): pemisah ribuan titik, desimal koma, tanpa nol di belakang kecuali diminta. */
    private static function number(BigDecimal $value, int $minDecimals = 0): string
    {
        $text = (string) $value;
        [$int, $dec] = array_pad(explode('.', $text, 2), 2, '');
        $dec = rtrim($dec, '0');
        $dec = str_pad($dec, $minDecimals, '0');
        // Tanpa float (ADR-101): kelompokkan digit bilangan bulat per tiga dari kanan.
        $sign = str_starts_with($int, '-') ? '-' : '';
        $int = $sign.strrev(implode('.', str_split(strrev(ltrim($int, '-')), 3)));

        return $dec === '' ? $int : $int.','.$dec;
    }
}
