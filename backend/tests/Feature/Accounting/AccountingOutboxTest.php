<?php

use App\Domain\Accounting\Outbox\AccountingOutbox;
use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\EventSource;
use App\Domain\Accounting\Outbox\EventStatus;
use App\Domain\Accounting\Outbox\Models\AccountingEvent;
use App\Domain\Accounting\Period\PeriodGuard;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * ADR-104 (T-077, 15 §4.8): outbox ditulis di transaksi pemanggil; append-only kecuali kolom
 * mesin posting (status, journal_entry_id, error), dijaga trigger DB + model.
 */

/** @return array<string, mixed> */
function adjustedPayload(string $amount = '125000.00'): array
{
    return ['lines' => [
        ['role' => 'inventory', 'debit' => $amount, 'stock_location_id' => 3, 'product_id' => 7],
        ['role' => 'inventory_variance', 'credit' => $amount, 'stock_location_id' => 3, 'product_id' => 7],
    ]];
}

function recordAdjusted(int $sourceId = 1, int $revision = 1, string $date = '2026-11-17'): AccountingEvent
{
    return DB::transaction(fn () => AccountingOutbox::record(
        EventCode::InventoryAdjusted, new EventSource('stock_opname', $sourceId), $date, adjustedPayload(), $revision,
    ));
}

test('ADR-104 record menulis event pending dengan payload apa adanya (string desimal utuh)', function () {
    $event = recordAdjusted(sourceId: 42);

    $row = DB::table('accounting_events')->where('id', $event->id)->first();
    expect($row->event_code)->toBe('inventory.adjusted')
        ->and($row->business_date)->toBe('2026-11-17')
        ->and($row->source_type)->toBe('stock_opname')
        ->and((int) $row->source_id)->toBe(42)
        ->and((int) $row->revision)->toBe(1)
        ->and($row->status)->toBe('pending')
        ->and($row->journal_entry_id)->toBeNull()
        ->and(json_decode($row->payload, true))->toBe(adjustedPayload())
        ->and($event->fresh()?->status)->toBe(EventStatus::Pending);
});

test('ADR-104 record di luar transaksi pemanggil melempar LogicException', function () {
    // RefreshDatabase membungkus test dalam transaksi; keluar sementara agar level benar-benar 0.
    DB::rollBack();
    try {
        expect(DB::transactionLevel())->toBe(0)
            ->and(fn () => AccountingOutbox::record(EventCode::InventoryAdjusted, new EventSource('stock_opname', 1), '2026-11-17', adjustedPayload()))
            ->toThrow(LogicException::class, 'wajib dipanggil di dalam transaksi');
    } finally {
        DB::beginTransaction();
    }

    expect(AccountingEvent::query()->count())->toBe(0);
});

test('ADR-104 event ganda (kode, sumber, revisi sama) ditolak STATE_CONFLICT; revisi berikutnya boleh', function () {
    recordAdjusted(sourceId: 5);

    try {
        recordAdjusted(sourceId: 5);
        $this->fail('Event ganda seharusnya ditolak.');
    } catch (ApiError $e) {
        expect($e->errorCode)->toBe(ErrorCode::STATE_CONFLICT);
    }

    recordAdjusted(sourceId: 5, revision: 2);
    expect(AccountingEvent::query()->where('source_id', 5)->pluck('revision')->all())->toBe([1, 2]);
});

test('ADR-104 payload tidak sah tidak menulis apa pun', function () {
    expect(fn () => DB::transaction(fn () => AccountingOutbox::record(
        EventCode::InventoryAdjusted, new EventSource('stock_opname', 1), '2026-11-17', ['lines' => [['role' => 'inventory', 'debit' => 1.5]]],
    )))->toThrow(InvalidArgumentException::class);

    expect(AccountingEvent::query()->count())->toBe(0);
});

test('ADR-104 sumber, tanggal, dan revisi tidak sah ditolak', function (Closure $call) {
    DB::transaction($call);
})->with([
    'tipe sumber' => [fn () => AccountingOutbox::record(EventCode::InventoryAdjusted, new EventSource('Stock Opname', 1), '2026-11-17', adjustedPayload())],
    'id sumber' => [fn () => AccountingOutbox::record(EventCode::InventoryAdjusted, new EventSource('stock_opname', 0), '2026-11-17', adjustedPayload())],
    'tanggal' => [fn () => AccountingOutbox::record(EventCode::InventoryAdjusted, new EventSource('stock_opname', 1), '17/11/2026', adjustedPayload())],
    'revisi' => [fn () => AccountingOutbox::record(EventCode::InventoryAdjusted, new EventSource('stock_opname', 1), '2026-11-17', adjustedPayload(), 0)],
])->throws(InvalidArgumentException::class);

test('ADR-115 PERIOD_LOCKED di dalam transaksi Action → rollback, tidak ada event tertulis', function () {
    setSetting('accounting.lock_date', '2026-10-31');

    expect(fn () => DB::transaction(function () {
        PeriodGuard::assertOpen('2026-10-15');
        AccountingOutbox::record(EventCode::InventoryAdjusted, new EventSource('stock_opname', 1), '2026-10-15', adjustedPayload());
    }))->toThrow(ApiError::class);

    expect(AccountingEvent::query()->count())->toBe(0);
});

// --- Trigger append-only (SQL langsung) ---------------------------------------------------------

test('ADR-104 trigger mengizinkan UPDATE kolom mesin posting: status, journal_entry_id, error', function () {
    $event = recordAdjusted();

    DB::table('accounting_events')->where('id', $event->id)->update(['status' => 'failed', 'error' => 'ACCOUNT_ROLE_UNMAPPED']);
    DB::table('accounting_events')->where('id', $event->id)->update(['status' => 'posted', 'journal_entry_id' => 77, 'error' => null]);

    $row = DB::table('accounting_events')->where('id', $event->id)->first();
    expect($row->status)->toBe('posted')
        ->and((int) $row->journal_entry_id)->toBe(77)
        ->and($row->error)->toBeNull();
});

test('ADR-104 trigger menolak UPDATE kolom lain (SQL langsung)', function (string $column, mixed $value) {
    $event = recordAdjusted();

    expect(fn () => DB::table('accounting_events')->where('id', $event->id)->update([$column => $value]))
        ->toThrow(QueryException::class, 'accounting_events is append-only');
})->with([
    'payload' => ['payload', json_encode(adjustedPayload('1.00'))],
    'event_code' => ['event_code', 'inventory.written_off'],
    'business_date' => ['business_date', '2026-01-01'],
    'source_type' => ['source_type', 'goods_receipt'],
    'source_id' => ['source_id', 99],
    'revision' => ['revision', 2],
    'created_at ke NULL' => ['created_at', null],
    'id' => ['id', 999999],
]);

test('ADR-104 trigger menolak UPDATE campuran kolom posting + kolom lain', function () {
    $event = recordAdjusted();

    expect(fn () => DB::table('accounting_events')->where('id', $event->id)->update(['status' => 'posted', 'business_date' => '2026-01-01']))
        ->toThrow(QueryException::class, 'accounting_events is append-only');
    expect(DB::table('accounting_events')->where('id', $event->id)->value('status'))->toBe('pending');
});

test('ADR-104 trigger menolak DELETE (SQL langsung)', function () {
    recordAdjusted();

    expect(fn () => DB::table('accounting_events')->delete())
        ->toThrow(QueryException::class, 'accounting_events delete blocked')
        ->and(AccountingEvent::query()->count())->toBe(1);
});

test('ADR-104 model hanya mengizinkan perubahan kolom posting dan menolak hapus', function () {
    $event = recordAdjusted();

    $event->status = EventStatus::Posted;
    $event->journal_entry_id = 5;
    $event->save();
    expect($event->fresh()?->status)->toBe(EventStatus::Posted);

    $event->payload = adjustedPayload('1.00');
    expect(fn () => $event->save())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $event->delete())->toThrow(LogicException::class, 'append-only');
});
