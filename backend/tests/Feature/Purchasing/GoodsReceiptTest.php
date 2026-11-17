<?php

use App\Domain\Accounting\Outbox\Models\AccountingEvent;
use App\Domain\Approval\Actions\DecideApprovalStep;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductRegistration;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Purchasing\Actions\SavePurchaseOrder;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Enums\ReceiptLineStatus;
use App\Domain\Purchasing\Listings\GoodsReceiptListing;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\EmployeeWorld;
use Tests\Support\StockWorld;

/*
 * T-080 — penerimaan barang (FR-A25, BR-103, BR-158, BR-159, SEC-104, SEC-107, 15 §4.9/§5/§11.4).
 * Data fiktif. Waktu dibekukan 17 Nov 2026 03.00 UTC (10.00 WIB). Role & izin Spatie sungguhan:
 * staf gudang menerima & memeriksa, staf gudang kedua memposting (dua orang), kepala gudang menyetujui
 * sisa umur pendek (location_head).
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 03:00:00', 'UTC'));
    Notification::fake();
    $this->org = EmployeeWorld::make($this);
    $this->seed(ApprovalRuleSeeder::class);
    $this->useRealPermissions();
    setSetting('approval.reauth_amount', '999999999999');

    $headEmployee = $this->org->employee();
    $this->org->gudang->update(['head_employee_id' => $headEmployee->id]);
    $this->head = $this->org->actor('kepala_gudang', $headEmployee);
    $this->clerk = $this->org->actor('staf_gudang', $this->org->employee());
    $this->clerk2 = $this->org->actor('staf_gudang', $this->org->employee());
    $this->buyer = $this->org->actor('purchasing', $this->org->employee());

    $this->warehouse = StockWorld::stockLocation($this->org->gudang, 'GD-UJI');
    $this->store = StockWorld::stockLocation($this->org->toko, 'TK-UJI', 'store');
    $this->supplier = tap((new Supplier)->forceFill(['code' => 'SUP-1', 'name' => 'CV Pemasok Uji', 'payment_term_days' => 30, 'is_active' => true]))->save();

    $category = ProductCategory::query()->create([
        'code' => 'INS', 'name' => 'Insektisida', 'kind' => 'insecticide', 'requires_batch' => true, 'requires_expiry' => true,
        'requires_serial' => false, 'requires_registration' => true, 'default_bin_zone' => 'pesticide', 'sort_order' => 1, 'is_active' => true,
    ]);
    [$this->product, $this->bottle] = grProduct($category, 'GR-01');
    $this->box = tap((new ProductUnit)->forceFill([
        'product_id' => $this->product->id, 'unit_id' => ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('code', 'box')->value('id'),
        'factor' => '24', 'is_base' => false, 'is_default_sales' => false, 'is_default_purchase' => true, 'is_active' => true,
    ]))->save();
    (new ProductRegistration)->forceFill([
        'product_id' => $this->product->id, 'registration_no' => 'RI 01/2026', 'valid_until' => '2028-12-31', 'status' => 'active',
    ])->save();
    DB::table('supplier_products')->insert(['supplier_id' => $this->supplier->id, 'product_id' => $this->product->id, 'is_preferred' => true, 'updated_at' => now()]);

    $this->damaged = (int) ReferenceItem::query()->forGroup(ReferenceGroup::ReceiptRejectReason)->where('code', 'damaged')->value('id');
});

/** @return array{0: Product, 1: ProductUnit} */
function grProduct(ProductCategory $category, string $sku, array $attrs = []): array
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

/** PO disetujui ke gudang uji: 2 dus @240.000 diskon 10% + 5 botol @12.000. */
function grPo(?array $lines = null, PurchaseOrderStatus $status = PurchaseOrderStatus::Approved, ?int $locationId = null): PurchaseOrder
{
    $po = app(SavePurchaseOrder::class)->handle(null, [
        'supplier_id' => test()->supplier->id, 'stock_location_id' => $locationId ?? test()->warehouse->id,
        'order_date' => '2026-11-16', 'expected_date' => null, 'payment_term_days' => null, 'note' => null,
    ], $lines ?? [
        ['product_unit_id' => test()->box->id, 'qty' => '2', 'unit_price' => '240000', 'discount_pct' => '10', 'note' => null],
        ['product_unit_id' => test()->bottle->id, 'qty' => '5', 'unit_price' => '12000', 'discount_pct' => '0', 'note' => null],
    ], test()->buyer->id);
    $po->forceFill(['status' => $status])->save();

    return $po;
}

/**
 * Baris kiriman untuk semua baris PO (qty = qty PO), ED jauh, izin cocok.
 *
 * @return list<array<string, mixed>>
 */
function grLines(PurchaseOrder $po, array $override = []): array
{
    return $po->lines()->get()->map(fn ($l, $i) => [
        'purchase_order_line_id' => $l->id,
        'qty_received' => rtrim(rtrim($l->qty, '0'), '.'),
        'qty_rejected' => '',
        'reject_reason_id' => null,
        'batch_no' => 'B-'.($i + 1),
        'expiry_date' => '2028-06-30',
        'registration_no_seen' => 'ri 01/2026',
        'stock_bin_id' => null,
        ...($override[$i] ?? []),
    ])->all();
}

function grPayload(PurchaseOrder $po, array $override = [], ?array $lines = null): array
{
    return [
        'purchase_order_id' => $po->id,
        'received_at' => '2026-11-17T09:00',
        'supplier_delivery_no' => 'SJ-001',
        'vehicle_plate' => 'k 1234 ab',
        'note' => null,
        'lines' => $lines ?? grLines($po),
        ...$override,
    ];
}

function makeGr(PurchaseOrder $po, ?User $actor = null, array $override = [], ?array $lines = null): GoodsReceipt
{
    test()->actingAs($actor ?? test()->clerk)->post('/penerimaan', grPayload($po, $override, $lines))->assertSessionHasNoErrors();

    return GoodsReceipt::query()->latest('id')->firstOrFail();
}

/** Periksa (staf 1) lalu posting (staf 2). */
function grCheckAndPost(GoodsReceipt $gr): GoodsReceipt
{
    test()->actingAs(test()->clerk)->post("/penerimaan/{$gr->id}/periksa")->assertSessionHasNoErrors();
    test()->actingAs(test()->clerk2)->post("/penerimaan/{$gr->id}/posting")->assertSessionHasNoErrors();

    return $gr->fresh();
}

function grDecide(User $actor, GoodsReceipt $gr, bool $approve): void
{
    $request = ApprovalRequest::query()->findOrFail($gr->fresh()->approval_request_id);
    $approve
        ? app(DecideApprovalStep::class)->approve($actor, $request->id, $request->current_step, null, true)
        : app(DecideApprovalStep::class)->reject($actor, $request->id, $request->current_step, 'Sisa umur terlalu pendek', true);
}

test('BR-158 SEC-104 draf dari PO: nomor GR-2611-0001, biaya dari PO per satuan dasar, biaya kiriman klien ditolak', function () {
    $po = grPo();
    $gr = makeGr($po);
    $lines = $gr->lines()->get();

    expect($gr->number)->toBe('GR-2611-0001')
        ->and($gr->status)->toBe(GoodsReceiptStatus::Draft)
        ->and($gr->supplier_id)->toBe($this->supplier->id)
        ->and($gr->stock_location_id)->toBe($this->warehouse->id)
        ->and($gr->vehicle_plate)->toBe('K1234AB')
        // 240.000 × 90% / 24 = 9.000 per botol; baris kedua 12.000.
        ->and($lines[0]->unit_cost)->toBe('9000.0000')
        ->and($lines[0]->qty_received_base)->toBe('48.000')
        ->and($lines[1]->unit_cost)->toBe('12000.0000');

    $bad = grLines($po, [0 => ['unit_cost' => '1']]);
    $this->actingAs($this->clerk)->post('/penerimaan', grPayload($po, lines: $bad))->assertSessionHasErrors('lines.0.unit_cost');
    $this->actingAs($this->clerk)->post('/penerimaan', grPayload($po, ['supplier_delivery_no' => '']))->assertSessionHasErrors('supplier_delivery_no');
    $this->actingAs($this->clerk)->post('/penerimaan', grPayload($po, lines: grLines($po, [0 => ['batch_no' => '']])))->assertSessionHasErrors('lines.0.batch_no');
    $this->actingAs($this->clerk)->post('/penerimaan', grPayload($po, lines: grLines($po, [0 => ['qty_rejected' => '1']])))->assertSessionHasErrors('lines.0.reject_reason_id');
    expect(GoodsReceipt::query()->count())->toBe(1);
});

test('BR-103 ED ≤ tanggal terima → 422 BATCH_EXPIRED_ON_RECEIPT (draf maupun JSON)', function () {
    $po = grPo();
    $expired = grLines($po, [0 => ['expiry_date' => '2026-11-17']]);

    $this->actingAs($this->clerk)->post('/penerimaan', grPayload($po, lines: $expired))->assertSessionHasErrors('lines.0.expiry_date');
    $this->actingAs($this->clerk)->postJson('/penerimaan', grPayload($po, lines: $expired))
        ->assertStatus(422)->assertJsonPath('error.code', 'BATCH_EXPIRED_ON_RECEIPT');
    expect(GoodsReceipt::query()->count())->toBe(0);
});

test('BR-103 sisa umur 4 bulan (batas 6) → pending_approval; disetujui kepala gudang → posted, stok masuk', function () {
    $po = grPo();
    $gr = makeGr($po, lines: grLines($po, [0 => ['expiry_date' => '2027-03-17']]));
    $this->actingAs($this->clerk)->post("/penerimaan/{$gr->id}/periksa")->assertSessionHasNoErrors();

    $line = $gr->lines()->first();
    expect($line->line_status)->toBe(ReceiptLineStatus::ShortShelfLife)
        ->and($line->shelf_life_months)->toBe('4.0');

    $this->actingAs($this->clerk2)->post("/penerimaan/{$gr->id}/posting")->assertSessionHasNoErrors();
    $gr->refresh();
    $request = ApprovalRequest::query()->with('steps')->findOrFail($gr->approval_request_id);
    expect($gr->status)->toBe(GoodsReceiptStatus::PendingApproval)
        ->and($request->document_type)->toBe('goods_receipt_short_shelf_life')
        ->and($request->steps[0]->resolved_employee_id)->toBe($this->head->employee_id)
        ->and(StockMovement::query()->count())->toBe(0);

    grDecide($this->head, $gr, true);
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::Posted)
        ->and($gr->fresh()->posted_by)->toBe($this->clerk2->id)
        ->and(StockMovement::query()->where('source_type', 'goods_receipt')->count())->toBe(2)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Received);
});

test('BR-103 sisa umur pendek ditolak → penerimaan rejected, stok & PO tidak berubah', function () {
    $po = grPo();
    $gr = makeGr($po, lines: grLines($po, [0 => ['expiry_date' => '2027-03-17']]));
    $this->actingAs($this->clerk)->post("/penerimaan/{$gr->id}/periksa");
    $this->actingAs($this->clerk2)->post("/penerimaan/{$gr->id}/posting");

    grDecide($this->head, $gr, false);
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::Rejected)
        ->and(StockBatch::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(AccountingEvent::query()->count())->toBe(0)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Approved)
        ->and($po->lines()->sum('qty_received_base'))->toEqual(0);
});

test('BR-158 SEC-104 over-receipt di atas toleransi → 422 OVER_RECEIPT; toleransi 20% lolos; kumulatif antar GR', function () {
    $po = grPo();
    $over = grLines($po, [1 => ['qty_received' => '6']]);
    $gr = makeGr($po, lines: $over);
    $this->actingAs($this->clerk)->postJson("/penerimaan/{$gr->id}/periksa")->assertStatus(422)->assertJsonPath('error.code', 'OVER_RECEIPT');
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::Draft);

    setSetting('purchasing.over_receipt_tolerance_pct', '20');
    $this->actingAs($this->clerk)->post("/penerimaan/{$gr->id}/periksa")->assertSessionHasNoErrors();
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::Checked);
    setSetting('purchasing.over_receipt_tolerance_pct', '0');

    // Kumulatif: GR pertama terposting penuh, GR kedua 1 botol lagi → OVER_RECEIPT.
    $po2 = grPo();
    grCheckAndPost(makeGr($po2));
    expect($po2->fresh()->status)->toBe(PurchaseOrderStatus::Received);
    $po2->forceFill(['status' => PurchaseOrderStatus::PartiallyReceived])->save();
    $line = $po2->lines()->get()[1];
    $second = makeGr($po2, lines: [[...grLines($po2)[1], 'qty_received' => '1', 'purchase_order_line_id' => $line->id]]);
    $this->actingAs($this->clerk)->postJson("/penerimaan/{$second->id}/periksa")->assertStatus(422)->assertJsonPath('error.code', 'OVER_RECEIPT');
});

test('BR-158 SEC-104 PO belum disetujui → PO_NOT_APPROVED; tanpa PO ditolak saat setting mati', function () {
    foreach ([PurchaseOrderStatus::Draft, PurchaseOrderStatus::Submitted, PurchaseOrderStatus::Closed] as $status) {
        $po = grPo(status: $status);
        $this->actingAs($this->clerk)->postJson('/penerimaan', grPayload($po))->assertStatus(422)->assertJsonPath('error.code', 'PO_NOT_APPROVED');
    }

    // PO ditutup setelah draf GR dibuat → periksa juga ditolak.
    $po = grPo();
    $gr = makeGr($po);
    $po->forceFill(['status' => PurchaseOrderStatus::Closed])->save();
    $this->actingAs($this->clerk)->postJson("/penerimaan/{$gr->id}/periksa")->assertStatus(422)->assertJsonPath('error.code', 'PO_NOT_APPROVED');

    $this->actingAs($this->clerk)->post('/penerimaan', [
        ...grPayload($po), 'purchase_order_id' => null, 'supplier_id' => $this->supplier->id, 'stock_location_id' => $this->warehouse->id,
        'lines' => [['product_unit_id' => $this->bottle->id, 'qty_received' => '1', 'unit_cost' => '1000', 'batch_no' => 'X', 'expiry_date' => '2028-01-01', 'registration_no_seen' => 'RI 01/2026']],
    ])->assertSessionHasErrors('purchase_order_id');
    expect(GoodsReceipt::query()->count())->toBe(1);
});

test('BR-158(2) tanpa PO bila setting aktif & pelaku purchase_orders.create; biaya diketik', function () {
    setSetting('purchasing.allow_receipt_without_po', true);
    $this->head->givePermissionTo('purchase_orders.create');
    $payload = [
        'purchase_order_id' => null, 'supplier_id' => $this->supplier->id, 'stock_location_id' => $this->warehouse->id,
        'received_at' => '2026-11-17T09:00', 'supplier_delivery_no' => 'SJ-9',
        'lines' => [['product_unit_id' => $this->bottle->id, 'qty_received' => '3', 'unit_cost' => '11.500', 'batch_no' => 'X', 'expiry_date' => '2028-01-01', 'registration_no_seen' => 'RI 01/2026']],
    ];
    $this->actingAs($this->clerk)->post('/penerimaan', $payload)->assertSessionHasErrors('purchase_order_id');
    $this->actingAs($this->head)->post('/penerimaan', $payload)->assertSessionHasNoErrors();

    expect(GoodsReceipt::query()->sole()->lines()->sole()->unit_cost)->toBe('11500.0000');
});

test('SEC-104 pemeriksa = pemosting saat receipt_two_person → 403 RECEIPT_SAME_PERSON; setting mati lolos', function () {
    $gr = makeGr(grPo());
    $this->actingAs($this->clerk)->post("/penerimaan/{$gr->id}/periksa")->assertSessionHasNoErrors();

    $this->actingAs($this->clerk)->postJson("/penerimaan/{$gr->id}/posting")->assertStatus(403)->assertJsonPath('error.code', 'RECEIPT_SAME_PERSON');
    $this->actingAs($this->clerk)->get("/penerimaan/{$gr->id}")
        ->assertInertia(fn (Assert $page) => $page->where('can.post', true)->where('can.post_blocked_same_person', true));
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::Checked);

    setSetting('purchasing.receipt_two_person', false);
    $this->actingAs($this->clerk)->post("/penerimaan/{$gr->id}/posting")->assertSessionHasNoErrors();
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::Posted);
});

test('BR-158(6) posting dua kali → satu posting + 409 STATE_CONFLICT, mutasi tidak ganda', function () {
    $gr = makeGr(grPo());
    $this->actingAs($this->clerk)->post("/penerimaan/{$gr->id}/periksa");
    $this->actingAs($this->clerk2)->postJson("/penerimaan/{$gr->id}/posting")->assertRedirect();
    $this->actingAs($this->clerk2)->postJson("/penerimaan/{$gr->id}/posting")->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');

    expect(StockMovement::query()->count())->toBe(2)
        ->and(AccountingEvent::query()->count())->toBe(1);
});

test('BR-158 BR-159 posting: batch + mutasi receipt + HPP rata-rata dua biaya; PO sebagian → diterima; last_price (UAT-A10)', function () {
    $po = grPo();
    $first = makeGr($po, lines: [grLines($po, [0 => ['qty_received' => '1']])[0]]);
    grCheckAndPost($first);

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and(ProductCost::query()->find($this->product->id)->avg_cost)->toBe('9000.0000');
    $batch = StockBatch::query()->where('batch_no', 'B-1')->sole();
    expect($batch->status)->toBe(StockBatchStatus::Available)
        ->and($batch->qty_on_hand)->toBe('24.000')
        ->and($batch->expiry_date->toDateString())->toBe('2028-06-30')
        ->and($first->lines()->first()->stock_batch_id)->toBe($batch->id)
        ->and(StockMovement::query()->sole()->movement_type->value)->toBe('receipt');
    $sp = DB::table('supplier_products')->where('supplier_id', $this->supplier->id)->where('product_id', $this->product->id)->first();
    expect($sp->last_price)->toBe('240000.00')->and((int) $sp->last_price_unit_id)->toBe($this->box->id);

    // Sisa: 1 dus @9.000 + 5 botol @12.000 → HPP (24×9000 + 24×9000 + 5×12000) / 53.
    $second = makeGr($po, lines: grLines($po, [0 => ['qty_received' => '1']]));
    grCheckAndPost($second);
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and(ProductCost::query()->find($this->product->id)->avg_cost)->toBe('9283.0189')
        ->and(StockBatch::query()->where('batch_no', 'B-1')->sole()->qty_on_hand)->toBe('48.000');
});

test('BR-158(4) nomor izin tidak cocok → baris ke Q-01, batch quarantine, ikut event inventory.goods_received yang sama', function () {
    $po = grPo();
    $gr = makeGr($po, lines: grLines($po, [1 => ['registration_no_seen' => 'RI 99/2020']]));
    grCheckAndPost($gr);

    $lines = $gr->lines()->get();
    expect($lines[0]->line_status)->toBe(ReceiptLineStatus::Ok)
        ->and($lines[1]->line_status)->toBe(ReceiptLineStatus::RegistrationMismatch);
    $quarantined = StockBatch::query()->findOrFail($lines[1]->stock_batch_id);
    expect($quarantined->status)->toBe(StockBatchStatus::Quarantine)
        ->and($quarantined->stock_bin_id)->toBe(StockBin::query()->where('stock_location_id', $this->warehouse->id)->where('code', 'Q-01')->value('id'))
        ->and(AccountingEvent::query()->count())->toBe(1)
        ->and(AccountingEvent::query()->sole()->payload['lines'])->toHaveCount(4);
});

test('15 §11.4 outbox: D inventory = K GRNI = Σ qty × biaya, dims per baris, refs.supplier_id; lock date → PERIOD_LOCKED tanpa mutasi', function () {
    $po = grPo();
    $gr = makeGr($po);
    grCheckAndPost($gr);

    $event = AccountingEvent::query()->sole();
    $lines = $event->payload['lines'];
    $debit = collect($lines)->where('role', 'inventory')->sum(fn ($l) => (float) $l['debit']);
    $credit = collect($lines)->where('role', 'goods_received_not_invoiced')->sum(fn ($l) => (float) $l['credit']);
    expect($event->event_code)->toBe('inventory.goods_received')
        ->and($event->business_date->toDateString())->toBe('2026-11-17')
        ->and($lines[0])->toBe(['role' => 'inventory', 'debit' => '432000.00', 'stock_location_id' => $this->warehouse->id, 'product_id' => $this->product->id])
        ->and($lines[3]['credit'])->toBe('60000.00')
        ->and($debit)->toBe(492000.0)->and($credit)->toBe(492000.0)
        ->and($event->payload['refs'])->toBe(['supplier_id' => $this->supplier->id]);

    setSetting('accounting.lock_date', '2026-11-17');
    $locked = makeGr(grPo());
    $this->actingAs($this->clerk)->post("/penerimaan/{$locked->id}/periksa");
    $this->actingAs($this->clerk2)->postJson("/penerimaan/{$locked->id}/posting")->assertStatus(422)->assertJsonPath('error.code', 'PERIOD_LOCKED');
    expect($locked->fresh()->status)->toBe(GoodsReceiptStatus::Checked)
        ->and(StockMovement::query()->count())->toBe(2)
        ->and(AccountingEvent::query()->count())->toBe(1);
});

test('BR-158(7) barang ditolak tidak masuk stok; baris ditolak seluruhnya tanpa mutasi', function () {
    $po = grPo();
    $gr = makeGr($po, lines: grLines($po, [
        0 => ['qty_received' => '1', 'qty_rejected' => '1', 'reject_reason_id' => $this->damaged],
        1 => ['qty_received' => '0', 'qty_rejected' => '5', 'reject_reason_id' => $this->damaged],
    ]));
    grCheckAndPost($gr);

    $lines = $gr->lines()->get();
    expect($lines[0]->qty_rejected_base)->toBe('24.000')
        ->and(StockMovement::query()->count())->toBe(1)
        ->and(StockBatch::query()->sum('qty_on_hand'))->toEqual(24)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);
});

test('SEC-107 harga beli: staf gudang tidak menerima unit_cost di props; kepala gudang (purchase_orders.view) menerima', function () {
    $gr = makeGr(grPo());

    $this->actingAs($this->clerk)->get("/penerimaan/{$gr->id}")
        ->assertInertia(fn (Assert $page) => $page->component('goods-receipts/show')
            ->where('receipt.show_cost', false)->where('receipt.total_value', null)->missing('receipt.lines.0.unit_cost')->missing('receipt.lines.0.line_value'));
    $this->actingAs($this->head)->get("/penerimaan/{$gr->id}")
        ->assertInertia(fn (Assert $page) => $page->where('receipt.show_cost', true)->where('receipt.lines.0.unit_cost', '9000.0000')->where('receipt.total_value', '492000.00'));
    $this->actingAs($this->clerk)->get("/penerimaan/baru?po={$gr->purchase_order_id}")
        ->assertInertia(fn (Assert $page) => $page->component('goods-receipts/form')->where('order.lines.0.unit_cost', null));
});

test('06 §5 IDOR: penerimaan lokasi lain → 404 (show/ubah/periksa/posting/seri/form dari PO)', function () {
    $poStore = grPo(locationId: $this->store->id);
    $other = makeGr($poStore, $this->org->actor('owner'));
    $line = $other->lines()->first();

    foreach ([['GET', "/penerimaan/{$other->id}"], ['GET', "/penerimaan/{$other->id}/ubah"], ['POST', "/penerimaan/{$other->id}/periksa"],
        ['POST', "/penerimaan/{$other->id}/posting"], ['PUT', "/penerimaan/{$other->id}/baris/{$line->id}/seri"], ['GET', "/penerimaan/baru?po={$poStore->id}"]] as [$method, $uri]) {
        $this->assertOutOfScope404($this->clerk, $method, $uri, $method === 'PUT' ? ['serials' => [], 'method' => 'scan'] : []);
    }
    $this->actingAs($this->clerk)->post('/penerimaan', grPayload($poStore))->assertNotFound();
    $this->actingAs($this->clerk)->get('/penerimaan')->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    expect($other->fresh()->status)->toBe(GoodsReceiptStatus::Draft);
});

test('15 §7.3 daftar penerimaan: cari nomor SJ & nomor PO, filter status, received_at hari lokal, sort; ekspor tanpa biaya', function () {
    $po = grPo();
    $a = makeGr($po, override: ['supplier_delivery_no' => 'SJ-ALFA']);
    // 16 Nov 23.30 WIB = 16.30 UTC: masuk filter tanggal lokal 16 Nov, bukan 17 Nov.
    $b = makeGr($po, override: ['supplier_delivery_no' => 'SJ-BETA', 'received_at' => '2026-11-16T23:30']);
    grCheckAndPost($a);

    $this->actingAs($this->head)->get('/penerimaan?q=SJ-BET')->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $b->id));
    $this->actingAs($this->head)->get('/penerimaan?q='.$po->number)->assertInertia(fn (Assert $page) => $page->has('rows', 2));
    $this->actingAs($this->head)->get('/penerimaan?filter[status][]=posted')->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $a->id));
    $this->actingAs($this->head)->get('/penerimaan?filter[received_at][from]=2026-11-16&filter[received_at][to]=2026-11-16')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $b->id));
    $this->actingAs($this->head)->get('/penerimaan')->assertInertia(fn (Assert $page) => $page->where('rows.0.id', $a->id)->where('canCreate', true));

    $headers = (new GoodsReceiptListing)->exportColumns()->headers();
    expect($headers)->not->toContain('Biaya')->and(implode(' ', $headers))->not->toContain('Harga');
});

test('15 §5 detail PO menampilkan penerimaan & tombol Terima Barang; staf gudang tanpa purchase_orders.view → 403 daftar PO', function () {
    $po = grPo();
    $gr = makeGr($po);

    $this->actingAs($this->head)->get("/pembelian/po/{$po->id}")
        ->assertInertia(fn (Assert $page) => $page->where('can.receive', true)->where('receipts.0.number', $gr->number)
            ->where('receipts.0.url', "/penerimaan/{$gr->id}")->where('order.lines.0.base_unit', 'Botol'));
    $this->actingAs($this->buyer)->get("/pembelian/po/{$po->id}")->assertInertia(fn (Assert $page) => $page->where('can.receive', false));
    $this->actingAs($this->buyer)->get('/penerimaan/baru')->assertForbidden();
});

test('ubah draf yang sudah diperiksa mengembalikannya ke draf; penerimaan terposting tidak bisa diubah', function () {
    $po = grPo();
    $gr = makeGr($po);
    $this->actingAs($this->clerk)->post("/penerimaan/{$gr->id}/periksa");
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::Checked);

    $lines = grLines($po, [0 => ['id' => $gr->lines()->first()->id, 'batch_no' => 'B-UBAH']]);
    $this->actingAs($this->clerk)->put("/penerimaan/{$gr->id}", grPayload($po, lines: $lines))->assertSessionHasNoErrors();
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::Draft)
        ->and($gr->fresh()->checked_by)->toBeNull()
        ->and($gr->lines()->first()->batch_no)->toBe('B-UBAH');

    grCheckAndPost($gr);
    $this->actingAs($this->clerk)->put("/penerimaan/{$gr->id}", grPayload($po))->assertForbidden();
});
