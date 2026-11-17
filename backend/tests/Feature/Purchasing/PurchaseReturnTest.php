<?php

use App\Domain\Accounting\Outbox\Models\AccountingEvent;
use App\Domain\Approval\Actions\DecideApprovalStep;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Enums\SerialStatus;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\SerialNumber;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Purchasing\Enums\PurchaseReturnStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\EmployeeWorld;
use Tests\Support\StockWorld;

/*
 * T-081 — retur ke prinsipal (FR-A26, BR-155, BR-159, SEC-107, 15 §4.9/§5/§11.4, PA-68). Data fiktif.
 * Waktu dibekukan 17 Nov 2026 03.00 UTC (10.00 WIB). Purchasing membuat & mengirim, owner menyetujui
 * (`purchase_returns.approve`).
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 03:00:00', 'UTC'));
    Notification::fake();
    $this->org = EmployeeWorld::make($this);
    $this->seed(ApprovalRuleSeeder::class);
    $this->useRealPermissions();
    setSetting('approval.reauth_amount', '999999999999');

    $this->buyer = $this->org->actor('purchasing', $this->org->employee());
    $this->owner = $this->org->actor('owner');
    $this->warehouse = StockWorld::stockLocation($this->org->gudang, 'GD-UJI');
    $this->store = StockWorld::stockLocation($this->org->toko, 'TK-UJI', 'store');
    $this->supplier = tap((new Supplier)->forceFill(['code' => 'SUP-1', 'name' => 'PT Prinsipal Uji', 'payment_term_days' => 30, 'is_active' => true]))->save();

    $this->category = ProductCategory::query()->create([
        'code' => 'INS', 'name' => 'Insektisida', 'kind' => 'insecticide', 'requires_batch' => true, 'requires_expiry' => true,
        'requires_serial' => false, 'requires_registration' => false, 'default_bin_zone' => 'pesticide', 'sort_order' => 1, 'is_active' => true,
    ]);
    $this->product = rtbProduct('RTB-01');
    $this->reason = (int) ReferenceItem::query()->forGroup(ReferenceGroup::PurchaseReturnReason)->where('code', 'damaged')->value('id');

    // Dua batch beda biaya → HPP rata-rata 1.500.
    $this->batchA = rtbStock($this->warehouse, $this->product, '10', '1000', 'A-1', '2028-01-31');
    $this->batchB = rtbStock($this->warehouse, $this->product, '10', '2000', 'B-1', '2028-02-28');
});

function rtbProduct(string $sku, array $attrs = []): Product
{
    $unit = ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('code', 'bottle')->value('id');
    $p = (new Product)->forceFill([
        'sku' => $sku, 'name' => "Produk {$sku}", 'product_category_id' => test()->category->id, 'base_unit_id' => $unit,
        'tracks_batch' => true, 'tracks_expiry' => true, 'tracks_serial' => false, 'requires_registration' => false,
        'is_restricted' => false, 'is_sellable' => true, 'is_purchasable' => true, 'status' => 'active', ...$attrs,
    ]);
    $p->save();
    (new ProductUnit)->forceFill(['product_id' => $p->id, 'unit_id' => $unit, 'factor' => '1', 'is_base' => true, 'is_default_sales' => true, 'is_default_purchase' => true, 'is_active' => true])->save();

    return $p;
}

function rtbStock(StockLocation $location, Product $product, string $qty, string $cost, string $batchNo, ?string $expiry = null, ?int $binId = null): StockBatch
{
    return StockLedger::transaction(fn () => app(StockLedger::class)->receive($location, $product, new BatchKey($batchNo, $expiry, $binId), Qty::of($qty), UnitCost::of($cost),
        new Source('opening_balance', random_int(1, 999_999)), '2026-11-10', MovementType::OpeningBalance)->batch());
}

/** @param  list<array{0: StockBatch, 1: string}>  $lines */
function rtbPayload(array $lines, array $override = []): array
{
    return [
        'supplier_id' => test()->supplier->id,
        'stock_location_id' => test()->warehouse->id,
        'reason_id' => test()->reason,
        'goods_receipt_id' => null,
        'note' => null,
        'lines' => array_map(fn (array $l) => ['stock_batch_id' => $l[0]->id, 'qty_base' => $l[1]], $lines),
        ...$override,
    ];
}

function makeRtb(array $lines, ?User $actor = null, array $override = []): PurchaseReturn
{
    test()->actingAs($actor ?? test()->buyer)->post('/pembelian/retur', rtbPayload($lines, $override))->assertSessionHasNoErrors();

    return PurchaseReturn::query()->latest('id')->firstOrFail();
}

function approveRtb(PurchaseReturn $ret, bool $approve = true): PurchaseReturn
{
    test()->actingAs(test()->buyer)->post("/pembelian/retur/{$ret->id}/ajukan")->assertSessionHasNoErrors();
    $request = ApprovalRequest::query()->findOrFail($ret->fresh()->approval_request_id);
    $approve
        ? app(DecideApprovalStep::class)->approve(test()->owner, $request->id, $request->current_step, null, true)
        : app(DecideApprovalStep::class)->reject(test()->owner, $request->id, $request->current_step, 'Tidak perlu diretur', true);

    return $ret->fresh();
}

test('BR-159 draf: nomor RTB-2611-0001, biaya baris = biaya beli batch, total dihitung server; biaya kiriman klien diabaikan', function () {
    $ret = makeRtb([[$this->batchA, '4'], [$this->batchB, '1,5']], override: ['total_cost' => '1']);
    $lines = $ret->lines()->get();

    expect($ret->number)->toBe('RTB-2611-0001')
        ->and($ret->status)->toBe(PurchaseReturnStatus::Draft)
        ->and($lines[0]->unit_cost)->toBe('1000.0000')
        ->and($lines[1]->qty_base)->toBe('1.500')
        ->and($lines[1]->unit_cost)->toBe('2000.0000')
        ->and($ret->total_cost)->toBe('7000.00');
});

test('BR-155 qty retur > tersedia batch → INSUFFICIENT_STOCK; batch lokasi lain & batch dimusnahkan ditolak', function () {
    $this->actingAs($this->buyer)->postJson('/pembelian/retur', rtbPayload([[$this->batchA, '11']]))
        ->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK')->assertJsonPath('error.details.stock_batch_id', $this->batchA->id);

    $elsewhere = rtbStock($this->store, $this->product, '3', '1000', 'S-1', '2028-01-31');
    $this->actingAs($this->buyer)->post('/pembelian/retur', rtbPayload([[$elsewhere, '1']]))->assertSessionHasErrors('lines.0.stock_batch_id');

    $gone = rtbStock($this->warehouse, $this->product, '1', '1000', 'X-1', '2026-01-01');
    StockLedger::transaction(function () use ($gone) {
        $ledger = app(StockLedger::class);
        $ledger->setStatus($gone, StockBatchStatus::Expired);
        $ledger->issue($gone->fresh(), Qty::of(1), new Source('disposal_report', 1, 1), MovementType::Disposal, '2026-11-17');
        $ledger->setStatus($gone->fresh(), StockBatchStatus::Disposed);
    });
    $this->actingAs($this->buyer)->post('/pembelian/retur', rtbPayload([[$gone, '1']]))->assertSessionHasErrors('lines.0.stock_batch_id');
    expect(PurchaseReturn::query()->count())->toBe(0);
});

test('BR-155 disetujui → reservasi aktif; ditolak → tanpa reservasi; dibatalkan setelah disetujui → reservasi dilepas', function () {
    $ret = approveRtb(makeRtb([[$this->batchA, '4']]));
    $line = $ret->lines()->first();
    $request = ApprovalRequest::query()->findOrFail($ret->approval_request_id);

    expect($ret->status)->toBe(PurchaseReturnStatus::Approved)
        ->and($request->document_type)->toBe('purchase_return')
        ->and($request->amount)->toBe('6000.00')
        ->and($line->reservation_id)->not->toBeNull()
        ->and(StockReservation::query()->find($line->reservation_id)->status)->toBe(ReservationStatus::Active)
        ->and($this->batchA->fresh()->qty_reserved)->toBe('4.000');

    $this->actingAs($this->buyer)->post("/pembelian/retur/{$ret->id}/batal", ['reason' => 'Prinsipal menolak retur'])->assertSessionHasNoErrors();
    expect($ret->fresh()->status)->toBe(PurchaseReturnStatus::Cancelled)
        ->and(StockReservation::query()->find($line->reservation_id)->status)->toBe(ReservationStatus::Released)
        ->and($this->batchA->fresh()->qty_reserved)->toBe('0.000');

    $rejected = approveRtb(makeRtb([[$this->batchB, '2']]), approve: false);
    expect($rejected->status)->toBe(PurchaseReturnStatus::Rejected)
        ->and(StockReservation::query()->count())->toBe(1)
        ->and($this->batchB->fresh()->qty_reserved)->toBe('0.000');
    $this->actingAs($this->buyer)->post("/pembelian/retur/{$rejected->id}/batal")->assertSessionHasNoErrors();
    expect($rejected->fresh()->status)->toBe(PurchaseReturnStatus::Cancelled);
});

test('15 §11.4 kirim: PeriodGuard, mutasi purchase_return, outbox D = K dengan selisih biaya ke inventory_variance dua arah, tanpa inventory.adjusted', function () {
    $ret = approveRtb(makeRtb([[$this->batchA, '4'], [$this->batchB, '4']]));
    $this->actingAs($this->buyer)->post("/pembelian/retur/{$ret->id}/kirim")->assertSessionHasNoErrors();

    expect($ret->fresh()->status)->toBe(PurchaseReturnStatus::Shipped)
        ->and($this->batchA->fresh()->qty_on_hand)->toBe('6.000')
        ->and($this->batchA->fresh()->qty_reserved)->toBe('0.000')
        ->and(StockMovement::query()->where('source_type', 'purchase_return')->where('movement_type', 'purchase_return')->count())->toBe(2);

    $event = AccountingEvent::query()->where('event_code', 'inventory.purchase_returned')->sole();
    $dims = ['stock_location_id' => $this->warehouse->id, 'product_id' => $this->product->id];
    expect($event->business_date->toDateString())->toBe('2026-11-17')
        ->and($event->payload['refs'])->toBe(['supplier_id' => $this->supplier->id])
        ->and($event->payload['lines'])->toBe([
            // Batch A: biaya 1.000 < HPP 1.500 → selisih di debit.
            ['role' => 'goods_received_not_invoiced', 'debit' => '4000.00', ...$dims],
            ['role' => 'inventory_variance', 'debit' => '2000.00', ...$dims],
            ['role' => 'inventory', 'credit' => '6000.00', ...$dims],
            // Batch B: biaya 2.000 > HPP 1.500 → selisih di kredit.
            ['role' => 'goods_received_not_invoiced', 'debit' => '8000.00', ...$dims],
            ['role' => 'inventory', 'credit' => '6000.00', ...$dims],
            ['role' => 'inventory_variance', 'credit' => '2000.00', ...$dims],
        ])
        ->and(AccountingEvent::query()->where('event_code', 'inventory.adjusted')->count())->toBe(0);

    $debit = collect($event->payload['lines'])->sum(fn ($l) => (float) ($l['debit'] ?? 0));
    $credit = collect($event->payload['lines'])->sum(fn ($l) => (float) ($l['credit'] ?? 0));
    expect($debit)->toBe($credit);

    // Klik kirim ganda → 409, tanpa mutasi tambahan.
    $this->actingAs($this->buyer)->postJson("/pembelian/retur/{$ret->id}/kirim")->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');
    expect(StockMovement::query()->where('source_type', 'purchase_return')->count())->toBe(2);
});

test('ADR-115 tanggal kirim ≤ lock date → PERIOD_LOCKED; stok, reservasi, event tidak berubah', function () {
    $ret = approveRtb(makeRtb([[$this->batchA, '2']]));
    setSetting('accounting.lock_date', '2026-11-17');

    $this->actingAs($this->buyer)->postJson("/pembelian/retur/{$ret->id}/kirim")->assertStatus(422)->assertJsonPath('error.code', 'PERIOD_LOCKED');
    expect($ret->fresh()->status)->toBe(PurchaseReturnStatus::Approved)
        ->and($this->batchA->fresh()->qty_on_hand)->toBe('10.000')
        ->and($this->batchA->fresh()->qty_reserved)->toBe('2.000')
        ->and(AccountingEvent::query()->where('event_code', 'inventory.purchase_returned')->count())->toBe(0);
});

test('BR-163 PA-68 batch karantina & kedaluwarsa bisa diretur sampai terkirim', function () {
    $q01 = StockBin::query()->where('stock_location_id', $this->warehouse->id)->where('code', StockBin::QUARANTINE_CODE)->value('id');
    $quarantine = rtbStock($this->warehouse, $this->product, '3', '1500', 'Q-1', '2027-12-31', (int) $q01);
    $expired = rtbStock($this->warehouse, $this->product, '2', '1500', 'E-1', '2026-10-01');
    StockLedger::transaction(fn () => app(StockLedger::class)->setStatus($expired, StockBatchStatus::Expired));
    expect($quarantine->status)->toBe(StockBatchStatus::Quarantine);

    $ret = approveRtb(makeRtb([[$quarantine, '3'], [$expired->fresh(), '2']]));
    expect($ret->status)->toBe(PurchaseReturnStatus::Approved);
    $this->actingAs($this->buyer)->post("/pembelian/retur/{$ret->id}/kirim")->assertSessionHasNoErrors();

    expect($quarantine->fresh()->qty_on_hand)->toBe('0.000')
        ->and($expired->fresh()->qty_on_hand)->toBe('0.000')
        ->and($ret->fresh()->status)->toBe(PurchaseReturnStatus::Shipped);
});

test('PA-68 reservasi kedaluwarsa sebelum kirim → stok diambil langsung; stok sudah habis → INSUFFICIENT_STOCK', function () {
    setSetting('inventory.reservation_ttl_hours', 1);
    $ret = approveRtb(makeRtb([[$this->batchA, '4']]));
    $this->travel(2)->hours();
    $this->artisan('inventory:release-reservations')->assertSuccessful();
    expect($this->batchA->fresh()->qty_reserved)->toBe('0.000');

    // Stok batch A diambil dokumen lain sampai tinggal 3.
    StockLedger::transaction(fn () => app(StockLedger::class)->issue($this->batchA->fresh(), Qty::of(7), new Source('sales_invoice', 1, 1), MovementType::Sale, '2026-11-17'));
    $this->actingAs($this->buyer)->postJson("/pembelian/retur/{$ret->id}/kirim")->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

    $ok = approveRtb(makeRtb([[$this->batchB, '2']]));
    StockReservation::query()->whereKey($ok->lines()->value('reservation_id'))->update(['expires_at' => now()->subMinute()]);
    $this->artisan('inventory:release-reservations')->assertSuccessful();
    $this->actingAs($this->buyer)->post("/pembelian/retur/{$ok->id}/kirim")->assertSessionHasNoErrors();
    expect($this->batchB->fresh()->qty_on_hand)->toBe('8.000')->and($ok->fresh()->status)->toBe(PurchaseReturnStatus::Shipped);
});

test('FR-A26 tutup wajib catatan konfirmasi; transisi tidak sah ditolak', function () {
    $ret = makeRtb([[$this->batchA, '1']]);
    $this->actingAs($this->buyer)->post("/pembelian/retur/{$ret->id}/tutup", ['confirmation' => 'x'])->assertForbidden();
    $this->actingAs($this->buyer)->postJson("/pembelian/retur/{$ret->id}/kirim")->assertStatus(409);

    approveRtb($ret);
    $this->actingAs($this->buyer)->post("/pembelian/retur/{$ret->id}/batal")->assertSessionHasNoErrors();
    $this->actingAs($this->buyer)->post("/pembelian/retur/{$ret->id}/batal")->assertForbidden();

    $shipped = approveRtb(makeRtb([[$this->batchB, '1']]));
    $this->actingAs($this->buyer)->post("/pembelian/retur/{$shipped->id}/kirim")->assertSessionHasNoErrors();
    $this->actingAs($this->buyer)->post("/pembelian/retur/{$shipped->id}/batal")->assertForbidden();
    $this->actingAs($this->buyer)->post("/pembelian/retur/{$shipped->id}/tutup", ['confirmation' => ''])->assertSessionHasErrors('confirmation');
    $this->actingAs($this->buyer)->post("/pembelian/retur/{$shipped->id}/tutup", ['confirmation' => 'Nota kredit prinsipal no. NK-77 diterima'])->assertSessionHasNoErrors();

    expect($shipped->fresh()->status)->toBe(PurchaseReturnStatus::Closed)->and($shipped->fresh()->closed_at)->not->toBeNull();
    $this->actingAs($this->buyer)->get("/pembelian/retur/{$shipped->id}")
        ->assertInertia(fn (Assert $page) => $page->component('purchase-returns/show')
            ->where('history.0.event_label', 'Dikonfirmasi prinsipal (ditutup)')
            ->where('history.0.note', 'Nota kredit prinsipal no. NK-77 diterima'));
});

test('PA-68 produk bernomor seri: kirim wajib memilih nomor in_stock sejumlah qty; terkirim → returned_to_principal', function () {
    $tool = rtbProduct('ALT-01', ['tracks_serial' => true, 'tracks_expiry' => false]);
    $batch = rtbStock($this->warehouse, $tool, '3', '500000', 'SPR-B');
    foreach (['SPR-1', 'SPR-2', 'SPR-3'] as $no) {
        (new SerialNumber)->forceFill(['product_id' => $tool->id, 'serial_no' => $no, 'stock_batch_id' => $batch->id, 'status' => 'in_stock', 'input_method' => 'scan'])->save();
    }
    $this->actingAs($this->buyer)->post('/pembelian/retur', rtbPayload([[$batch, '1.5']]))->assertSessionHasErrors('lines.0.qty_base');
    $ret = approveRtb(makeRtb([[$batch, '2']]));
    $lineId = $ret->lines()->value('id');

    $this->actingAs($this->buyer)->get("/pembelian/retur/{$ret->id}")
        ->assertInertia(fn (Assert $page) => $page->where('purchaseReturn.lines.0.serial_options', ['SPR-1', 'SPR-2', 'SPR-3']));
    $this->actingAs($this->buyer)->post("/pembelian/retur/{$ret->id}/kirim", ['serials' => [$lineId => ['SPR-1']]])->assertSessionHasErrors("serials.{$lineId}");
    $this->actingAs($this->buyer)->post("/pembelian/retur/{$ret->id}/kirim", ['serials' => [$lineId => ['SPR-1', 'NOPE']]])->assertSessionHasErrors("serials.{$lineId}");
    $this->actingAs($this->buyer)->post("/pembelian/retur/{$ret->id}/kirim", ['serials' => [$lineId => ['spr-1', 'SPR-3']]])->assertSessionHasNoErrors();

    expect(SerialNumber::query()->where('status', SerialStatus::ReturnedToPrincipal)->orderBy('serial_no')->pluck('serial_no')->all())->toBe(['SPR-1', 'SPR-3'])
        ->and(SerialNumber::query()->where('serial_no', 'SPR-2')->value('status'))->toBe(SerialStatus::InStock);
});

test('06 §5 IDOR: retur lokasi lain → 404; SEC-107 biaya hanya untuk pemegang izin harga beli', function () {
    $ret = makeRtb([[$this->batchA, '1']]);
    $storeHead = $this->org->actor('kepala_gudang', $this->org->employee($this->org->toko));
    foreach ([['GET', "/pembelian/retur/{$ret->id}"], ['GET', "/pembelian/retur/{$ret->id}/ubah"], ['PUT', "/pembelian/retur/{$ret->id}"],
        ['POST', "/pembelian/retur/{$ret->id}/ajukan"], ['POST', "/pembelian/retur/{$ret->id}/kirim"], ['POST', "/pembelian/retur/{$ret->id}/tutup"], ['POST', "/pembelian/retur/{$ret->id}/batal"]] as [$method, $uri]) {
        // Payload sah dari sudut pelaku (lokasi dalam scope-nya), supaya yang diuji memang scope dokumen.
        $this->assertOutOfScope404($storeHead, $method, $uri, $method === 'PUT' ? rtbPayload([[$this->batchA, '1']], ['stock_location_id' => $this->store->id]) : []);
    }
    $this->actingAs($storeHead)->get('/pembelian/retur')->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    $this->actingAs($storeHead)->post('/pembelian/retur', rtbPayload([[$this->batchA, '1']]))->assertSessionHasErrors('stock_location_id');

    $clerk = $this->org->actor('staf_gudang', $this->org->employee());
    $clerk->givePermissionTo('purchase_returns.manage');
    $this->actingAs($clerk)->get("/pembelian/retur/{$ret->id}")
        ->assertInertia(fn (Assert $page) => $page->where('purchaseReturn.show_cost', false)->where('purchaseReturn.total_cost', null)->missing('purchaseReturn.lines.0.unit_cost'));
    $this->actingAs($this->buyer)->get("/pembelian/retur/{$ret->id}")
        ->assertInertia(fn (Assert $page) => $page->where('purchaseReturn.total_cost', '1000.00')->where('purchaseReturn.lines.0.unit_cost', '1000.0000'));
    $this->actingAs($this->org->actor('staf_gudang', $this->org->employee()))->get('/pembelian/retur')->assertForbidden();
});

test('15 §7.3 daftar retur: cari nomor, filter status & supplier, sort; lookup returnable_batches memuat karantina/ED', function () {
    $draft = makeRtb([[$this->batchA, '1']]);
    $approved = approveRtb(makeRtb([[$this->batchB, '1']]));

    $this->actingAs($this->buyer)->get('/pembelian/retur?q='.$approved->number)->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $approved->id));
    $this->actingAs($this->buyer)->get('/pembelian/retur?filter[status][]=draft')->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $draft->id));
    $this->actingAs($this->buyer)->get("/pembelian/retur?filter[supplier_id]={$this->supplier->id}&sort=number")->assertInertia(fn (Assert $page) => $page->has('rows', 2)->where('rows.0.id', $draft->id));

    $expired = rtbStock($this->warehouse, $this->product, '2', '1500', 'E-9', '2026-10-01');
    StockLedger::transaction(fn () => app(StockLedger::class)->setStatus($expired, StockBatchStatus::Expired));
    $options = $this->actingAs($this->buyer)->getJson("/lookup/returnable_batches?product_id={$this->product->id}&stock_location_id={$this->warehouse->id}")->assertOk()->json();
    // Urut ED terdekat: batch kedaluwarsa paling atas.
    expect(collect($options)->pluck('label')->all())->toBe(['E-9', 'A-1', 'B-1'])
        ->and(collect($options)->firstWhere('label', 'E-9')['status'])->toBe('expired');
    $this->actingAs($this->org->actor('staf_gudang', $this->org->employee()))
        ->getJson("/lookup/returnable_batches?product_id={$this->product->id}&stock_location_id={$this->warehouse->id}")->assertForbidden();
});

test('FR-A26 retur dari penerimaan terposting (?gr=): supplier, lokasi, dan batch tersedia terisi', function () {
    $gr = tap((new GoodsReceipt)->forceFill([
        'number' => 'GR-2611-0009', 'supplier_id' => $this->supplier->id, 'stock_location_id' => $this->warehouse->id,
        'received_at' => now(), 'supplier_delivery_no' => 'SJ-9', 'status' => 'posted',
    ]))->save();
    (new GoodsReceiptLine)->forceFill([
        'goods_receipt_id' => $gr->id, 'product_id' => $this->product->id, 'product_unit_id' => ProductUnit::query()->where('product_id', $this->product->id)->value('id'),
        'qty_received' => '10', 'qty_received_base' => '10', 'batch_no' => 'A-1', 'unit_cost' => '1000', 'line_status' => 'ok', 'stock_batch_id' => $this->batchA->id,
    ])->save();

    $this->actingAs($this->buyer)->get("/penerimaan/{$gr->id}")->assertInertia(fn (Assert $page) => $page->where('can.return', true));
    $this->actingAs($this->buyer)->get("/pembelian/retur/baru?gr={$gr->id}")
        ->assertInertia(fn (Assert $page) => $page->component('purchase-returns/form')
            ->where('prefill.goods_receipt.number', 'GR-2611-0009')
            ->where('prefill.supplier.id', $this->supplier->id)
            ->where('prefill.lines.0.stock_batch_id', $this->batchA->id)
            ->where('prefill.lines.0.available_base', '10.000'));

    $ret = makeRtb([[$this->batchA, '2']], override: ['goods_receipt_id' => $gr->id]);
    expect($ret->goods_receipt_id)->toBe($gr->id);
    $other = tap((new Supplier)->forceFill(['code' => 'SUP-2', 'name' => 'Lain', 'payment_term_days' => 0, 'is_active' => true]))->save();
    $this->actingAs($this->buyer)->post('/pembelian/retur', rtbPayload([[$this->batchA, '1']], ['goods_receipt_id' => $gr->id, 'supplier_id' => $other->id]))
        ->assertSessionHasErrors('goods_receipt_id');
});
