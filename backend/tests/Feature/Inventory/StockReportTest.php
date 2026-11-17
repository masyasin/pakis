<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Listings\StockMovementListing;
use App\Domain\Inventory\Listings\StockPositionListing;
use App\Domain\Inventory\Models\DisposalReport;
use App\Domain\Inventory\Models\ProductStockLevel;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Database\Seeders\TransitStockLocationSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EmployeeWorld;
use Tests\Support\LedgerWorld;
use Tests\Support\StockWorld;

/*
 * T-087 — laporan stok (FR-A30: posisi, kartu stok, stok minimum, telusur batch), Laporan Audit
 * Stok (PA-72: pelanggaran FEFO, selisih per penghitung), berkas privat gudang (SEC-108/109), dan
 * SEC-107 (HPP/biaya hanya `stock.view_cost`, termasuk BA pemusnahan). Data fiktif. Waktu dibekukan
 * 17 Nov 2026 03.00 UTC. Role & izin Spatie sungguhan.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 03:00:00', 'UTC'));
    Notification::fake();
    Storage::fake('local');
    $this->org = EmployeeWorld::make($this);
    $this->seed([TransitStockLocationSeeder::class, ApprovalRuleSeeder::class]);
    $this->useRealPermissions();

    $other = StockWorld::location('L-LAIN');
    $this->gd = StockWorld::stockLocation($this->org->gudang, 'GD-A');
    $this->toko = StockWorld::stockLocation($this->org->toko, 'GD-B');
    $this->far = StockWorld::stockLocation($other, 'GD-C');
    $this->bin = LedgerWorld::bin($this->gd, 'R-01');

    $this->staff = $this->org->actor('staf_gudang', $this->org->employee($this->org->gudang));
    $this->staff2 = $this->org->actor('staf_gudang', $this->org->employee($this->org->gudang));
    $this->head = $this->org->actor('kepala_gudang', $this->org->employee($this->org->gudang));
    $this->outsider = $this->org->actor('kepala_gudang', $this->org->employee($other));
    $this->owner = $this->org->actor('owner');

    $this->product = LedgerWorld::product('RPT-01');
    $this->batch = LedgerWorld::receive($this->gd, $this->product, '50', '1000', new BatchKey('B-001', '2027-12-31', $this->bin->id));
    $this->farBatch = LedgerWorld::receive($this->far, $this->product, '7', '1000', new BatchKey('B-001', '2027-12-31'));
});

test('FR-A30 SEC-107 posisi stok per produk: scope Stok, nilai HPP hanya untuk stock.view_cost (kunci tidak dikirim)', function () {
    $this->actingAs($this->head)->get('/stok')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('stock/positions')
            ->where('mode', 'per_product')->where('canViewCost', false)
            ->has('rows', 1)
            ->where('rows.0.location', $this->gd->name)
            ->where('rows.0.qty_on_hand', '50.000')
            ->missing('rows.0.value')->missing('rows.0.avg_cost'));

    $this->actingAs($this->owner)->get('/stok?sort=sku')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canViewCost', true)
            ->has('rows', 2)
            ->where('rows.0.value', fn ($v) => in_array((string) $v, ['50000.00', '7000.00'], true)));
});

test('FR-A30 posisi stok per batch: filter tampilan, pencarian nomor batch, filter lokasi', function () {
    LedgerWorld::receive($this->gd, $this->product, '5', '1000', new BatchKey('B-002', '2028-06-30', $this->bin->id));

    $this->actingAs($this->head)->get('/stok?filter[view]=per_batch')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('mode', 'per_batch')->has('rows', 2));
    $this->actingAs($this->head)->get('/stok?filter[view]=per_batch&q=B-002')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.batch_no', 'B-002')->where('rows.0.bin', 'R-01'));
    $this->actingAs($this->owner)->get("/stok?filter[view]=per_batch&filter[stock_location_id][]={$this->far->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.location', $this->far->name));
});

test('SEC-107 ekspor posisi & kartu stok: kolom HPP/biaya hanya untuk stock.view_cost; tanpa stock.export → 403', function () {
    $headers = fn ($listing, $user) => $listing->exportColumnsFor($user)->headers();

    expect($headers(new StockPositionListing, $this->head))->not->toContain('HPP rata-rata')->not->toContain('Nilai')
        ->and($headers(new StockPositionListing, $this->owner))->toContain('HPP rata-rata')->toContain('Nilai')
        ->and($headers(new StockMovementListing, $this->head))->not->toContain('Biaya satuan')
        ->and($headers(new StockMovementListing, $this->owner))->toContain('Biaya satuan');

    $this->actingAs($this->staff)->get('/stok/ekspor')->assertForbidden();
    $this->actingAs($this->staff)->get('/stok/kartu/ekspor')->assertForbidden();
    $this->actingAs($this->head)->get('/stok/ekspor')->assertSuccessful();
});

test('FR-A30 kartu stok: rentang tanggal bawaan bulan berjalan, scope Stok, cari nomor batch, biaya hanya stock.view_cost', function () {
    $this->actingAs($this->head)->get('/stok/kartu')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('stock/movements')
            ->has('rows', 1)
            ->where('rows.0.batch_no', 'B-001')
            ->where('rows.0.type', 'receipt')
            ->where('rows.0.qty_after', '50.000')
            ->missing('rows.0.unit_cost'));

    $this->actingAs($this->owner)->get('/stok/kartu?q=B-001')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2)->has('rows.0.unit_cost'));
    $this->actingAs($this->owner)->get('/stok/kartu?q=TIDAK-ADA')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 0));
});

test('UAT-A17 telusur batch: tanpa produk + nomor batch kosong; dengan keduanya semua lokasi dalam scope (Owner semua)', function () {
    $this->actingAs($this->head)->get('/stok/telusur')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('stock/trace')->where('searched', false)->has('rows', 0)
            // Contoh titik mulai hanya dari batch dalam scope (bukan batch lokasi lain).
            ->where('examples', [['product_id' => $this->product->id, 'sku' => 'RPT-01', 'name' => $this->product->name, 'batch_no' => 'B-001']]));

    $url = "/stok/telusur?filter[product_id][]={$this->product->id}&q=B-001";
    $this->actingAs($this->head)->get($url)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('searched', true)->has('rows', 1)->where('rows.0.location', $this->gd->name));
    $this->actingAs($this->owner)->get($url)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2));
});

test('15 §5 stok minimum: lokasi wajib dalam scope, upsert lewat SaveStockLevel beraudit, filter di bawah minimum; tanpa stock_locations.manage → 403', function () {
    $this->actingAs($this->head)->get('/stok/minimum')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('stock/levels')->where('canManage', false)
            ->where('rows.0.sku', 'RPT-01')->where('rows.0.min_qty_base', null)->where('rows.0.below_min', false));

    $payload = ['product_id' => $this->product->id, 'stock_location_id' => $this->gd->id, 'min_qty_base' => '60', 'max_qty_base' => '100'];
    $this->actingAs($this->head)->put('/stok/minimum', $payload)->assertForbidden();
    $this->actingAs($this->owner)->put('/stok/minimum', $payload)->assertSessionHasNoErrors();

    expect(ProductStockLevel::query()->where('product_id', $this->product->id)->where('stock_location_id', $this->gd->id)->value('min_qty_base'))->toBe('60.000')
        ->and(AuditLog::query()->where('event', 'stock_level.saved')->exists())->toBeTrue();

    $this->actingAs($this->head)->get("/stok/minimum?filter[stock_location_id][]={$this->gd->id}&filter[below_min]=1")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.below_min', true)->where('rows.0.min_qty_base', '60.000'));

    $this->actingAs($this->owner)->put('/stok/minimum', [...$payload, 'max_qty_base' => '10'])->assertSessionHasErrors('max_qty_base');
    $this->actingAs($this->owner)->put('/stok/minimum', [...$payload, 'stock_location_id' => 999999])->assertSessionHasErrors('stock_location_id');
});

test('PA-72 Laporan Audit Stok hanya stock.audit_reports (bawaan Owner): FEFO override mutasi terkirim muncul', function () {
    $newer = LedgerWorld::receive($this->gd, $this->product, '10', '1000', new BatchKey('B-009', '2028-06-30'));
    $this->actingAs($this->staff)->post('/mutasi', [
        'from_stock_location_id' => $this->gd->id, 'to_stock_location_id' => $this->toko->id, 'vehicle_id' => null, 'note' => null,
        'lines' => [['product_id' => $this->product->id, 'qty_base' => '4', 'source_batch_id' => $newer->id]],
    ])->assertSessionHasNoErrors();
    $transfer = StockTransfer::query()->latest('id')->firstOrFail();

    // Draf belum dihitung pelanggaran: batch belum keluar gudang.
    $this->actingAs($this->owner)->get('/stok/audit/fefo')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('stock-audit/index')->where('tab', 'fefo')->has('rows', 0));

    $this->actingAs($this->staff)->post("/mutasi/{$transfer->id}/kirim")->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->get('/stok/audit/fefo')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)
            ->where('rows.0.document.number', $transfer->number)->where('rows.0.batch_no', 'B-009'));
    $this->actingAs($this->head)->get('/stok/audit/fefo')->assertForbidden();
    $this->actingAs($this->head)->get('/stok/audit/penghitung')->assertForbidden();
});

test('PA-72 PA-67 a selisih per penghitung: hitungan pertama yang menyimpang dari ekspektasi dihitung per penghitung', function () {
    $this->actingAs($this->staff)->post('/opname', [
        'stock_location_id' => $this->gd->id, 'scope' => 'full', 'scope_ids' => null, 'blind_count' => true, 'note' => null,
    ])->assertSessionHasNoErrors();
    $opname = StockOpname::query()->latest('id')->firstOrFail();
    $this->actingAs($this->staff)->post("/opname/{$opname->id}/mulai")->assertSessionHasNoErrors();
    $line = $opname->lines()->where('stock_batch_id', $this->batch->id)->sole();
    $this->actingAs($this->staff)->put("/opname/{$opname->id}/baris/{$line->id}", ['mode' => 'count', 'qty' => '48'])->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->get('/stok/audit/penghitung')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'counters')->has('rows', 1)
            ->where('rows.0.counter', $this->staff->name)
            ->where('rows.0.lines_counted', 1)
            ->where('rows.0.variance_lines', 1)
            ->where('rows.0.variance_qty', fn ($v) => (float) $v === 2.0));
});

test('SEC-108 SEC-109 foto opname: URL bertanda tangan 5 menit; tanpa tanda tangan 403; di luar scope 404', function () {
    $this->actingAs($this->staff)->post('/opname', [
        'stock_location_id' => $this->gd->id, 'scope' => 'full', 'scope_ids' => null, 'blind_count' => true, 'note' => null,
    ])->assertSessionHasNoErrors();
    $opname = StockOpname::query()->latest('id')->firstOrFail();
    $this->actingAs($this->staff)->post("/opname/{$opname->id}/mulai")->assertSessionHasNoErrors();
    $line = $opname->lines()->sole();
    Storage::disk('local')->put("opname/{$opname->id}/foto.jpg", 'jpeg-uji');
    $line->forceFill(['photo_path' => "opname/{$opname->id}/foto.jpg"])->save();

    $signed = URL::temporarySignedRoute('admin.stock-opnames.lines.photo', now()->addMinutes(5), ['opname' => $opname->id, 'line' => $line->id], false);
    $this->actingAs($this->staff)->get($signed)->assertOk();
    $this->actingAs($this->staff)->get("/opname/{$opname->id}/baris/{$line->id}/foto")->assertForbidden();
    $this->actingAs($this->org->actor('staf_gudang', $this->org->employee(StockWorld::location('L-JAUH'))))->get($signed)->assertNotFound();

    $this->travel(6)->minutes();
    $this->actingAs($this->staff)->get($signed)->assertForbidden();
});

test('SEC-107 PA-72 BA pemusnahan: nilai HPP hanya untuk stock.view_cost; scan BA & foto lewat URL bertanda tangan ber-scope', function () {
    $report = (new DisposalReport)->forceFill([
        'number' => 'BAP-2026-0099', 'stock_location_id' => $this->gd->id, 'method' => 'destroy', 'status' => 'executed',
        'reason_id' => (int) ReferenceItem::query()->forGroup(ReferenceGroup::DisposalReason)->where('code', 'damaged')->value('id'),
        'total_cost' => '12000', 'document_path' => 'disposal/99/document/ba.pdf', 'photo_paths' => ['disposal/99/photos/a.jpg'],
        'executed_at' => now(), 'created_by' => $this->head->id,
    ]);
    $report->save();
    Storage::disk('local')->put('disposal/99/document/ba.pdf', '%PDF-1.4 uji');
    Storage::disk('local')->put('disposal/99/photos/a.jpg', 'jpeg-uji');

    $documentUrl = null;
    $this->actingAs($this->head)->get("/pemusnahan/{$report->id}")->assertOk()
        ->assertInertia(function (Assert $page) use (&$documentUrl) {
            $page->where('canViewCost', false)->missing('report.total_cost')->has('report.photo_urls', 1)
                ->where('report.document_url', function ($url) use (&$documentUrl) {
                    $documentUrl = $url;

                    return is_string($url) && str_contains($url, 'signature=');
                });
        });
    $this->actingAs($this->head)->get('/pemusnahan')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canViewCost', false)->missing('rows.0.total_cost'));
    $this->actingAs($this->head)->get("/pemusnahan/{$report->id}/cetak")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canViewCost', false)->missing('report.total_cost'));
    $this->actingAs($this->owner)->get("/pemusnahan/{$report->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canViewCost', true)->where('report.total_cost', '12000.00'));

    $this->actingAs($this->head)->get($documentUrl)->assertOk();
    $this->actingAs($this->head)->get("/pemusnahan/{$report->id}/dokumen")->assertForbidden();
    $this->actingAs($this->outsider)->get($documentUrl)->assertNotFound();
    $photo = URL::temporarySignedRoute('admin.disposal-reports.photo', now()->addMinutes(5), ['report' => $report->id, 'index' => 0], false);
    $missing = URL::temporarySignedRoute('admin.disposal-reports.photo', now()->addMinutes(5), ['report' => $report->id, 'index' => 3], false);
    $this->actingAs($this->head)->get($photo)->assertOk();
    $this->actingAs($this->head)->get($missing)->assertNotFound();
});

test('15 §7.4 widget dasbor "Stok di bawah minimum": scope Stok, urut rasio saldo/minimum, tanpa stock.view = null', function () {
    $other = LedgerWorld::product('RPT-02');
    LedgerWorld::receive($this->gd, $other, '45', '1000', new BatchKey('B-777', '2027-12-31', $this->bin->id));
    foreach ([[$this->product->id, $this->gd->id, '100'], [$other->id, $this->gd->id, '50'], [$this->product->id, $this->far->id, '70']] as [$p, $l, $min]) {
        (new ProductStockLevel)->forceFill(['product_id' => $p, 'stock_location_id' => $l, 'min_qty_base' => $min])->save();
    }

    $this->actingAs($this->head)->get('/dasbor')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('below_min.total', 2)
            ->where('below_min.items.0.sku', 'RPT-01')->where('below_min.items.0.qty_on_hand', fn ($v) => (float) $v === 50.0)
            ->where('below_min.items.1.sku', 'RPT-02'));
    $this->actingAs($this->owner)->get('/dasbor')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('below_min.total', 3)->where('below_min.items.0.location', $this->far->name));
    $hr = $this->org->actor('hrd');
    $this->actingAs($hr)->get('/dasbor')->assertOk()->assertInertia(fn (Assert $page) => $page->where('below_min', null));
});

test('T-087 detail produk tab Stok: batch bersaldo dalam scope Stok tanpa HPP (SEC-107); Owner semua lokasi', function () {
    $this->actingAs($this->head)->get("/produk/{$this->product->id}?tab=stok")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('products/show')->has('stock', 1)
            ->where('stock.0.location', $this->gd->name)->where('stock.0.batch_no', 'B-001')->where('stock.0.bin', 'R-01')
            ->missing('stock.0.avg_cost')->missing('stock.0.value'));
    $this->actingAs($this->owner)->get("/produk/{$this->product->id}?tab=stok")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('stock', 2));
});
