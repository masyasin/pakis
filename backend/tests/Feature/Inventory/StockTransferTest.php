<?php

use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\Models\AccountingEvent;
use App\Domain\Approval\Actions\DecideApprovalStep;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Inventory\Actions\TransferReceiveReminders;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Notifications\TransferNotReceivedNotification;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Database\Seeders\TransitStockLocationSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\EmployeeWorld;
use Tests\Support\LedgerWorld;
use Tests\Support\StockWorld;

/*
 * T-082 — mutasi antar lokasi (FR-A27, BR-155, BR-156, BR-161, 15 §6.6/§11.4). Data fiktif.
 * Waktu dibekukan 17 Nov 2026 03.00 UTC (10.00 WIB). Role & izin Spatie sungguhan: staf gudang
 * asal mengirim, staf gudang tujuan menerima, kepala gudang tujuan menyetujui selisih.
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 03:00:00', 'UTC'));
    Notification::fake();
    $this->org = EmployeeWorld::make($this);
    $this->seed([TransitStockLocationSeeder::class, ApprovalRuleSeeder::class]);
    $this->useRealPermissions();
    setSetting('approval.reauth_amount', '999999999999');

    $other = StockWorld::location('L-LAIN');
    $this->src = StockWorld::stockLocation($this->org->gudang, 'GD-A');
    $this->dst = StockWorld::stockLocation($this->org->toko, 'GD-B');
    $this->far = StockWorld::stockLocation($other, 'GD-C');
    $this->transit = StockWorld::transit();
    $this->bin = LedgerWorld::bin($this->dst, 'R-01');

    $this->shipper = $this->org->actor('staf_gudang', $this->org->employee($this->org->gudang));
    $this->receiver = $this->org->actor('staf_gudang', $this->org->employee($this->org->toko));
    $this->head = $this->org->actor('kepala_gudang', $this->org->employee($this->org->toko));
    $this->outsider = $this->org->actor('staf_gudang', $this->org->employee($other));

    $this->product = LedgerWorld::product('MUT-01');
    $this->batch = LedgerWorld::receive($this->src, $this->product, '50', '1000', new BatchKey('B-001', '2027-12-31'));
});

/** Majukan waktu + sesi baru: sesi lama kedaluwarsa (idle) setelah lompatan berjam-jam. */
function stTravel(string $utc): void
{
    test()->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    test()->flushSession();
}

/** @return array<string, mixed> */
function stPayload(array $override = [], ?array $lines = null): array
{
    return [
        'from_stock_location_id' => test()->src->id,
        'to_stock_location_id' => test()->dst->id,
        'vehicle_id' => null,
        'note' => null,
        'lines' => $lines ?? [['product_id' => test()->product->id, 'qty_base' => '50', 'source_batch_id' => null]],
        ...$override,
    ];
}

function stDraft(array $override = [], ?array $lines = null, ?User $actor = null): StockTransfer
{
    test()->actingAs($actor ?? test()->shipper)->post('/mutasi', stPayload($override, $lines))->assertSessionHasNoErrors();

    return StockTransfer::query()->latest('id')->firstOrFail();
}

function stShipped(?array $lines = null, ?User $actor = null): StockTransfer
{
    $t = stDraft(lines: $lines, actor: $actor);
    test()->actingAs($actor ?? test()->shipper)->post("/mutasi/{$t->id}/kirim")->assertSessionHasNoErrors();

    return $t->fresh();
}

/** Terima: `$qty` per baris urut id (string sama untuk semua baris bila skalar). */
function stReceive(StockTransfer $t, string|array $qty, ?User $actor = null, ?string $reason = 'Kardus robek', ?int $binId = -1): TestResponse
{
    $lines = $t->lines()->get()->values()->map(fn ($l, $i) => [
        'line_id' => $l->id,
        'qty_received' => is_array($qty) ? $qty[$i] : $qty,
        'dest_bin_id' => $binId === -1 ? test()->bin->id : $binId,
        'variance_reason' => $reason,
    ])->all();

    return test()->actingAs($actor ?? test()->receiver)->post("/mutasi/{$t->id}/terima", ['lines' => $lines]);
}

function stOnHand(int $locationId): string
{
    return (string) StockBatch::query()->where('stock_location_id', $locationId)->where('product_id', test()->product->id)->sum('qty_on_hand');
}

function stEvents(StockTransfer $t, EventCode $code)
{
    return AccountingEvent::query()->where('event_code', $code->value)->where('source_type', 'stock_transfer')->where('source_id', $t->id)->orderBy('revision')->get();
}

function stDecide(User $actor, StockTransfer $t, bool $approve): ApprovalRequest
{
    $request = ApprovalRequest::query()->findOrFail($t->fresh()->approval_request_id);
    $decide = app(DecideApprovalStep::class);

    return $approve
        ? $decide->approve($actor, $request->id, $request->current_step, null, true)
        : $decide->reject($actor, $request->id, $request->current_step, 'Hitung ulang, kardus kedua belum dibuka', true);
}

test('BR-161 kirim: stok asal turun, TRANSIT naik dengan batch, ED, dan biaya sama; tanpa event akuntansi; nomor MUT-2611-0001', function () {
    $t = stShipped();
    $line = $t->lines()->sole();
    $transitBatch = StockBatch::query()->findOrFail($line->transit_batch_id);

    expect($t->number)->toBe('MUT-2611-0001')
        ->and($t->status)->toBe(StockTransferStatus::Shipped)
        ->and($t->shipped_by)->toBe($this->shipper->id)
        ->and(stOnHand($this->src->id))->toBe('0.000')
        ->and($transitBatch->stock_location_id)->toBe($this->transit->id)
        ->and($transitBatch->batch_no)->toBe('B-001')
        ->and($transitBatch->expiry_date->toDateString())->toBe('2027-12-31')
        ->and($transitBatch->receipt_unit_cost)->toBe($this->batch->fresh()->receipt_unit_cost)
        ->and($transitBatch->qty_on_hand)->toBe('50.000')
        ->and($line->qty_shipped_base)->toBe('50.000')
        ->and(StockMovement::query()->where('source_type', 'stock_transfer')->where('source_id', $t->id)->pluck('movement_type')->map->value->all())
        ->toBe(['transfer_out', 'transfer_in'])
        // 15 §11.4: kirim tidak memancarkan event (event saat terima).
        ->and(AccountingEvent::query()->where('source_type', 'stock_transfer')->count())->toBe(0);
});

test('BR-161 terima lengkap: TRANSIT → tujuan & rak, satu event inventory.transferred (lines kosong, refs asal/tujuan), status received', function () {
    $t = stShipped();
    stReceive($t, '50')->assertSessionHasNoErrors();
    $t->refresh();
    $line = $t->lines()->sole();

    expect($t->status)->toBe(StockTransferStatus::Received)
        ->and($t->received_by)->toBe($this->receiver->id)
        ->and(stOnHand($this->transit->id))->toBe('0.000')
        ->and(stOnHand($this->dst->id))->toBe('50.000')
        ->and(StockBatch::query()->findOrFail($line->dest_batch_id)->stock_bin_id)->toBe($this->bin->id)
        ->and($line->dest_bin_id)->toBe($this->bin->id);

    $events = stEvents($t, EventCode::InventoryTransferred);
    expect($events)->toHaveCount(1)
        ->and($events[0]->business_date->toDateString())->toBe('2026-11-17')
        ->and($events[0]->payload['lines'])->toBe([])
        ->and($events[0]->payload['refs']['from_stock_location_id'])->toBe($this->src->id)
        ->and($events[0]->payload['refs']['to_stock_location_id'])->toBe($this->dst->id)
        ->and($events[0]->payload['refs']['items'][0]['qty_base'])->toBe('50.000')
        ->and($events[0]->payload['refs']['items'][0]['value'])->toBe('50000.00')
        ->and(stEvents($t, EventCode::InventoryTransferVariance))->toHaveCount(0);
});

test('BR-161 terima 48 dari 50: received_with_variance, 2 tetap di TRANSIT; disetujui → adjustment_out + inventory.transfer_variance (bukan inventory.adjusted) → closed', function () {
    $t = stShipped();
    stReceive($t, '48')->assertSessionHasNoErrors();
    $t->refresh();

    $request = ApprovalRequest::query()->findOrFail($t->approval_request_id);
    expect($t->status)->toBe(StockTransferStatus::ReceivedWithVariance)
        ->and(stOnHand($this->transit->id))->toBe('2.000')
        ->and(stOnHand($this->dst->id))->toBe('48.000')
        ->and($request->document_type)->toBe('stock_transfer_variance')
        ->and($request->amount)->toBe('2000.00')
        ->and($t->lines()->sole()->variance_reason)->toBe('Kardus robek');

    stDecide($this->head, $t, true);
    $t->refresh();

    $adjust = StockMovement::query()->where('source_type', 'stock_transfer')->where('source_id', $t->id)->where('movement_type', 'adjustment_out')->sole();
    $variance = stEvents($t, EventCode::InventoryTransferVariance);
    expect($t->status)->toBe(StockTransferStatus::Closed)
        ->and(stOnHand($this->transit->id))->toBe('0.000')
        ->and($adjust->qty)->toBe('-2.000')
        ->and($variance)->toHaveCount(1)
        ->and($variance[0]->payload['lines'])->toBe([
            ['role' => 'inventory_loss', 'debit' => '2000.00', 'stock_location_id' => $this->transit->id, 'product_id' => $this->product->id],
            ['role' => 'inventory', 'credit' => '2000.00', 'stock_location_id' => $this->transit->id, 'product_id' => $this->product->id],
        ])
        ->and($variance[0]->payload['refs'])->toBe(['from_stock_location_id' => $this->src->id, 'to_stock_location_id' => $this->dst->id])
        ->and(AccountingEvent::query()->where('event_code', EventCode::InventoryAdjusted->value)->count())->toBe(0);
});

test('BR-161 selisih ditolak → baris selisih dibuka untuk hitung ulang; hitung ulang lengkap → received, tambahan = event transferred revisi 2 (PA-66)', function () {
    $t = stShipped();
    stReceive($t, '48')->assertSessionHasNoErrors();
    stDecide($this->head, $t, false);
    $t->refresh();

    expect($t->status)->toBe(StockTransferStatus::ReceivedWithVariance)
        ->and($t->approval_request_id)->toBeNull()
        ->and($t->acceptsReceipt())->toBeTrue();

    // Hitung ulang tidak boleh kurang dari yang sudah diterima.
    stReceive($t, '47')->assertSessionHasErrors('lines.0.qty_received');

    stReceive($t, '50', reason: null)->assertSessionHasNoErrors();
    $t->refresh();
    $line = $t->lines()->sole();

    expect($t->status)->toBe(StockTransferStatus::Received)
        ->and($line->qty_received_base)->toBe('50.000')
        ->and($line->variance_reason)->toBeNull()
        ->and(stOnHand($this->transit->id))->toBe('0.000')
        ->and(stOnHand($this->dst->id))->toBe('50.000')
        ->and(stEvents($t, EventCode::InventoryTransferred)->pluck('revision')->all())->toBe([1, 2])
        ->and(stEvents($t, EventCode::InventoryTransferred)[1]->payload['refs']['items'][0]['qty_base'])->toBe('2.000');
});

test('BR-161 hitung ulang masih kurang → approval selisih baru', function () {
    $t = stShipped();
    stReceive($t, '48')->assertSessionHasNoErrors();
    $first = $t->fresh()->approval_request_id;
    stDecide($this->head, $t, false);

    stReceive($t, '49')->assertSessionHasNoErrors();
    $t->refresh();

    expect($t->status)->toBe(StockTransferStatus::ReceivedWithVariance)
        ->and($t->approval_request_id)->not->toBeNull()->not->toBe($first)
        ->and(ApprovalRequest::query()->findOrFail($t->approval_request_id)->amount)->toBe('1000.00')
        ->and(stOnHand($this->transit->id))->toBe('1.000');
});

test('BR-161 TRANSFER_OVER_RECEIPT: terima lebih dari dikirim ditolak, tidak ada stok yang pindah', function () {
    $t = stShipped();
    stReceive($t, '51')->assertSessionHasErrors(['lines.0.qty_received']);

    expect($t->fresh()->status)->toBe(StockTransferStatus::Shipped)
        ->and(stOnHand($this->transit->id))->toBe('50.000')
        ->and(ErrorCode::TRANSFER_OVER_RECEIPT->status())->toBe(422);
});

test('BR-161 alasan wajib bila diterima kurang dari dikirim', function () {
    $t = stShipped();
    stReceive($t, '48', reason: '  ')->assertSessionHasErrors('lines.0.variance_reason');

    expect(stOnHand($this->transit->id))->toBe('50.000');
});

test('BR-161 TRANSFER_SAME_PERSON: pengirim tidak boleh menerima mutasinya sendiri', function () {
    // Owner memegang semua izin dan scope semua lokasi, jadi hanya aturan dua orang yang menolak.
    $owner = $this->org->actor('owner');
    $t = stShipped(actor: $owner);

    $this->actingAs($owner)->postJson("/mutasi/{$t->id}/terima", ['lines' => [[
        'line_id' => $t->lines()->sole()->id, 'qty_received' => '50', 'dest_bin_id' => null, 'variance_reason' => null,
    ]]])->assertStatus(403)->assertJsonPath('error.code', 'TRANSFER_SAME_PERSON');

    expect($t->fresh()->status)->toBe(StockTransferStatus::Shipped);
});

test('ADR-115 terima memanggil PeriodGuard dengan tanggal terima: tanggal terkunci → PERIOD_LOCKED, tidak ada mutasi maupun event', function () {
    $t = stShipped();
    setSetting('accounting.lock_date', '2026-11-17');

    stReceive($t, '50')->assertSessionHasErrors();

    expect($t->fresh()->status)->toBe(StockTransferStatus::Shipped)
        ->and(stOnHand($this->transit->id))->toBe('50.000')
        ->and(stEvents($t, EventCode::InventoryTransferred))->toHaveCount(0);
});

test('ADR-115 putusan selisih memanggil PeriodGuard dengan tanggal putusan: terkunci → rollback utuh', function () {
    $t = stShipped();
    stReceive($t, '48')->assertSessionHasNoErrors();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 03:00:00', 'UTC'));
    setSetting('accounting.lock_date', '2026-11-19');

    expect(fn () => stDecide($this->head, $t, true))->toThrow(fn (ApiError $e) => expect($e->errorCode)->toBe(ErrorCode::PERIOD_LOCKED));

    $t->refresh();
    expect($t->status)->toBe(StockTransferStatus::ReceivedWithVariance)
        ->and(ApprovalRequest::query()->findOrFail($t->approval_request_id)->status)->toBe(ApprovalStatus::Pending)
        ->and(stOnHand($this->transit->id))->toBe('2.000')
        ->and(stEvents($t, EventCode::InventoryTransferVariance))->toHaveCount(0);
});

test('BR-153 penerima yang melaporkan selisih tidak bisa menyetujuinya sendiri', function () {
    $t = stShipped();
    stReceive($t, '48', actor: $this->head)->assertSessionHasNoErrors();

    expect(fn () => stDecide($this->head, $t, true))->toThrow(fn (ApiError $e) => expect($e->errorCode)->toBe(ErrorCode::APPROVAL_SELF_DECISION));
});

test('BR-155 batch dipilih saat draf direservasi; ubah draf melepas & mereservasi; batal melepas; BR-156 fefo_override dihitung server', function () {
    $newer = LedgerWorld::receive($this->src, $this->product, '10', '1000', new BatchKey('B-002', '2028-06-30'));
    $t = stDraft(lines: [['product_id' => $this->product->id, 'qty_base' => '4', 'source_batch_id' => $newer->id]]);

    expect($newer->fresh()->qty_reserved)->toBe('4.000')
        ->and($t->lines()->sole()->fefo_override)->toBeTrue();

    $this->actingAs($this->shipper)->put("/mutasi/{$t->id}", stPayload(lines: [
        ['product_id' => $this->product->id, 'qty_base' => '3', 'source_batch_id' => $this->batch->id],
    ]))->assertSessionHasNoErrors();

    expect($newer->fresh()->qty_reserved)->toBe('0.000')
        ->and($this->batch->fresh()->qty_reserved)->toBe('3.000')
        ->and($t->lines()->sole()->fefo_override)->toBeFalse();

    $this->actingAs($this->shipper)->post("/mutasi/{$t->id}/batal")->assertSessionHasNoErrors();

    expect($t->fresh()->status)->toBe(StockTransferStatus::Cancelled)
        ->and($this->batch->fresh()->qty_reserved)->toBe('0.000')
        ->and(StockReservation::query()->where('source_id', $t->id)->pluck('status')->all())
        ->each->toBe(ReservationStatus::Released);
});

test('BR-155 kirim baris berbatch: reservasi dilepas lalu batch yang sama pindah ke TRANSIT', function () {
    $t = stDraft(lines: [['product_id' => $this->product->id, 'qty_base' => '20', 'source_batch_id' => $this->batch->id]]);
    $this->actingAs($this->shipper)->post("/mutasi/{$t->id}/kirim")->assertSessionHasNoErrors();

    expect($this->batch->fresh()->qty_reserved)->toBe('0.000')
        ->and($this->batch->fresh()->qty_on_hand)->toBe('30.000')
        ->and(stOnHand($this->transit->id))->toBe('20.000')
        ->and($t->lines()->sole()->source_batch_id)->toBe($this->batch->id);
});

test('BR-102 baris tanpa batch dialokasikan FEFO saat kirim; terbagi ke dua batch → baris dipecah per batch (PA-66)', function () {
    $later = LedgerWorld::receive($this->src, $this->product, '30', '1000', new BatchKey('B-009', '2028-01-31'));
    $t = stShipped(lines: [['product_id' => $this->product->id, 'qty_base' => '70', 'source_batch_id' => null]]);
    $lines = $t->lines()->get();

    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('source_batch_id')->all())->toBe([$this->batch->id, $later->id])
        ->and($lines->pluck('qty_shipped_base')->all())->toBe(['50.000', '20.000'])
        ->and($lines->pluck('qty_requested_base')->all())->toBe(['50.000', '20.000']);
});

test('BR-161 batal hanya draf: mutasi yang sudah dikirim tidak bisa dibatalkan', function () {
    $t = stShipped();
    $this->actingAs($this->shipper)->post("/mutasi/{$t->id}/batal")->assertForbidden();

    expect($t->fresh()->status)->toBe(StockTransferStatus::Shipped);
});

test('BR-161 asal & tujuan hanya gudang/toko, berbeda, asal dalam scope pengirim', function () {
    $this->actingAs($this->shipper)->post('/mutasi', stPayload(['to_stock_location_id' => $this->src->id]))
        ->assertSessionHasErrors('to_stock_location_id');
    $this->actingAs($this->shipper)->post('/mutasi', stPayload(['to_stock_location_id' => $this->transit->id]))
        ->assertSessionHasErrors('to_stock_location_id');
    // Lokasi asal di luar scope pengirim.
    $this->actingAs($this->shipper)->post('/mutasi', stPayload(['from_stock_location_id' => $this->far->id]))
        ->assertSessionHasErrors('from_stock_location_id');

    expect(StockTransfer::query()->count())->toBe(0);
});

test('IDOR admin.stock-transfers.*: staf lokasi lain (bukan asal/tujuan) → 404; asal & tujuan boleh melihat', function () {
    $t = stShipped();

    $this->assertOutOfScope404($this->outsider, 'GET', "/mutasi/{$t->id}");
    $this->assertOutOfScope404($this->outsider, 'POST', "/mutasi/{$t->id}/terima", ['lines' => [['line_id' => 1, 'qty_received' => '1']]]);
    $this->assertOutOfScope404($this->outsider, 'POST', "/mutasi/{$t->id}/kirim");
    $this->assertOutOfScope404($this->outsider, 'POST', "/mutasi/{$t->id}/batal");
    $this->assertOutOfScope404($this->outsider, 'GET', "/mutasi/{$t->id}/ubah");

    $this->actingAs($this->shipper)->get("/mutasi/{$t->id}")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('can.receive', false)->where('transfer.number', 'MUT-2611-0001'));
    $this->actingAs($this->receiver)->get("/mutasi/{$t->id}")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('can.receive', true)->where('can.ship', false)
            // Nilai selisih = biaya: staf tanpa stock.view_cost tidak menerimanya.
            ->where('transfer.variance_value', null));
});

test('listing stock_transfers: pencarian nomor, filter status & terlambat, sort, scope asal atau tujuan', function () {
    $a = stShipped();
    stTravel('2026-11-18 06:00:00'); // 27 jam setelah kirim
    $b = stDraft();

    $rows = fn (User $u, array $q = []) => collect($this->actingAs($u)->get('/mutasi?'.http_build_query($q))->assertOk()
        ->viewData('page')['props']['rows'])->pluck('number')->all();

    expect($rows($this->receiver))->toBe([$b->number, $a->number])
        ->and($rows($this->receiver, ['sort' => 'number']))->toBe([$a->number, $b->number])
        ->and($rows($this->receiver, ['q' => $b->number]))->toBe([$b->number])
        ->and($rows($this->receiver, ['filter' => ['status' => ['shipped']]]))->toBe([$a->number])
        ->and($rows($this->receiver, ['filter' => ['overdue' => '1']]))->toBe([$a->number])
        ->and($rows($this->shipper))->toHaveCount(2)
        ->and($rows($this->outsider))->toBe([]);
});

test('BR-161 pengingat terima: setelah ambang ke penerima di lokasi tujuan (bukan pengirim/lokasi lain), sekali per mutasi per hari', function () {
    $t = stShipped();
    $reminders = app(TransferReceiveReminders::class);

    expect($reminders->handle()['sent'])->toBe(0); // belum lewat 24 jam

    stTravel('2026-11-18 04:00:00');
    expect($reminders->handle()['sent'])->toBe(1)
        ->and(app(TransferReceiveReminders::class)->handle()['sent'])->toBe(0);

    Notification::assertSentTo([$this->receiver, $this->head], TransferNotReceivedNotification::class);
    Notification::assertNotSentTo([$this->shipper, $this->outsider], TransferNotReceivedNotification::class);

    stTravel('2026-11-19 04:00:00');
    expect(app(TransferReceiveReminders::class)->handle()['sent'])->toBe(1);

    stReceive($t, '50')->assertSessionHasNoErrors();
    stTravel('2026-11-20 04:00:00');
    expect(app(TransferReceiveReminders::class)->handle()['checked'])->toBe(0);
});

test('dasbor "Mutasi belum diterima" untuk pemegang stock_transfers.receive, hanya tujuan dalam scope', function () {
    stShipped();
    stTravel('2026-11-18 04:00:00');

    $item = fn (User $u) => collect($this->actingAs($u)->get('/dasbor')->assertOk()->viewData('page')['props']['pending'])
        ->firstWhere('key', 'transfers_unreceived');

    expect($item($this->receiver)['count'])->toBe(1)
        ->and($item($this->shipper)['count'])->toBe(0)
        ->and($item($this->org->actor('kepala_lokasi', $this->org->employee($this->org->toko))))->toBeNull();
});
