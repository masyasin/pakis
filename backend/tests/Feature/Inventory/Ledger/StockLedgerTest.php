<?php

use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\Exceptions\BatchNotAvailable;
use App\Domain\Inventory\Ledger\Exceptions\InsufficientStock;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Ledger\StockLine;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBatchStatusLog;
use App\Domain\Inventory\Models\StockMovement;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\TransitStockLocationSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerWorld;
use Tests\Support\StockWorld;

/*
 * T-077 — StockLedger (ADR-103, BR-101, BR-154, BR-155, SEC-102). Data dummy. Test ber-RefreshDatabase
 * sudah di dalam transaksi, jadi pemanggilan ledger di sini sah; penolakan di luar transaksi diuji di
 * tests/Unit/Inventory/StockLedgerOutsideTransactionTest.php.
 */

beforeEach(function () {
    $this->seed([ReferenceSeeder::class, TransitStockLocationSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 03:00:00', 'UTC'));
    $this->gd = LedgerWorld::warehouse('GD-A');
    $this->product = LedgerWorld::product('P-01');
    $this->ledger = LedgerWorld::ledger();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function ldgIssue(StockBatch $batch, string $qty, MovementType $type = MovementType::Sale)
{
    return test()->ledger->issue($batch, Qty::of($qty), LedgerWorld::source('sales_invoice', 9), $type, LedgerWorld::DATE);
}

/** @return list<string> qty_after mutasi batch berurutan */
function ldgAfters(StockBatch $batch): array
{
    return StockMovement::query()->where('stock_batch_id', $batch->id)->orderBy('id')->pluck('qty_after')->all();
}

test('BR-154 terima: batch dibuat sekali, satu mutasi receipt per penerimaan, qty_after = saldo', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '10', '1000');
    $again = LedgerWorld::receive($this->gd, $this->product, '5.5', '1000');

    expect($again->id)->toBe($batch->id)
        ->and(StockBatch::query()->count())->toBe(1)
        ->and((string) $again->onHand())->toBe('15.500')
        ->and($again->expiry_date?->toDateString())->toBe('2027-12-31')
        ->and($again->received_date?->toDateString())->toBe(LedgerWorld::DATE)
        ->and($again->status)->toBe(StockBatchStatus::Available)
        ->and(ldgAfters($batch))->toBe(['10.000', '15.500']);

    $m = StockMovement::query()->orderBy('id')->first();
    expect($m->movement_type)->toBe(MovementType::Receipt)
        ->and($m->qty)->toBe('10.000')
        ->and($m->unit_cost)->toBe('1000.0000')
        ->and($m->source_type)->toBe('goods_receipt')
        ->and($m->stock_location_id)->toBe($this->gd->id)
        ->and($m->product_id)->toBe($this->product->id)
        ->and($m->business_date->toDateString())->toBe(LedgerWorld::DATE);
});

test('BR-154 batch tanpa rak dan dengan rak adalah batch berbeda (bin_key), batch sama tidak kembar', function () {
    $bin = LedgerWorld::bin($this->gd, 'A-01');
    LedgerWorld::receive($this->gd, $this->product, '1');
    LedgerWorld::receive($this->gd, $this->product, '1', key: new BatchKey('B-001', '2027-12-31', $bin->id));
    LedgerWorld::receive($this->gd, $this->product, '1', key: new BatchKey('B-001', '2027-12-31', $bin->id));

    expect(StockBatch::query()->count())->toBe(2)
        ->and(StockBatch::query()->where('stock_bin_id', $bin->id)->value('qty_on_hand'))->toBe('2.000');
});

test('BR-164 terima ke rak karantina → batch berstatus quarantine', function () {
    $q = LedgerWorld::quarantineBin($this->gd);
    $batch = LedgerWorld::receive($this->gd, $this->product, '3', key: new BatchKey('B-9', '2027-01-01', $q->id));

    expect($batch->status)->toBe(StockBatchStatus::Quarantine);
});

test('BR-101 keluar: saldo turun, mutasi negatif ber-HPP rata-rata; melebihi tersedia → INSUFFICIENT_STOCK', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '10', '1000');
    $result = ldgIssue($batch, '4');

    expect($result->movement()->qty)->toBe('-4.000')
        ->and($result->movement()->qty_after)->toBe('6.000')
        ->and($result->movement()->unit_cost)->toBe('1000.0000')
        ->and((string) $result->batch()->onHand())->toBe('6.000')
        ->and((string) $result->value())->toBe('4000.00');

    try {
        ldgIssue($batch, '6.001');
        $this->fail('Harus INSUFFICIENT_STOCK');
    } catch (InsufficientStock $e) {
        expect($e->errorCode)->toBe(ErrorCode::INSUFFICIENT_STOCK)
            ->and($e->status())->toBe(422)
            ->and((string) $e->details['available_base'])->toBe('6.000')
            ->and($e->details['stock_batch_id'])->toBe($batch->id);
    }
    expect($batch->fresh()->qty_on_hand)->toBe('6.000')->and(StockMovement::query()->count())->toBe(2);
});

test('BR-154 issue banyak batch: batch yang sama dijumlah jadi satu mutasi, urut id', function () {
    $a = LedgerWorld::receive($this->gd, $this->product, '5', key: new BatchKey('A', '2027-01-01'));
    $b = LedgerWorld::receive($this->gd, $this->product, '5', key: new BatchKey('B', '2027-02-01'));

    $result = $this->ledger->issue([new StockLine($b, Qty::of(1)), new StockLine($a, Qty::of(2)), new StockLine($b, Qty::of(3))], null,
        LedgerWorld::source('sales_invoice', 2), MovementType::Sale, LedgerWorld::DATE);

    expect(array_map(fn ($m) => [$m->stock_batch_id, $m->qty, $m->qty_after], $result->movements))->toBe([
        [$a->id, '-2.000', '3.000'],
        [$b->id, '-4.000', '1.000'],
    ]);
});

test('BR-102 jual dari batch karantina → BATCH_NOT_AVAILABLE; pemusnahan & retur prinsipal tetap boleh', function () {
    $q = LedgerWorld::quarantineBin($this->gd);
    $batch = LedgerWorld::receive($this->gd, $this->product, '3', key: new BatchKey('B-9', '2027-01-01', $q->id));

    expect(fn () => ldgIssue($batch, '1'))->toThrow(BatchNotAvailable::class);
    expect(fn () => ldgIssue($batch, '1', MovementType::TransferOut))->toThrow(BatchNotAvailable::class);

    ldgIssue($batch, '1', MovementType::PurchaseReturn);
    $result = ldgIssue($batch, '2', MovementType::Disposal);
    expect($result->movement()->qty_after)->toBe('0.000');
});

test('jenis mutasi salah arah ditolak (receipt sebagai pengurang, sale sebagai penerimaan)', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '3');

    expect(fn () => ldgIssue($batch, '1', MovementType::Receipt))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->ledger->receive($this->gd, $this->product, new BatchKey('X'), Qty::of(1), UnitCost::of(1), LedgerWorld::source(), LedgerWorld::DATE, MovementType::TransferIn))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => ldgIssue($batch, '0'))->toThrow(InvalidArgumentException::class);
});

test('BR-161 move ke TRANSIT: dua mutasi, batch bayangan bernomor, ED, tanggal terima & biaya beli sama', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '10', '1250');
    $transit = StockWorld::transit();

    $result = $this->ledger->move($batch, $transit, Qty::of('4'), LedgerWorld::source('stock_transfer', 3, 7), MovementType::TransferOut, MovementType::TransferIn, LedgerWorld::DATE);

    [$out, $in] = $result->movements;
    $shadow = $result->batches[$in->stock_batch_id];
    expect([$out->movement_type, $out->qty, $out->qty_after, $out->stock_location_id])->toBe([MovementType::TransferOut, '-4.000', '6.000', $this->gd->id])
        ->and([$in->movement_type, $in->qty, $in->qty_after, $in->stock_location_id])->toBe([MovementType::TransferIn, '4.000', '4.000', $transit->id])
        ->and($in->source_line_id)->toBe(7)
        ->and($shadow->batch_no)->toBe('B-001')
        ->and($shadow->expiry_date?->toDateString())->toBe('2027-12-31')
        ->and($shadow->received_date?->toDateString())->toBe(LedgerWorld::DATE)
        ->and($shadow->receipt_unit_cost)->toBe('1250.0000')
        ->and($shadow->stock_bin_id)->toBeNull();

    // Kirim kedua memakai batch bayangan yang sama.
    $again = $this->ledger->move($batch, $transit, Qty::of('1'), LedgerWorld::source('stock_transfer', 4), MovementType::TransferOut, MovementType::TransferIn, LedgerWorld::DATE);
    expect($again->movements[1]->stock_batch_id)->toBe($shadow->id)->and($again->movements[1]->qty_after)->toBe('5.000');
});

test('BR-164 pindah rak ke karantina → batch tujuan quarantine; lepas ke rak umum → available', function () {
    $bin = LedgerWorld::bin($this->gd, 'A-01');
    $q = LedgerWorld::quarantineBin($this->gd);
    $batch = LedgerWorld::receive($this->gd, $this->product, '5', key: new BatchKey('B-1', '2027-01-01', $bin->id));

    $in = $this->ledger->move($batch, $q, Qty::of(2), LedgerWorld::source('stock_quarantine', 1), MovementType::BinMoveOut, MovementType::BinMoveIn, LedgerWorld::DATE);
    $quarantined = $in->batches[$in->movements[1]->stock_batch_id];
    expect($quarantined->status)->toBe(StockBatchStatus::Quarantine)->and($quarantined->stock_bin_id)->toBe($q->id);

    $back = $this->ledger->move($quarantined, LedgerWorld::bin($this->gd, 'A-02'), Qty::of(2), LedgerWorld::source('stock_quarantine', 2), MovementType::BinMoveOut, MovementType::BinMoveIn, LedgerWorld::DATE);
    expect($back->batches[$back->movements[1]->stock_batch_id]->status)->toBe(StockBatchStatus::Available)
        ->and(fn () => $this->ledger->move($batch, $bin, Qty::of(1), LedgerWorld::source(), MovementType::BinMoveOut, MovementType::BinMoveIn, LedgerWorld::DATE))
        ->toThrow(InvalidArgumentException::class);
});

test('15 §4.8 rak milik lokasi lain / rak di lokasi tanpa rak ditolak', function () {
    $other = LedgerWorld::warehouse('GD-B');
    $foreignBin = LedgerWorld::bin($other, 'B-01');

    expect(fn () => LedgerWorld::receive($this->gd, $this->product, '1', key: new BatchKey('X', null, $foreignBin->id)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => LedgerWorld::receive(StockWorld::transit(), $this->product, '1', key: new BatchKey('X', null, $foreignBin->id)))->toThrow(InvalidArgumentException::class);
});

test('BR-155 reservasi menaikkan qty_reserved tanpa mutasi; tersedia berkurang; konsumsi menurunkan keduanya', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '10');
    $res = $this->ledger->reserve($batch, Qty::of(7), LedgerWorld::source('stock_transfer', 5, 1));

    expect($res->status)->toBe(ReservationStatus::Active)
        ->and($res->expires_at?->toIso8601String())->toBe(now()->addHours(72)->toIso8601String())
        ->and($batch->fresh()->qty_reserved)->toBe('7.000')
        ->and(StockMovement::query()->count())->toBe(1);

    expect(fn () => ldgIssue($batch, '3.001'))->toThrow(InsufficientStock::class);
    expect(fn () => $this->ledger->reserve($batch, Qty::of('3.5'), LedgerWorld::source()))->toThrow(InsufficientStock::class);

    $consumed = $this->ledger->consume($res, MovementType::TransferOut, LedgerWorld::DATE);
    expect($consumed->movement()->qty)->toBe('-7.000')
        ->and($consumed->movement()->source_type)->toBe('stock_transfer')
        ->and($consumed->movement()->source_line_id)->toBe(1)
        ->and($batch->fresh()->only(['qty_on_hand', 'qty_reserved']))->toBe(['qty_on_hand' => '3.000', 'qty_reserved' => '0.000'])
        ->and($res->fresh()->status)->toBe(ReservationStatus::Consumed);

    // Reservasi yang sudah dikonsumsi tidak bisa dikonsumsi lagi; lepas = no-op.
    try {
        $this->ledger->consume($res, MovementType::TransferOut, LedgerWorld::DATE);
        $this->fail('Harus STATE_CONFLICT');
    } catch (ApiError $e) {
        expect($e->errorCode)->toBe(ErrorCode::STATE_CONFLICT);
    }
    expect($this->ledger->release($res))->toBeFalse();
});

test('BR-155 lepas reservasi idempoten; TTL bawaan dari setting; batch karantina tidak bisa direservasi', function () {
    setSetting('inventory.reservation_ttl_hours', 5);
    $batch = LedgerWorld::receive($this->gd, $this->product, '10');
    $res = $this->ledger->reserve($batch, Qty::of(4), LedgerWorld::source());

    expect($res->expires_at?->toIso8601String())->toBe(now()->addHours(5)->toIso8601String())
        ->and($this->ledger->release($res))->toBeTrue()
        ->and($this->ledger->release($res))->toBeFalse()
        ->and($batch->fresh()->qty_reserved)->toBe('0.000')
        ->and($res->fresh()->status)->toBe(ReservationStatus::Released);

    $q = LedgerWorld::receive($this->gd, $this->product, '1', key: new BatchKey('Q', null, LedgerWorld::quarantineBin($this->gd)->id));
    expect(fn () => $this->ledger->reserve($q, Qty::of(1), LedgerWorld::source()))->toThrow(BatchNotAvailable::class);
});

test('PA-68 BR-155 retur prinsipal boleh mereservasi batch karantina & kedaluwarsa; disposed tetap ditolak; sumber lain tidak', function () {
    $q = LedgerWorld::receive($this->gd, $this->product, '5', key: new BatchKey('Q-RTB', null, LedgerWorld::quarantineBin($this->gd)->id));
    $res = $this->ledger->reserve($q, Qty::of(2), LedgerWorld::source('purchase_return', 3, 1));
    expect($res->status)->toBe(ReservationStatus::Active)
        ->and($q->fresh()->qty_reserved)->toBe('2.000')
        ->and(fn () => $this->ledger->reserve($q, Qty::of(1), LedgerWorld::source('stock_transfer', 3, 1)))->toThrow(BatchNotAvailable::class);

    $expired = LedgerWorld::receive($this->gd, $this->product, '3', key: new BatchKey('ED-RTB', '2026-01-01'));
    $this->ledger->setStatus($expired, StockBatchStatus::Expired);
    expect($this->ledger->reserve($expired->fresh(), Qty::of(3), LedgerWorld::source('purchase_return', 4, 1))->status)->toBe(ReservationStatus::Active)
        ->and(fn () => $this->ledger->reserve($expired->fresh(), Qty::of(1), LedgerWorld::source('purchase_return', 5, 1)))->toThrow(InsufficientStock::class);

    $gone = LedgerWorld::receive($this->gd, $this->product, '1', key: new BatchKey('X-RTB', '2026-01-01'));
    $this->ledger->setStatus($gone, StockBatchStatus::Expired);
    $this->ledger->issue($gone->fresh(), Qty::of(1), LedgerWorld::source('disposal_report', 1, 1), MovementType::Disposal, LedgerWorld::DATE);
    $this->ledger->setStatus($gone->fresh(), StockBatchStatus::Disposed);
    expect(fn () => $this->ledger->reserve($gone->fresh(), Qty::of(1), LedgerWorld::source('purchase_return', 6, 1)))->toThrow(BatchNotAvailable::class);
});

test('BR-155 job release-reservations melepas yang lewat masa berlaku saja', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '10');
    $old = $this->ledger->reserve($batch, Qty::of(2), LedgerWorld::source(), ttlHours: 1);
    $fresh = $this->ledger->reserve($batch, Qty::of(3), LedgerWorld::source(), ttlHours: 24);

    CarbonImmutable::setTestNow(now()->addHours(2));
    $this->artisan('inventory:release-reservations')->assertSuccessful();

    expect($old->fresh()->status)->toBe(ReservationStatus::Released)
        ->and($fresh->fresh()->status)->toBe(ReservationStatus::Active)
        ->and($batch->fresh()->qty_reserved)->toBe('3.000');
});

test('BR-162 penyesuaian: + adjustment_in, − adjustment_out ber-HPP rata-rata; BatchKey membuat batch baru', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '10', '1500');

    $minus = $this->ledger->adjust($batch, Qty::of('-2'), LedgerWorld::source('stock_opname', 1, 1), null, LedgerWorld::DATE);
    expect([$minus->movement()->movement_type, $minus->movement()->qty, $minus->movement()->unit_cost])->toBe([MovementType::AdjustmentOut, '-2.000', '1500.0000']);

    $found = $this->ledger->adjust(new BatchKey('TEMU-1', '2028-01-01'), Qty::of('3'), LedgerWorld::source('stock_opname', 1, 2), 99, LedgerWorld::DATE, $this->gd, $this->product);
    expect([$found->movement()->movement_type, $found->movement()->qty_after, $found->movement()->unit_cost])->toBe([MovementType::AdjustmentIn, '3.000', '1500.0000'])
        ->and($found->batch()->batch_no)->toBe('TEMU-1')
        ->and($found->batch()->receipt_unit_cost)->toBeNull();

    expect(fn () => $this->ledger->adjust($batch, Qty::of('-8.001'), LedgerWorld::source(), null, LedgerWorld::DATE))->toThrow(InsufficientStock::class)
        ->and(fn () => $this->ledger->adjust($batch, Qty::zero(), LedgerWorld::source(), null, LedgerWorld::DATE))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->ledger->adjust(new BatchKey('Z'), Qty::of(1), LedgerWorld::source(), null, LedgerWorld::DATE))->toThrow(InvalidArgumentException::class);
});

test('BR-157 setStatus: tinggalkan available melepas reservasi + log; aturan transisi ditegakkan', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '10');
    $res = $this->ledger->reserve($batch, Qty::of(4), LedgerWorld::source());

    $expired = $this->ledger->setStatus($batch, StockBatchStatus::Expired, note: 'ED lewat');
    expect($expired->status)->toBe(StockBatchStatus::Expired)
        ->and($expired->qty_reserved)->toBe('0.000')
        ->and($res->fresh()->status)->toBe(ReservationStatus::Released)
        ->and(StockBatchStatusLog::query()->sole()->only(['from_status', 'to_status', 'user_id', 'note']))
        ->toBe(['from_status' => 'available', 'to_status' => 'expired', 'user_id' => null, 'note' => 'ED lewat'])
        ->and(StockMovement::query()->count())->toBe(1);

    expect(fn () => $this->ledger->setStatus($batch, StockBatchStatus::Available))->toThrow(LogicException::class)
        ->and(fn () => $this->ledger->setStatus($batch, StockBatchStatus::Disposed))->toThrow(LogicException::class);

    ldgIssue($batch, '10', MovementType::Disposal);
    expect($this->ledger->setStatus($batch, StockBatchStatus::Disposed)->status)->toBe(StockBatchStatus::Disposed)
        ->and(fn () => $this->ledger->setStatus($batch, StockBatchStatus::Quarantine))->toThrow(LogicException::class);
});

test('BR-164 batch di rak karantina tidak bisa dijadikan available; alasan karantina tersimpan', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '2');
    $q = $this->ledger->setStatus($batch, StockBatchStatus::Quarantine, reasonId: 12);
    expect($q->quarantine_reason_id)->toBe(12);
    expect($this->ledger->setStatus($batch, StockBatchStatus::Available)->quarantine_reason_id)->toBeNull();

    $inQ = LedgerWorld::receive($this->gd, $this->product, '1', key: new BatchKey('Q', null, LedgerWorld::quarantineBin($this->gd)->id));
    expect(fn () => $this->ledger->setStatus($inQ, StockBatchStatus::Available))->toThrow(LogicException::class);
});

test('SEC-102 trigger menolak UPDATE/DELETE stock_movements (SQL langsung)', function () {
    LedgerWorld::receive($this->gd, $this->product, '1');

    expect(fn () => DB::statement('UPDATE stock_movements SET qty = 5'))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::statement('DELETE FROM stock_movements'))->toThrow(QueryException::class, 'append-only');
});

test('BR-101 CHECK DB menolak saldo minus dan reservasi > saldo (lapis kedua)', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '1');

    expect(fn () => DB::statement('UPDATE stock_batches SET qty_on_hand = -1 WHERE id = ?', [$batch->id]))->toThrow(QueryException::class)
        ->and(fn () => DB::statement('UPDATE stock_batches SET qty_reserved = 2 WHERE id = ?', [$batch->id]))->toThrow(QueryException::class)
        ->and(fn () => DB::statement('INSERT INTO stock_movements (stock_batch_id, product_id, stock_location_id, movement_type, qty, qty_after, unit_cost, source_type, source_id, business_date, moved_at) VALUES (?, ?, ?, ?, 0, 1, 0, ?, 1, ?, NOW())',
            [$batch->id, $this->product->id, $this->gd->id, 'receipt', 'x', LedgerWorld::DATE]))->toThrow(QueryException::class);
});

test('SEC-102 model buku stok baca-saja: save, update massal, increment, insert, delete lewat model ditolak', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '1');
    $movement = StockMovement::query()->sole();

    $attempts = [
        fn () => $batch->forceFill(['qty_on_hand' => '9'])->save(),
        fn () => $batch->increment('qty_on_hand'),
        fn () => $batch->delete(),
        fn () => StockBatch::query()->whereKey($batch->id)->update(['qty_on_hand' => '9']),
        fn () => StockBatch::query()->whereKey($batch->id)->decrement('qty_on_hand'),
        fn () => StockBatch::query()->insert(['stock_location_id' => $this->gd->id]),
        fn () => StockBatch::query()->updateOrInsert(['id' => $batch->id], ['qty_on_hand' => '9']),
        fn () => $movement->delete(),
        fn () => StockMovement::query()->delete(),
        fn () => StockMovement::query()->insertOrIgnore([]),
        fn () => StockMovement::query()->truncate(),
    ];
    foreach ($attempts as $i => $attempt) {
        expect($attempt)->toThrow(LogicException::class, 'StockLedger', "percobaan #{$i}");
    }
    expect($batch->fresh()->qty_on_hand)->toBe('1.000')->and(StockMovement::query()->count())->toBe(1);
});

test('15 §9 negative_stock_allowed selain false → ledger menolak bekerja', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '1');
    setSetting('inventory.negative_stock_allowed', true);

    expect(fn () => ldgIssue($batch, '1'))->toThrow(LogicException::class, 'Stok minus');
});

test('15 §11.1 job buku stok terjadwal: rekonsiliasi 01.00 lokal, lepas reservasi tiap 15 menit', function () {
    $events = collect(app(Schedule::class)->events())
        ->mapWithKeys(fn ($e) => [trim((string) preg_replace('/^.*artisan["\']?\s+/', '', (string) $e->command)) => $e]);

    expect($events->get('inventory:reconcile')?->expression)->toBe('0 1 * * *')
        ->and($events->get('inventory:reconcile')?->timezone)->toBe('Asia/Jakarta')
        ->and($events->get('inventory:reconcile')?->withoutOverlapping)->toBeTrue()
        ->and($events->get('inventory:release-reservations')?->expression)->toBe('*/15 * * * *')
        ->and($events->get('inventory:release-reservations')?->onOneServer)->toBeTrue();
});

test('ADR-103 StockLedger::transaction di dalam transaksi lain = savepoint tanpa retry', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '2');

    expect(fn () => StockLedger::transaction(function () use ($batch) {
        ldgIssue($batch, '1');
        throw new RuntimeException('batal');
    }))->toThrow(RuntimeException::class);

    expect($batch->fresh()->qty_on_hand)->toBe('2.000')->and(StockMovement::query()->count())->toBe(1);
});
