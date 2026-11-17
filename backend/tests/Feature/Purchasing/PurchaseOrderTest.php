<?php

use App\Domain\Approval\Actions\DecideApprovalStep;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Purchasing\Actions\RecalculatePurchaseOrder;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Api\ApiError;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\EmployeeWorld;
use Tests\Support\StockWorld;

/*
 * T-079 — purchase order + persetujuan (FR-A24, BR-160, BR-153, SEC-101, 15 §4.9/§5). Data fiktif.
 * Waktu dibekukan 16 Nov 2026 03.00 UTC (10.00 WIB). Role & izin Spatie sungguhan.
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 03:00:00', 'UTC'));
    Notification::fake();
    $this->org = EmployeeWorld::make($this);
    $this->seed(ApprovalRuleSeeder::class);
    $this->useRealPermissions();
    setSetting('approval.reauth_amount', '999999999999');

    $this->boss = $this->org->employee();
    $this->buyerEmployee = $this->org->employee(supervisor: $this->boss);
    $this->buyer = $this->org->actor('purchasing', $this->buyerEmployee);
    $this->owner = $this->org->actor('owner');

    $this->warehouse = StockWorld::stockLocation($this->org->gudang, 'GD-UJI');
    $this->store = StockWorld::stockLocation($this->org->toko, 'TK-UJI', 'store');
    $this->supplier = poSupplier('CV Pemasok Uji');

    $category = ProductCategory::query()->create([
        'code' => 'INS', 'name' => 'Insektisida', 'kind' => 'insecticide', 'requires_batch' => true, 'requires_expiry' => true,
        'requires_serial' => false, 'requires_registration' => true, 'default_bin_zone' => 'pesticide', 'sort_order' => 1, 'is_active' => true,
    ]);
    [$this->product, $this->bottle] = poProduct($category, 'PO-01');
    $this->box = tap((new ProductUnit)->forceFill([
        'product_id' => $this->product->id, 'unit_id' => ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('code', 'box')->value('id'),
        'factor' => '24', 'is_base' => false, 'is_default_sales' => false, 'is_default_purchase' => true, 'is_active' => true,
    ]))->save();
    [$this->blocked] = poProduct($category, 'PO-02', ['is_purchasable' => false]);
});

function poSupplier(string $name, bool $active = true): Supplier
{
    $s = (new Supplier)->forceFill(['code' => 'SUP-'.(Supplier::query()->count() + 1), 'name' => $name, 'payment_term_days' => 45, 'is_active' => $active]);
    $s->save();

    return $s;
}

/** @return array{0: Product, 1: ProductUnit} */
function poProduct(ProductCategory $category, string $sku, array $attrs = []): array
{
    $unit = ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('code', 'bottle')->value('id');
    $p = (new Product)->forceFill([
        'sku' => $sku, 'name' => "Produk {$sku}", 'product_category_id' => $category->id, 'base_unit_id' => $unit,
        'tracks_batch' => true, 'tracks_expiry' => true, 'tracks_serial' => false, 'requires_registration' => true,
        'is_restricted' => false, 'is_sellable' => true, 'is_purchasable' => true, 'status' => 'active', ...$attrs,
    ]);
    $p->save();
    $base = tap((new ProductUnit)->forceFill(['product_id' => $p->id, 'unit_id' => $unit, 'factor' => '1', 'is_base' => true, 'is_default_sales' => true, 'is_default_purchase' => true, 'is_active' => true]))->save();

    return [$p, $base];
}

/** @return array<string, mixed> */
function poPayload(array $override = [], ?array $lines = null): array
{
    return [
        'supplier_id' => test()->supplier->id,
        'stock_location_id' => test()->warehouse->id,
        'order_date' => '2026-11-16',
        'expected_date' => '2026-11-20',
        'payment_term_days' => null,
        'note' => null,
        'lines' => $lines ?? [
            ['product_unit_id' => test()->box->id, 'qty' => '2', 'unit_price' => '240.000', 'discount_pct' => '10'],
            ['product_unit_id' => test()->bottle->id, 'qty' => '5', 'unit_price' => '12.500,50', 'discount_pct' => ''],
        ],
        ...$override,
    ];
}

function makePo(?User $actor = null, array $override = [], ?array $lines = null): PurchaseOrder
{
    test()->actingAs($actor ?? test()->buyer)->post('/pembelian/po', poPayload($override, $lines))->assertSessionHasNoErrors();

    return PurchaseOrder::query()->latest('id')->firstOrFail();
}

/** PO senilai kira-kira `$amount` (satu baris botol). */
function poWorth(string $amount, ?User $actor = null): PurchaseOrder
{
    return makePo($actor, lines: [['product_unit_id' => test()->bottle->id, 'qty' => '1', 'unit_price' => $amount, 'discount_pct' => '0']]);
}

function poDecide(User $actor, PurchaseOrder $po): ApprovalRequest
{
    $request = ApprovalRequest::query()->findOrFail($po->fresh()->approval_request_id);

    return app(DecideApprovalStep::class)->approve($actor, $request->id, $request->current_step, null, true);
}

test('BR-160 total dihitung server dari baris; total kiriman klien diabaikan; nomor PO-2611-0001', function () {
    $po = makePo(override: ['subtotal' => '1', 'grand_total' => '1', 'discount_total' => '0']);

    expect($po->number)->toBe('PO-2611-0001')
        ->and($po->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($po->subtotal)->toBe('542502.50')
        ->and($po->discount_total)->toBe('48000.00')
        ->and($po->tax_total)->toBe('0.00')
        ->and($po->grand_total)->toBe('494502.50')
        // Tempo kosong = tempo supplier.
        ->and($po->payment_term_days)->toBe(45);

    $lines = $po->lines()->get();
    expect($lines)->toHaveCount(2)
        ->and($lines[0]->qty_base)->toBe('48.000')
        ->and($lines[0]->line_total)->toBe('432000.00')
        ->and($lines[1]->line_total)->toBe('62502.50');
    expect(AuditLog::query()->where('event', 'purchase_order.created')->where('auditable_id', $po->id)->exists())->toBeTrue();

    // Penomoran reset bulanan (14 §8).
    $this->travelTo(CarbonImmutable::parse('2026-12-01 03:00:00', 'UTC'));
    $this->flushSession(); // sesi dua minggu lalu sudah kedaluwarsa (batas idle)
    expect(makePo(override: ['order_date' => '2026-12-01', 'expected_date' => null])->number)->toBe('PO-2612-0001');
});

test('BR-160 supplier nonaktif, produk tidak bisa dibeli, satuan bulat dengan pecahan, dan diskon 100% ditolak', function () {
    $inactive = poSupplier('CV Berhenti', active: false);
    $post = fn (array $override = [], ?array $lines = null) => $this->actingAs($this->buyer)->post('/pembelian/po', poPayload($override, $lines));

    $post(['supplier_id' => $inactive->id])->assertSessionHasErrors('supplier_id');
    $blockedUnit = ProductUnit::query()->where('product_id', $this->blocked->id)->sole();
    $post(lines: [['product_unit_id' => $blockedUnit->id, 'qty' => '1', 'unit_price' => '1000', 'discount_pct' => '0']])
        ->assertSessionHasErrors('lines.0.product_unit_id');
    $post(lines: [['product_unit_id' => $this->box->id, 'qty' => '1,5', 'unit_price' => '1000', 'discount_pct' => '0']])
        ->assertSessionHasErrors('lines.0.qty');
    $post(lines: [['product_unit_id' => $this->bottle->id, 'qty' => '1', 'unit_price' => '1000', 'discount_pct' => '100']])
        ->assertSessionHasErrors('lines.0.discount_pct');
    $post(['lines' => []])->assertSessionHasErrors('lines');
    $post(['expected_date' => '2026-11-10'])->assertSessionHasErrors('expected_date');

    expect(PurchaseOrder::query()->count())->toBe(0);
});

test('BR-153 PO < Rp 25 jt: satu langkah purchase_orders.approve (owner); disetujui → approved', function () {
    $po = makePo();
    $this->actingAs($this->buyer)->post("/pembelian/po/{$po->id}/ajukan")->assertSessionHasNoErrors();

    $po->refresh();
    $request = ApprovalRequest::query()->with('steps')->findOrFail($po->approval_request_id);
    expect($po->status)->toBe(PurchaseOrderStatus::Submitted)
        ->and($request->document_type)->toBe('purchase_order')
        ->and($request->amount)->toBe('494502.50')
        ->and($request->steps)->toHaveCount(1)
        ->and($request->steps[0]->permission_name)->toBe('purchase_orders.approve');

    // PO yang sudah diajukan tidak bisa diubah (BR-160).
    $this->actingAs($this->buyer)->put("/pembelian/po/{$po->id}", poPayload())->assertForbidden();

    expect(poDecide($this->owner, $po)->status)->toBe(ApprovalStatus::Approved)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Approved)
        ->and($po->fresh()->approved_at)->not->toBeNull();
    $this->actingAs($this->buyer)->get("/pembelian/po/{$po->id}/ubah")->assertForbidden();
});

test('BR-153 PO ≥ Rp 25 jt: atasan langsung pengaju lalu purchase_orders.approve_high', function () {
    $po = poWorth('25000000');
    $this->actingAs($this->buyer)->post("/pembelian/po/{$po->id}/ajukan")->assertSessionHasNoErrors();
    $bossUser = $this->org->actor('supervisor', $this->boss);

    $request = ApprovalRequest::query()->with('steps')->findOrFail($po->fresh()->approval_request_id);
    expect($request->steps)->toHaveCount(2)
        ->and($request->steps[0]->resolved_employee_id)->toBe($this->boss->id)
        ->and($request->steps[1]->permission_name)->toBe('purchase_orders.approve_high');

    expect(poDecide($bossUser, $po)->status)->toBe(ApprovalStatus::Pending)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Submitted);
    expect(poDecide($this->owner, $po)->status)->toBe(ApprovalStatus::Approved)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Approved);
});

test('SEC-101 maker-checker: pembuat PO yang memegang purchase_orders.approve tidak bisa menyetujui PO-nya sendiri', function () {
    $this->buyer->givePermissionTo('purchase_orders.approve');
    $po = makePo();
    $this->actingAs($this->buyer)->post("/pembelian/po/{$po->id}/ajukan")->assertSessionHasNoErrors();

    $code = null;
    try {
        poDecide($this->buyer, $po);
    } catch (ApiError $e) {
        $code = $e->errorCode;
    }

    expect($code)->not->toBeNull()
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Submitted);
    expect(poDecide($this->owner, $po)->status)->toBe(ApprovalStatus::Approved);
});

test('BR-160 ditolak → bisa dibatalkan; tutup hanya untuk PO terbuka dan wajib alasan', function () {
    $po = makePo();
    $this->actingAs($this->buyer)->post("/pembelian/po/{$po->id}/tutup", ['reason' => 'Tidak jadi'])->assertForbidden();
    $this->actingAs($this->buyer)->post("/pembelian/po/{$po->id}/ajukan");
    $request = ApprovalRequest::query()->findOrFail($po->fresh()->approval_request_id);
    app(DecideApprovalStep::class)->reject($this->owner, $request->id, $request->current_step, 'Harga terlalu tinggi', true);
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Rejected);

    $this->actingAs($this->buyer)->post("/pembelian/po/{$po->id}/batal")->assertSessionHasNoErrors();
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled);

    $open = makePo();
    $open->forceFill(['status' => PurchaseOrderStatus::Approved])->save();
    $this->actingAs($this->buyer)->post("/pembelian/po/{$open->id}/batal")->assertForbidden();
    $this->actingAs($this->buyer)->post("/pembelian/po/{$open->id}/tutup", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->buyer)->post("/pembelian/po/{$open->id}/tutup", ['reason' => 'Supplier kehabisan stok'])->assertSessionHasNoErrors();

    $open->refresh();
    expect($open->status)->toBe(PurchaseOrderStatus::Closed)
        ->and($open->close_reason)->toBe('Supplier kehabisan stok')
        ->and($open->closed_at)->not->toBeNull();
    $this->actingAs($this->buyer)->get("/pembelian/po/{$open->id}")
        ->assertInertia(fn (Assert $page) => $page->component('purchase-orders/show')
            ->where('history.0.event_label', 'Ditutup')
            ->where('history.0.reason', 'Supplier kehabisan stok')
            ->where('can.close', false));
});

test('06 §5 kepala gudang hanya melihat PO ke gudang lokasinya; di luar scope → 404 (IDOR)', function () {
    $mine = makePo();
    $other = makePo(override: ['stock_location_id' => $this->store->id]);
    $head = $this->org->actor('kepala_gudang', $this->org->employee($this->org->gudang));

    $this->actingAs($head)->get('/pembelian/po')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $mine->id)->where('canCreate', false));
    $this->actingAs($head)->get("/pembelian/po/{$mine->id}")
        ->assertInertia(fn (Assert $page) => $page->where('order.id', $mine->id)->where('can.edit', false));

    foreach (["/pembelian/po/{$other->id}", "/pembelian/po/{$other->id}/pdf"] as $uri) {
        $this->assertOutOfScope404($head, 'GET', $uri);
    }
    // Pembuat PO (scope semua) tidak bisa memesan ke gudang yang tidak ada / nonaktif.
    $this->warehouse->forceFill(['is_active' => false])->save();
    $this->actingAs($this->buyer)->post('/pembelian/po', poPayload())->assertSessionHasErrors('stock_location_id');
});

test('06 §5 pengguna dengan izin create tetapi di luar scope tidak bisa mengubah/mengajukan PO lain (404)', function () {
    $other = makePo(override: ['stock_location_id' => $this->store->id]);
    $head = $this->org->actor('kepala_gudang', $this->org->employee($this->org->gudang));
    $head->givePermissionTo('purchase_orders.create');

    $this->assertOutOfScope404($head, 'GET', "/pembelian/po/{$other->id}/ubah");
    $this->assertOutOfScope404($head, 'PUT', "/pembelian/po/{$other->id}", poPayload());
    $this->assertOutOfScope404($head, 'POST', "/pembelian/po/{$other->id}/ajukan");
    $this->assertOutOfScope404($head, 'POST', "/pembelian/po/{$other->id}/batal");
    // Gudang tujuan di luar scope dianggap tidak ada.
    $this->actingAs($head)->post('/pembelian/po', poPayload(['stock_location_id' => $this->store->id]))->assertSessionHasErrors('stock_location_id');
    expect($other->fresh()->status)->toBe(PurchaseOrderStatus::Draft);
});

test('15 §7.3 daftar PO: filter terlambat memakai tanggal kantor; cari nomor; dasbor PO terlambat', function () {
    $late = makePo(override: ['order_date' => '2026-11-10', 'expected_date' => '2026-11-15']);
    $late->forceFill(['status' => PurchaseOrderStatus::Approved])->save();
    $draftLate = makePo(override: ['order_date' => '2026-11-10', 'expected_date' => '2026-11-15']);
    $today = makePo(override: ['expected_date' => '2026-11-16']);
    $today->forceFill(['status' => PurchaseOrderStatus::PartiallyReceived])->save();

    $this->actingAs($this->buyer)->get('/pembelian/po?filter[overdue]=1')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $late->id)->where('rows.0.overdue', true));
    $this->actingAs($this->buyer)->get('/pembelian/po?q='.$draftLate->number)
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $draftLate->id));
    $this->actingAs($this->buyer)->get('/pembelian/po?sort=grand_total&filter[status][]=draft')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1));

    $this->actingAs($this->buyer)->get('/dasbor')
        ->assertInertia(fn (Assert $page) => $page->where('pending', fn ($items) => collect($items)->firstWhere('key', 'po_overdue')['count'] === 1));
});

test('BR-160 RecalculatePurchaseOrder: sebagian → partially_received; semua terpenuhi → received', function () {
    $po = makePo();
    $po->forceFill(['status' => PurchaseOrderStatus::Approved])->save();
    $lines = $po->lines()->get();

    PurchaseOrderLine::query()->whereKey($lines[0]->id)->update(['qty_received_base' => '24']);
    expect(app(RecalculatePurchaseOrder::class)->handle($po)->status)->toBe(PurchaseOrderStatus::PartiallyReceived);

    PurchaseOrderLine::query()->whereKey($lines[0]->id)->update(['qty_received_base' => '48']);
    PurchaseOrderLine::query()->whereKey($lines[1]->id)->update(['qty_received_base' => '5']);
    expect(app(RecalculatePurchaseOrder::class)->handle($po)->status)->toBe(PurchaseOrderStatus::Received);
});

test('06 izin: tanpa purchase_orders.view → 403; halaman cetak memuat PO', function () {
    $po = makePo();
    $nobody = $this->org->actor('hrd', $this->org->employee());
    $this->actingAs($nobody)->get('/pembelian/po')->assertForbidden();

    $this->actingAs($this->buyer)->get("/pembelian/po/{$po->id}/pdf")
        ->assertInertia(fn (Assert $page) => $page->component('purchase-orders/print')->where('order.number', $po->number));
});
