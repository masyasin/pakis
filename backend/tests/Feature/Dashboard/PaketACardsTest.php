<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\LedgerResult;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Pricing\Models\PriceList;
use App\Domain\Pricing\Models\PriceListItem;
use App\Domain\Pricing\Models\PriceListVersion;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Support\Import\ImportBatch;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EmployeeWorld;
use Tests\Support\LedgerWorld;
use Tests\Support\StockWorld;

/*
 * T-087A — kartu dasbor tambahan Paket A (PA-73). Tiap kartu: hanya untuk pemegang izinnya, angka =
 * jumlah baris halaman tujuan dengan filter tautannya, kartu baru bernilai 0 tidak dikirim, nilai rupiah
 * hanya `stock.view_cost`. Data fiktif. Waktu dibekukan 17 Nov 2026 03.00 UTC. Izin Spatie sungguhan.
 */

const PAC_NEW_KEYS = ['quarantine_batches', 'expired_batches', 'opnames_open', 'disposals_approved', 'returns_open', 'receipts_draft',
    'customers_unverified', 'price_drafts', 'promos_ending', 'imports_pending', 'products_unpriced'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 03:00:00', 'UTC'));
    $this->org = EmployeeWorld::make($this);
    $this->useRealPermissions();

    $other = StockWorld::location('L-LAIN');
    $this->gd = StockWorld::stockLocation($this->org->gudang, 'GD-A');
    $this->far = StockWorld::stockLocation($other, 'GD-C');
    $this->owner = $this->org->actor('owner');
    $this->head = $this->org->actor('kepala_gudang', $this->org->employee($this->org->gudang));
    $this->sales = $this->org->actor('admin_penjualan');

    // Batch karantina & kedaluwarsa di GD-A (scope kepala gudang), satu karantina di GD-C (di luar scope).
    $this->product = LedgerWorld::product('DSB-01');
    $reason = ReferenceItem::query()->forGroup(ReferenceGroup::QuarantineReason)->value('id');
    $status = fn ($batch, StockBatchStatus $to) => StockLedger::transaction(fn () => LedgerWorld::ledger()->setStatus($batch, $to, $to === StockBatchStatus::Quarantine ? $reason : null));
    $status(LedgerWorld::receive($this->gd, $this->product, '5', '1000', new BatchKey('Q-1', '2027-12-31')), StockBatchStatus::Quarantine);
    $status(LedgerWorld::receive($this->gd, $this->product, '3', '1000', new BatchKey('X-1', '2026-10-01')), StockBatchStatus::Expired);
    $status(LedgerWorld::receive($this->far, $this->product, '2', '1000', new BatchKey('Q-2', '2027-12-31')), StockBatchStatus::Quarantine);
});

/** Satuan dasar yang sudah dibuat LedgerWorld::product(). */
function pacUnit(Product $product): ProductUnit
{
    return ProductUnit::query()->where('product_id', $product->id)->where('is_base', true)->firstOrFail();
}

/** Versi harga lewat jalur DB: draf → item → status akhir. */
function pacVersion(PriceList $list, ?ProductUnit $unit, string $status, string $from, ?string $to = null, int $no = 1): PriceListVersion
{
    $v = tap((new PriceListVersion)->forceFill(['price_list_id' => $list->id, 'version_no' => $no, 'effective_from' => $from, 'effective_to' => $to, 'status' => 'draft']))->save();
    if ($unit !== null) {
        (new PriceListItem)->forceFill(['price_list_version_id' => $v->id, 'product_id' => $unit->product_id, 'product_unit_id' => $unit->id, 'min_qty' => '1', 'price' => '10000'])->save();
    }
    if ($status !== 'draft') {
        $v->forceFill(['status' => $status, 'published_at' => now()])->save();
    }

    return $v;
}

function pacList(string $code, bool $active = true): PriceList
{
    return tap((new PriceList)->forceFill(['code' => $code, 'name' => "List {$code}", 'kind' => 'tier', 'is_active' => $active]))->save();
}

/** @return array<string, array{key: string, count: int, href: string}> */
function pacPending(mixed $user): array
{
    $items = [];
    test()->actingAs($user)->get(route('admin.dashboard'))->assertOk()->assertInertia(function (Assert $page) use (&$items) {
        $items = collect($page->toArray()['props']['pending'])->keyBy('key')->all();
    });

    return $items;
}

function pacTotal(mixed $user, string $href): int
{
    $total = -1;
    test()->actingAs($user)->get($href)->assertOk()->assertInertia(function (Assert $page) use (&$total) {
        $total = (int) $page->toArray()['props']['listing']['pagination']['total'];
    });

    return $total;
}

test('T-087A PA-73 angka kartu = jumlah baris halaman tujuan; kartu baru bernilai 0 tidak dikirim', function () {
    // Harga: satu daftar punya versi draf; promo aktif berakhir 3 hari lagi; satu batch impor tervalidasi.
    pacVersion(pacList('DRAFT'), null, 'draft', '2026-12-01 00:00:00');
    $this->actingAs($this->owner)->post('/promo', [
        'code' => 'promo-dsb', 'name' => 'Promo Dasbor', 'type' => 'percent_discount', 'starts_at' => '2026-11-10T00:00', 'ends_at' => '2026-11-20T00:00',
        'priority' => 100, 'is_stackable' => false, 'channels' => ['office'], 'budget_amount' => null, 'funded_by' => 'company', 'principal_id' => null,
        'principal_share_pct' => null, 'description' => null, 'targets' => [['type' => 'product', 'id' => $this->product->id]],
        'scopes' => [['type' => 'all', 'id' => null]], 'tiers' => [['min_qty_base' => '1', 'discount_pct' => '10']],
    ])->assertSessionHasNoErrors();
    Promotion::query()->latest('id')->firstOrFail()->forceFill(['status' => 'active'])->save();
    (new ImportBatch)->forceFill(['type' => 'products', 'original_name' => 'p.xlsx', 'stored_path' => 'imports/x.xlsx', 'file_hash' => str_repeat('a', 64),
        'status' => 'validated', 'total_rows' => 1, 'valid_rows' => 1, 'created_by' => $this->owner->id])->save();

    $pending = pacPending($this->owner);
    expect($pending['quarantine_batches']['count'] ?? null)->toBe(2)
        ->and($pending['expired_batches']['count'] ?? null)->toBe(1)
        ->and($pending['price_drafts']['count'] ?? null)->toBe(1)
        ->and($pending['promos_ending']['count'] ?? null)->toBe(1)
        ->and($pending['imports_pending']['count'] ?? null)->toBe(1)
        ->and($pending['products_unpriced']['count'] ?? null)->toBe(1)
        // Tanpa data: tidak dikirim (kartu lama tetap ada walau 0).
        ->and(array_keys($pending))->not->toContain('opnames_open', 'disposals_approved', 'returns_open', 'receipts_draft', 'customers_unverified')
        ->and(array_keys($pending))->toContain('po_overdue');

    foreach (array_intersect_key($pending, array_flip(PAC_NEW_KEYS)) as $key => $item) {
        expect(pacTotal($this->owner, $item['href']))->toBe($item['count'], "kartu {$key}");
    }

    // Ambang promo dari setting: 2 hari → promo yang berakhir 3 hari lagi tidak terhitung.
    setSetting('pricing.promo_ending_days', 2);
    expect(array_keys(pacPending($this->owner)))->not->toContain('promos_ending');
});

test('T-087A PA-73 kartu hanya untuk pemegang izin, angka sesuai scope Stok', function () {
    $head = pacPending($this->head);
    expect($head['quarantine_batches']['count'] ?? null)->toBe(1) // GD-C di luar scope
        ->and(pacTotal($this->head, $head['quarantine_batches']['href']))->toBe(1)
        ->and(array_keys($head))->not->toContain('price_drafts', 'products_unpriced', 'promos_ending');

    $sales = pacPending($this->sales);
    expect(array_keys($sales))->not->toContain('quarantine_batches', 'expired_batches', 'products_unpriced');

    $this->actingAs($this->head)->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page->where('opening_balance', null)->where('levels_unset', null));
});

test('T-087A PA-73 produk tanpa harga: versi superseded yang masih berlaku dihitung berharga; daftar nonaktif & versi lewat masa tidak', function () {
    $priced = LedgerWorld::product('DSB-02');
    $superseded = LedgerWorld::product('DSB-03');
    $inactiveList = LedgerWorld::product('DSB-04');
    $expired = LedgerWorld::product('DSB-05');
    $inactive = LedgerWorld::product('DSB-06');
    $inactive->forceFill(['status' => 'inactive'])->save();

    pacVersion(pacList('A'), pacUnit($priced), 'published', '2026-11-01 00:00:00');
    pacVersion(pacList('B'), pacUnit($superseded), 'superseded', '2026-11-01 00:00:00');
    pacVersion(pacList('C', active: false), pacUnit($inactiveList), 'published', '2026-11-01 00:00:00');
    pacVersion(pacList('D'), pacUnit($expired), 'published', '2026-10-01 00:00:00', '2026-11-10 00:00:00');

    $skus = [];
    $this->actingAs($this->owner)->get('/produk?filter[price_status]=missing')->assertOk()
        ->assertInertia(function (Assert $page) use (&$skus) {
            $skus = collect($page->toArray()['props']['rows'])->pluck('sku')->sort()->values()->all();
        });
    expect($skus)->toBe(['DSB-01', 'DSB-04', 'DSB-05']);
});

test('T-087A PA-73 banner saldo awal: hanya settings.update selama dibuka, nilai hanya stock.view_cost', function () {
    StockLedger::transaction(fn (): LedgerResult => LedgerWorld::ledger()->receive($this->gd, $this->product, new BatchKey('OB-1', '2027-12-31'),
        Qty::of('10'), UnitCost::of('1250'), LedgerWorld::source('opening_stock', 99), '2026-11-17', MovementType::OpeningBalance));

    $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('opening_balance.count', 1)->where('opening_balance.value', '12500.00')
        ->where('opening_balance.href', route('admin.settings.edit', ['group' => 'inventory'])));

    $this->owner->revokePermissionTo('stock.view_cost');
    $this->owner->roles->each->revokePermissionTo('stock.view_cost');
    app()->forgetScopedInstances();
    $this->actingAs($this->owner->fresh())->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page->where('opening_balance.value', null));

    setSetting('inventory.opening_balance_open', false);
    $this->actingAs($this->owner->fresh())->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page->where('opening_balance', null));
});

test('T-087A PA-73 produk tanpa stok minimum per lokasi; tautan lokasi = jumlah baris filter "belum diatur"', function () {
    LedgerWorld::product('DSB-07');
    $this->actingAs($this->owner)->put('/stok/minimum', ['product_id' => $this->product->id, 'stock_location_id' => $this->gd->id, 'min_qty_base' => '5'])
        ->assertSessionHasNoErrors();

    $data = null;
    $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertInertia(function (Assert $page) use (&$data) {
        $data = $page->toArray()['props']['levels_unset'];
    });
    $byLocation = collect($data['items'])->keyBy('location_id');
    expect($byLocation[$this->gd->id]['missing'] ?? null)->toBe(1)
        ->and($byLocation[$this->far->id]['missing'] ?? null)->toBe(2)
        ->and($data['total'])->toBe($byLocation->sum('missing'));

    expect(pacTotal($this->owner, "/stok/minimum?filter[stock_location_id]={$this->gd->id}&filter[unset]=1"))->toBe(1)
        ->and(pacTotal($this->owner, "/stok/minimum?filter[stock_location_id]={$this->far->id}&filter[unset]=1"))->toBe(2);
});

test('T-087A PA-73 grafik stok: nilai per lokasi hanya stock.view_cost; mutasi per hari & ED per bulan dalam scope Stok', function () {
    LedgerWorld::receive($this->gd, $this->product, '4', '1000', new BatchKey('E-1', '2026-12-20'));

    $owner = null;
    $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertInertia(function (Assert $page) use (&$owner) {
        $owner = $page->toArray()['props']['stock_charts'];
    });
    expect(collect($owner['value'])->pluck('value', 'location_id')->all())->toBe([$this->gd->id => '12000.00', $this->far->id => '2000.00'])
        ->and(collect($owner['flow'])->firstWhere('date', '2026-11-16'))->toBe(['date' => '2026-11-16', 'in' => 4, 'out' => 0])
        ->and($owner['expiry_days'])->toBe(90)
        ->and(collect($owner['expiry'])->pluck('month')->all())->toBe(['2026-11', '2026-12', '2027-01', '2027-02'])
        ->and(collect($owner['expiry'])->firstWhere('month', '2026-12'))->toBe(['month' => '2026-12', 'batches' => 1, 'value' => '4000.00']);

    $head = null;
    $this->actingAs($this->head)->get(route('admin.dashboard'))->assertInertia(function (Assert $page) use (&$head) {
        $head = $page->toArray()['props']['stock_charts'];
    });
    expect($head['value'])->toBeNull()
        ->and(collect($head['flow'])->firstWhere('date', '2026-11-16')['in'])->toBe(3)
        ->and(collect($head['expiry'])->firstWhere('month', '2026-12'))->toBe(['month' => '2026-12', 'batches' => 1, 'value' => null]);
});
