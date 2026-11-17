<?php

use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\CostCalculator;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockMovement;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\TransitStockLocationSeeder;
use Tests\Support\LedgerWorld;
use Tests\Support\StockWorld;

/*
 * T-077 — HPP moving average (BR-114, BR-159). Data dummy. Semua angka lewat Qty/UnitCost (string),
 * tidak ada float.
 */

beforeEach(function () {
    $this->seed([ReferenceSeeder::class, TransitStockLocationSeeder::class]);
    $this->gd = LedgerWorld::warehouse('GD-A');
    $this->product = LedgerWorld::product('P-01');
});

function hpp(): string
{
    return ProductCost::query()->findOrFail(test()->product->id)->avg_cost;
}

test('BR-159 stok 0 → HPP = biaya terima; mutasi terima ber-unit_cost biaya beli', function () {
    LedgerWorld::receive($this->gd, $this->product, '10', '1000');

    expect(hpp())->toBe('1000.0000')
        ->and(ProductCost::query()->findOrFail($this->product->id)->qty_basis)->toBe('10.000')
        ->and(StockMovement::query()->sole()->unit_cost)->toBe('1000.0000');
});

test('BR-159 10 @1.000 + 10 @1.200 = 1.100,0000', function () {
    LedgerWorld::receive($this->gd, $this->product, '10', '1000');
    LedgerWorld::receive($this->gd, $this->product, '10', '1200', new BatchKey('B-002', '2028-01-31'));

    expect(hpp())->toBe('1100.0000');
});

test('BR-159 qty_basis = total seluruh lokasi termasuk TRANSIT & karantina', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '10', '1000');
    $ledger = LedgerWorld::ledger();
    $ledger->move($batch, StockWorld::transit(), Qty::of(4), LedgerWorld::source('stock_transfer', 1), MovementType::TransferOut, MovementType::TransferIn, LedgerWorld::DATE);
    $ledger->move($batch, LedgerWorld::quarantineBin($this->gd), Qty::of(3), LedgerWorld::source('stock_quarantine', 1), MovementType::BinMoveOut, MovementType::BinMoveIn, LedgerWorld::DATE);

    // Pindah lokasi/rak tidak mengubah HPP; basis tetap 10 walau hanya 3 yang available di GD-A.
    expect(hpp())->toBe('1000.0000');

    LedgerWorld::receive(LedgerWorld::warehouse('GD-B'), $this->product, '10', '1200');
    expect(hpp())->toBe('1100.0000')
        ->and(ProductCost::query()->findOrFail($this->product->id)->qty_basis)->toBe('20.000');
});

test('BR-159 presisi 4 desimal, pembulatan half-up di akhir (bukan half-even)', function () {
    LedgerWorld::receive($this->gd, $this->product, '1', '1000');
    LedgerWorld::receive($this->gd, $this->product, '1', '1000.0001');
    // (1000 + 1000.0001) / 2 = 1000.00005 → half-up 1000.0001
    expect(hpp())->toBe('1000.0001');

    expect((string) CostCalculator::average(Qty::of('3'), UnitCost::of('1000'), Qty::of('7'), UnitCost::of('1000.0001')))->toBe('1000.0001')
        ->and((string) CostCalculator::average(Qty::of('3'), UnitCost::of('10'), Qty::of('1'), UnitCost::of('11')))->toBe('10.2500')
        ->and((string) CostCalculator::average(Qty::of('0.003'), UnitCost::of('1'), Qty::of('3'), UnitCost::of('2')))->toBe('1.9990');
});

test('BR-159 mutasi keluar memakai HPP saat itu; stok habis lalu terima → HPP = biaya terima baru', function () {
    $batch = LedgerWorld::receive($this->gd, $this->product, '10', '1000');
    LedgerWorld::receive($this->gd, $this->product, '10', '1200', new BatchKey('B-002', '2028-01-31'));

    $out = LedgerWorld::ledger()->issue($batch, Qty::of(10), LedgerWorld::source('sales_invoice', 1), MovementType::Sale, LedgerWorld::DATE);
    expect($out->movement()->unit_cost)->toBe('1100.0000');

    $b2 = $batch->newQuery()->where('batch_no', 'B-002')->sole();
    LedgerWorld::ledger()->issue($b2, Qty::of(10), LedgerWorld::source('sales_invoice', 2), MovementType::Sale, LedgerWorld::DATE);

    LedgerWorld::receive($this->gd, $this->product, '5', '900', new BatchKey('B-003', '2028-06-30'));
    expect(hpp())->toBe('900.0000');
});

test('BR-159 biaya beli batch yang diterima ulang = rata-rata tertimbang (telusur retur prinsipal)', function () {
    LedgerWorld::receive($this->gd, $this->product, '10', '1000');
    $batch = LedgerWorld::receive($this->gd, $this->product, '10', '1200');

    expect($batch->receipt_unit_cost)->toBe('1100.0000');
});

test('BR-159 biaya terima negatif ditolak', function () {
    expect(fn () => LedgerWorld::receive($this->gd, $this->product, '1', '-1'))->toThrow(InvalidArgumentException::class);
});
