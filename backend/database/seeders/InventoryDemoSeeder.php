<?php

namespace Database\Seeders;

use App\Domain\Accounting\Outbox\AccountingOutbox;
use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\EventSource;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\CreateStockOpname;
use App\Domain\Inventory\Actions\QuarantineStock;
use App\Domain\Inventory\Actions\ReceiveStockTransfer;
use App\Domain\Inventory\Actions\RecordCount;
use App\Domain\Inventory\Actions\RecordRecount;
use App\Domain\Inventory\Actions\SaveDisposalReport;
use App\Domain\Inventory\Actions\SaveStockLevel;
use App\Domain\Inventory\Actions\SaveStockTransfer;
use App\Domain\Inventory\Actions\ShipStockTransfer;
use App\Domain\Inventory\Actions\StartStockOpname;
use App\Domain\Inventory\Enums\DisposalMethod;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Enums\StockOpnameScope;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Data demo gudang T-087 agar laporan stok tidak kosong di dev: saldo awal beberapa batch per produk
 * di gudang utama (GD-UNDAAN), toko (TK-KALIREJO), dan gudang kedua bila ada (GD-KALIREJO) (ED beragam: normal, mendekati ED, sudah lewat), stok minimum (sebagian di
 * bawah minimum), mutasi (diterima, dalam perjalanan dengan pelanggaran FEFO, draf), opname sedang
 * dihitung dengan selisih, karantina, dan BA pemusnahan draf. Semua lewat StockLedger & Action asli
 * (audit, nomor dokumen, outbox sama dengan input layar). Data fiktif; ditolak di produksi.
 *
 *     php artisan db:seed --class=InventoryDemoSeeder
 *
 * Idempoten kasar: dilewati bila sudah ada mutasi demo (catatan "Data demo").
 */
class InventoryDemoSeeder extends Seeder
{
    /** Sumber saldo awal demo: tipe `opening_stock` (label "Saldo awal" di kartu stok), id mulai 900001. */
    private const SOURCE_BASE = 900000;

    /** SKU => [HPP per satuan dasar, [qty per batch gudang utama], [gudang kedua], [toko]] */
    private const STOCK = [
        'DM-INS-001' => ['42500', ['120', '36'], ['80'], ['18']],
        'DM-INS-002' => ['31000', ['60', '24'], ['40'], ['6']],
        'DM-FUN-001' => ['56000', ['90'], ['30', '12'], ['10']],
        'DM-FUN-002' => ['27500', ['48', '20'], ['24'], ['4']],
        'DM-HER-001' => ['64000', ['150', '60'], ['96'], ['24']],
        'DM-HER-002' => ['285000', ['12'], ['8'], []],
        'DM-MOL-001' => ['18500', ['200'], ['80', '40'], ['30']],
        'DM-ROD-001' => ['9500', ['75'], ['50'], ['15']],
        'DM-PER-001' => ['22000', ['40', '16'], ['24'], ['8']],
        'DM-PUP-001' => ['15500', ['300'], ['150'], ['40']],
        'DM-BIB-001' => ['78000', ['60'], ['40'], ['10']],
        'DM-BIB-002' => ['35000', ['100', '50'], ['60'], ['20']],
        'DM-ALT-001' => ['650000', ['8'], ['4'], ['2']],
        'DM-ALT-002' => ['12000', ['120'], ['60'], ['25']],
    ];

    /** Stok minimum di gudang utama: SKU => [min, maks]. Sebagian sengaja di atas saldo (alert). */
    private const LEVELS = [
        'DM-INS-001' => ['100', '400'],
        'DM-INS-002' => ['120', '300'],
        'DM-FUN-002' => ['100', '250'],
        'DM-HER-001' => ['150', '500'],
        'DM-HER-002' => ['20', '60'],
        'DM-MOL-001' => ['100', null],
        'DM-PUP-001' => ['200', '800'],
        'DM-ALT-001' => ['10', '20'],
    ];

    private int $source = self::SOURCE_BASE;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('InventoryDemoSeeder ditolak di produksi: data demo hanya untuk local/staging (04 §4.2).');
        }
        if (StockTransfer::query()->where('note', 'Data demo')->exists()) {
            return;
        }

        // Gudang utama & toko dibuat DemoSeeder; gudang kedua opsional (ada di sebagian DB dev).
        $main = StockLocation::query()->where('code', 'GD-UNDAAN')->first();
        $toko = StockLocation::query()->where('code', 'TK-KALIREJO')->first();
        $second = StockLocation::query()->where('code', 'GD-KALIREJO')->first();
        $owner = User::query()->role('owner')->orderBy('id')->first();
        $head = User::query()->role('kepala_lokasi')->orderBy('id')->first();
        $counter = User::query()->where('name', 'Slamet Riyadi')->first() ?? $head;
        $counter2 = User::query()->where('name', 'Joko Susilo')->first() ?? $owner;
        if ($main === null || $toko === null || $owner === null || $head === null || $counter === null || $counter2 === null) {
            return; // DemoSeeder (lokasi stok & pengguna) belum dijalankan.
        }

        $today = CarbonImmutable::now(DisplayTime::timezone())->startOfDay();
        $date = $today->subDays(10)->toDateString();

        // 1. Saldo awal: batch ke-1 ED jauh, batch ke-2 lebih dekat; beberapa sengaja mendekati ED / lewat.
        $products = Product::query()->whereIn('sku', array_keys(self::STOCK))->get()->keyBy('sku');
        $expiries = [$today->addMonths(18), $today->addDays(45)];
        foreach (self::STOCK as $sku => [$cost, $gd, $ud, $tk]) {
            $product = $products[$sku] ?? null;
            if ($product === null) {
                continue;
            }
            foreach (array_filter([[$main, $gd], [$second, $ud], [$toko, $tk]], fn ($pair) => $pair[0] !== null) as [$location, $qtys]) {
                foreach ($qtys as $i => $qty) {
                    $this->opening($location, $product, $qty, $cost, $i, $expiries[min($i, 1)], $date);
                }
            }
        }
        // Satu batch sudah lewat ED (status expired) dan satu ED 20 hari lagi, di gudang utama.
        $expired = $this->opening($main, $products['DM-FUN-001'], '14', '56000', 7, $today->subDays(3), $date);
        StockLedger::transaction(fn () => app(StockLedger::class)->setStatus($expired->fresh(), StockBatchStatus::Expired, null, $owner, 'Data demo: lewat ED'));
        $this->opening($main, $products['DM-PER-001'], '10', '22000', 8, $today->addDays(20), $date);

        // 2. Stok minimum gudang utama.
        foreach (self::LEVELS as $sku => [$min, $max]) {
            if (isset($products[$sku])) {
                app(SaveStockLevel::class)->handle($products[$sku]->id, $main->id, $min, $max, $owner->id);
            }
        }

        // 3. Mutasi: utama → toko diterima; utama → gudang kedua/toko dikirim dengan batch bukan FEFO; satu draf.
        $target = $second ?? $toko;
        $save = app(SaveStockTransfer::class);
        $header = fn (StockLocation $from, StockLocation $to) => ['from_stock_location_id' => $from->id, 'to_stock_location_id' => $to->id, 'vehicle_id' => null, 'note' => 'Data demo'];
        $received = $save->handle(null, $header($main, $toko), [
            ['product_id' => $products['DM-HER-001']->id, 'qty_base' => '12', 'source_batch_id' => null],
            ['product_id' => $products['DM-INS-001']->id, 'qty_base' => '6', 'source_batch_id' => null],
        ], $head->id);
        app(ShipStockTransfer::class)->handle($received, $head);
        app(ReceiveStockTransfer::class)->handle($received->fresh(), array_values($received->lines()->get()->map(fn ($l) => [
            'line_id' => $l->id, 'qty_received' => (string) $l->qty_shipped_base, 'dest_bin_id' => null, 'variance_reason' => null,
        ])->all()), $owner);

        $newest = StockBatch::query()->where('stock_location_id', $main->id)->where('product_id', $products['DM-INS-002']->id)
            ->where('status', StockBatchStatus::Available->value)->orderByDesc('expiry_date')->first();
        $inTransit = $save->handle(null, $header($main, $target), [
            ['product_id' => $products['DM-INS-002']->id, 'qty_base' => '10', 'source_batch_id' => $newest?->id],
            ['product_id' => $products['DM-PUP-001']->id, 'qty_base' => '25', 'source_batch_id' => null],
        ], $head->id);
        app(ShipStockTransfer::class)->handle($inTransit, $head);
        $save->handle(null, $header($toko, $main), [
            ['product_id' => $products['DM-MOL-001']->id, 'qty_base' => '20', 'source_batch_id' => null],
        ], $counter2->id);

        // 4. Karantina sebagian batch (kemasan bocor) + BA pemusnahan draf untuk batch karantina & ED lewat.
        $leaking = StockBatch::query()->where('stock_location_id', $main->id)->where('product_id', $products['DM-HER-002']->id)->first();
        $quarantined = null;
        if ($leaking !== null) {
            $quarantined = app(QuarantineStock::class)->handle($leaking, Qty::of('3'), $this->reason(ReferenceGroup::QuarantineReason), null, 'Data demo: jeriken bocor', $head);
        }
        $disposalLines = [['stock_batch_id' => $expired->id, 'qty_base' => '14']];
        if ($quarantined !== null) {
            $disposalLines[] = ['stock_batch_id' => $quarantined->id, 'qty_base' => '3'];
        }
        app(SaveDisposalReport::class)->handle([
            'stock_location_id' => $main->id, 'reason_id' => $this->reason(ReferenceGroup::DisposalReason), 'method' => DisposalMethod::Destroy,
            'planned_date' => $today->addDays(7)->toDateString(), 'note' => 'Data demo',
        ], $disposalLines, $head->id);

        // 5. Opname toko sedang dihitung: dua baris selisih (satu dihitung ulang orang lain).
        $opname = app(CreateStockOpname::class)->handle([
            'stock_location_id' => $toko->id, 'scope' => StockOpnameScope::Full, 'scope_ids' => null, 'blind_count' => true, 'note' => 'Data demo',
        ], $counter->id);
        $opname = app(StartStockOpname::class)->handle($opname, $counter->id);
        foreach ($opname->lines()->orderBy('id')->get()->values() as $i => $line) {
            if ($i >= 8) {
                break; // sisanya belum dihitung
            }
            $qty = Qty::of($line->qty_system_base);
            $counted = match ($i) {
                1 => $qty->minus('3'),
                4 => $qty->minus('2'),
                default => $qty,
            };
            app(RecordCount::class)->handle($opname, $line, $counted, null, null, $counter->id);
            if ($i === 1) {
                app(RecordRecount::class)->handle($opname, $line->fresh(), $qty->minus('3'), 'Data demo: dicek ulang', $counter2->id);
            }
        }
    }

    /** Saldo awal satu batch + event outbox (pola T-086), dalam satu transaksi buku stok. */
    private function opening(StockLocation $location, Product $product, string $qty, string $cost, int $index, CarbonImmutable $expiry, string $date): StockBatch
    {
        $id = ++$this->source;
        $bin = $this->bin($location, $product);
        $key = $product->tracks_batch
            ? new BatchKey(sprintf('DM%s-%02d', substr(str_replace('-', '', $product->sku), 2, 6), $index + 1), $product->tracks_expiry ? $expiry->toDateString() : null, $bin)
            : BatchKey::none($bin);

        return StockLedger::transaction(function () use ($location, $product, $qty, $cost, $key, $id, $date) {
            $result = app(StockLedger::class)->receive($location, $product, $key, Qty::of($qty), UnitCost::of($cost), new Source('opening_stock', $id), $date, MovementType::OpeningBalance, 'Data demo');
            $value = (string) $result->value();
            $dims = ['stock_location_id' => $location->id, 'product_id' => $product->id];
            AccountingOutbox::record(EventCode::InventoryOpeningBalance, new EventSource('opening_stock', $id), $date, [
                'lines' => [
                    ['role' => 'inventory', 'debit' => $value, ...$dims],
                    ['role' => 'opening_balance_equity', 'credit' => $value, ...$dims],
                ],
                'memo' => 'Saldo awal data demo',
            ]);

            return StockBatch::query()->findOrFail($result->movement()->stock_batch_id);
        });
    }

    /** Rak sesuai zona bawaan kategori (pestisida / umum), bila ada di lokasi itu. */
    private function bin(StockLocation $location, Product $product): ?int
    {
        $zone = $product->category->default_bin_zone->value;
        $id = StockBin::query()->where('stock_location_id', $location->id)->where('zone', $zone)->where('is_active', true)->orderBy('code')->value('id')
            ?? StockBin::query()->where('stock_location_id', $location->id)->where('zone', '<>', 'quarantine')->where('is_active', true)->orderBy('code')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function reason(ReferenceGroup $group): int
    {
        return (int) ReferenceItem::query()->forGroup($group)->orderBy('sort_order')->value('id');
    }
}
