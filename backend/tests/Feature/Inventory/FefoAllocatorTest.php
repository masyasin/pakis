<?php

use App\Domain\Catalog\Models\ProductRegistration;
use App\Domain\Inventory\Allocation\FefoAllocator;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\Exceptions\InsufficientStock;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TransitStockLocationSeeder;
use Tests\Support\LedgerWorld;

/*
 * T-078 — FefoAllocator (BR-102, BR-152 blokir, NFR-A02). Data dummy.
 */

const FEFO_TODAY = '2026-11-17';

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, TransitStockLocationSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 03:00:00', 'UTC')); // 10.00 WIB
    $this->gd = LedgerWorld::warehouse('GD-A');
    $this->product = LedgerWorld::product('P-01');
    $this->allocator = app(FefoAllocator::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Terima batch dengan ED & tanggal terima tertentu. */
function fefoBatch(object $test, string $no, ?string $expiry, string $received = '2026-11-10', string $qty = '10', ?int $binId = null): StockBatch
{
    return LedgerWorld::receive($test->gd, $test->product, $qty, '1000', new BatchKey($no, $expiry, $binId), $received);
}

function fefoAllocate(object $test, string $qty, ?string $asOf = FEFO_TODAY): array
{
    $allocation = $test->allocator->allocate($test->product, $test->gd, Qty::of($qty), $asOf);

    return array_map(fn ($line) => [$line->batch->batch_no, (string) $line->qty], $allocation->lines);
}

test('BR-102 urutan FEFO: ED terdekat, ED kosong terakhir, lalu received_date, lalu id (tabel kasus)', function () {
    fefoBatch($this, 'A', '2027-03-01', '2026-11-10');
    fefoBatch($this, 'B', '2027-01-15', '2026-11-12');
    fefoBatch($this, 'C', null, '2026-11-01');
    fefoBatch($this, 'D', '2027-01-15', '2026-11-05');
    fefoBatch($this, 'E', '2027-01-15', '2026-11-05'); // sama dengan D, id lebih besar

    expect(FefoAllocator::candidates($this->product->id, $this->gd->id, FEFO_TODAY)->pluck('batch_no')->all())
        ->toBe(['D', 'E', 'B', 'A', 'C'])
        ->and(fefoAllocate($this, '25'))->toBe([['D', '10.000'], ['E', '10.000'], ['B', '5.000']])
        ->and(fefoAllocate($this, '50'))->toBe([['D', '10.000'], ['E', '10.000'], ['B', '10.000'], ['A', '10.000'], ['C', '10.000']])
        ->and(fefoAllocate($this, '0.5'))->toBe([['D', '0.500']]);
});

test('BR-102 batch karantina, kedaluwarsa, ED ≤ hari ini, dan qty terreservasi penuh tidak dialokasikan', function () {
    fefoBatch($this, 'OK', '2027-06-01');
    fefoBatch($this, 'KAR', '2026-12-01', binId: LedgerWorld::quarantineBin($this->gd)->id);
    $expired = fefoBatch($this, 'EXP', '2026-12-02');
    fefoBatch($this, 'HARI-INI', FEFO_TODAY);
    $reserved = fefoBatch($this, 'RES', '2026-12-03', qty: '4');
    StockLedger::transaction(function () use ($expired, $reserved) {
        LedgerWorld::ledger()->setStatus($expired, StockBatchStatus::Expired);
        LedgerWorld::ledger()->reserve($reserved, Qty::of('4'), LedgerWorld::source('stock_transfer', 9));
    });

    expect(StockBatch::query()->where('batch_no', 'KAR')->value('status'))->toBe(StockBatchStatus::Quarantine)
        ->and(fefoAllocate($this, '10'))->toBe([['OK', '10.000']]);

    try {
        fefoAllocate($this, '11');
        $this->fail('Stok kurang harus ditolak.');
    } catch (ApiError $e) {
        expect($e->errorCode)->toBe(ErrorCode::INSUFFICIENT_STOCK)
            ->and((string) $e->details['available_base'])->toBe('10.000')
            ->and($e->details['product_id'])->toBe($this->product->id);
    }
});

test('BR-102 inventory.min_sell_days_before_expiry: ED ≤ asOf + N hari dilewati', function () {
    setSetting('inventory.min_sell_days_before_expiry', 30);
    fefoBatch($this, 'H30', '2026-12-17'); // tepat asOf + 30 → dilewati
    fefoBatch($this, 'H31', '2026-12-18');

    expect(fefoAllocate($this, '10'))->toBe([['H31', '10.000']])
        // asOf menentukan batas: 10 hari lebih awal, H30 masih layak.
        ->and(fefoAllocate($this, '20', '2026-11-07'))->toBe([['H30', '10.000'], ['H31', '10.000']]);
});

test('BR-152 produk wajib izin tanpa izin aktif → PRODUCT_REGISTRATION_INACTIVE; izin aktif → dialokasikan', function () {
    fefoBatch($this, 'B1', '2027-06-01');
    $this->product->forceFill(['requires_registration' => true])->save();

    $code = fn () => rescue(fn () => fefoAllocate($this, '1'), fn (Throwable $e) => $e instanceof ApiError ? $e->errorCode : $e, false);
    expect($code())->toBe(ErrorCode::PRODUCT_REGISTRATION_INACTIVE)
        ->and(ErrorCode::PRODUCT_REGISTRATION_INACTIVE->status())->toBe(422);

    // Izin `active` tetapi valid_until kemarin (job belum jalan) tetap tidak berlaku.
    $registration = (new ProductRegistration)->forceFill([
        'product_id' => $this->product->id, 'registration_no' => 'RI-0001', 'valid_until' => '2026-11-16', 'status' => 'active',
    ]);
    $registration->save();
    expect($code())->toBe(ErrorCode::PRODUCT_REGISTRATION_INACTIVE);

    $registration->forceFill(['valid_until' => '2027-12-31'])->save();
    expect(fefoAllocate($this, '1'))->toBe([['B1', '1.000']]);
});

test('BR-102 alokasi read-only dan bisa basi: ledger memvalidasi ulang saat eksekusi', function () {
    $batch = fefoBatch($this, 'B1', '2027-06-01', qty: '5');
    $allocation = $this->allocator->allocate($this->product, $this->gd, Qty::of('5'), FEFO_TODAY);

    // Dokumen lain mengambil 2 lebih dulu; alokasi tadi tidak menahan apa pun.
    StockLedger::transaction(fn () => LedgerWorld::ledger()->issue($batch, Qty::of('2'), LedgerWorld::source('stock_transfer', 1), MovementType::TransferOut, FEFO_TODAY));

    expect(fn () => StockLedger::transaction(fn () => LedgerWorld::ledger()->issue($allocation->lines, null, LedgerWorld::source('stock_transfer', 2), MovementType::TransferOut, FEFO_TODAY)))
        ->toThrow(InsufficientStock::class);
    expect((string) $batch->fresh()->qty_on_hand)->toBe('3.000');

    // Alokasi baru → eksekusi lewat ledger berhasil.
    $fresh = $this->allocator->allocate($this->product, $this->gd, Qty::of('3'), FEFO_TODAY);
    StockLedger::transaction(fn () => LedgerWorld::ledger()->issue($fresh->lines, null, LedgerWorld::source('stock_transfer', 3), MovementType::TransferOut, FEFO_TODAY));
    expect((string) $batch->fresh()->qty_on_hand)->toBe('0.000')
        ->and($fresh->batchIds())->toBe([$batch->id]);
});

test('BR-102 qty alokasi ≤ 0 ditolak', function () {
    expect(fn () => fefoAllocate($this, '0'))->toThrow(InvalidArgumentException::class);
});

test('NFR-A02 alokasi 1 produk pada 200 batch < 100 ms (benchmark longgar)', function () {
    for ($i = 1; $i <= 200; $i++) {
        $expiry = CarbonImmutable::parse('2027-01-01')->addDays($i % 97)->toDateString();
        fefoBatch($this, sprintf('BM-%03d', $i), $i % 25 === 0 ? null : $expiry, qty: '5');
    }
    fefoAllocate($this, '1'); // pemanasan (cache setting, koneksi)

    $start = hrtime(true);
    $allocation = $this->allocator->allocate($this->product, $this->gd, Qty::of('999'), FEFO_TODAY);
    $ms = (hrtime(true) - $start) / 1e6;

    expect(count($allocation->lines))->toBe(200)
        ->and((string) $allocation->lines[199]->qty)->toBe('4.000')
        ->and($ms)->toBeLessThan(100);
});
