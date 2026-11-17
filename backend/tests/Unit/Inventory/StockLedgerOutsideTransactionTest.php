<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\Exceptions\StockConflict;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockReservation;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * T-077 kriteria "pemanggilan di luar transaksi melempar LogicException" + retry deadlock BR-101.
 * TANPA RefreshDatabase: test ber-RefreshDatabase selalu di dalam transaksi, jadi tidak bisa menguji
 * transactionLevel() = 0. Tidak ada data yang ditulis (penolakan terjadi sebelum query apa pun).
 */

uses(TestCase::class);

/** QueryException tiruan dengan kode driver tertentu (1213 = deadlock MariaDB). */
function ledgerQueryException(int $driverCode, string $sqlState = 'HY000'): QueryException
{
    $pdo = new PDOException("SQLSTATE[{$sqlState}]: uji {$driverCode}");
    $pdo->errorInfo = [$sqlState, $driverCode, 'uji'];

    return new QueryException('mariadb', 'select 1', [], $pdo);
}

test('ADR-103 setiap method StockLedger di luar transaksi melempar LogicException', function () {
    expect(DB::transactionLevel())->toBe(0);

    $ledger = app(StockLedger::class);
    $location = (new StockLocation)->forceFill(['id' => 1]);
    $product = (new Product)->forceFill(['id' => 1]);
    $batch = (new StockBatch)->newFromBuilder(['id' => 1, 'batch_no' => '-', 'stock_location_id' => 1, 'product_id' => 1]);
    $reservation = (new StockReservation)->forceFill(['id' => 1, 'stock_batch_id' => 1]);
    $source = new Source('goods_receipt', 1);

    $calls = [
        'receive' => fn () => $ledger->receive($location, $product, BatchKey::none(), Qty::of(1), UnitCost::of(1), $source, '2026-11-16'),
        'issue' => fn () => $ledger->issue($batch, Qty::of(1), $source, MovementType::Sale, '2026-11-16'),
        'move' => fn () => $ledger->move($batch, $location, Qty::of(1), $source, MovementType::TransferOut, MovementType::TransferIn, '2026-11-16'),
        'reserve' => fn () => $ledger->reserve($batch, Qty::of(1), $source),
        'consume' => fn () => $ledger->consume($reservation, MovementType::Sale, '2026-11-16'),
        'release' => fn () => $ledger->release($reservation),
        'adjust' => fn () => $ledger->adjust($batch, Qty::of(1), $source, null, '2026-11-16'),
        'setStatus' => fn () => $ledger->setStatus($batch, StockBatchStatus::Quarantine),
    ];
    foreach ($calls as $method => $call) {
        expect($call)->toThrow(LogicException::class, 'wajib dipanggil di dalam transaksi', $method);
    }
});

test('BR-101 deadlock diulang maks 3 kali lalu StockConflict (409 STATE_CONFLICT)', function () {
    $attempts = 0;
    try {
        StockLedger::transaction(function () use (&$attempts) {
            $attempts++;
            throw ledgerQueryException(1213, '40001');
        });
        $this->fail('Harus StockConflict');
    } catch (StockConflict $e) {
        expect($attempts)->toBe(1 + StockLedger::MAX_RETRIES)
            ->and($e->errorCode)->toBe(ErrorCode::STATE_CONFLICT)
            ->and($e->status())->toBe(409)
            ->and($e->getPrevious())->toBeInstanceOf(QueryException::class);
    }
});

test('BR-101 deadlock sekali lalu berhasil → hasil percobaan kedua; galat lain tidak diulang', function () {
    $attempts = 0;
    $result = StockLedger::transaction(function () use (&$attempts) {
        if (++$attempts === 1) {
            throw ledgerQueryException(1213);
        }

        return 'ok';
    });
    expect($result)->toBe('ok')->and($attempts)->toBe(2);

    $other = 0;
    try {
        StockLedger::transaction(function () use (&$other) {
            $other++;
            throw ledgerQueryException(1062, '23000');
        });
        $this->fail('Galat non-deadlock harus diteruskan');
    } catch (QueryException) {
        // diharapkan
    }
    expect($other)->toBe(1)->and(DB::transactionLevel())->toBe(0);
});
