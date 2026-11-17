<?php

use App\Domain\Accounting\Outbox\Models\AccountingEvent;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockMovement;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Database\Seeders\ReferenceSeeder;
use Tests\Fixtures\Inventory\PostReceiptWithOutbox;
use Tests\Support\LedgerWorld;

/*
 * T-077 Bagian C — integrasi StockLedger + AccountingOutbox + PeriodGuard (ADR-104, ADR-115, 15 §11.4,
 * §12.1 "rollback bila outbox gagal"). Action fixture memakai pola wajib Action pemancar.
 */

beforeEach(function () {
    $this->seed([ReferenceSeeder::class]);
    $this->gd = LedgerWorld::warehouse('GD-A');
    $this->product = LedgerWorld::product('P-01');
    $this->post = app(PostReceiptWithOutbox::class);
});

function ledgerNothingWritten(): void
{
    expect(StockMovement::query()->count())->toBe(0)
        ->and(StockBatch::query()->count())->toBe(0)
        ->and(ProductCost::query()->count())->toBe(0)
        ->and(AccountingEvent::query()->count())->toBe(0);
}

test('ADR-115 tanggal bisnis ≤ accounting.lock_date → PERIOD_LOCKED, tidak ada mutasi, batch, HPP, maupun event', function () {
    setSetting('accounting.lock_date', '2026-11-15');

    try {
        $this->post->handle($this->gd, $this->product, '10', '1250', '2026-11-15', 501, 7);
        $this->fail('Harus PERIOD_LOCKED');
    } catch (ApiError $e) {
        expect($e->errorCode)->toBe(ErrorCode::PERIOD_LOCKED)->and($e->status())->toBe(422);
    }
    ledgerNothingWritten();
});

test('ADR-104 sesudah lock date: mutasi & event outbox tertulis dalam transaksi yang sama, tanggal bisnis sama', function () {
    setSetting('accounting.lock_date', '2026-11-15');

    $result = $this->post->handle($this->gd, $this->product, '10', '1250', '2026-11-16', 502, 7);

    $event = AccountingEvent::query()->sole();
    $movement = $result->movement();
    expect($event->event_code)->toBe('inventory.goods_received')
        ->and($event->source_type)->toBe('goods_receipt')
        ->and($event->source_id)->toBe(502)
        ->and($event->business_date->toDateString())->toBe('2026-11-16')
        ->and($movement->business_date->toDateString())->toBe('2026-11-16')
        ->and($event->payload['lines'][0])->toBe(['role' => 'inventory', 'debit' => '12500.00', 'stock_location_id' => $this->gd->id, 'product_id' => $this->product->id])
        ->and($event->payload['lines'][1]['credit'])->toBe('12500.00')
        ->and($event->payload['refs'])->toBe(['supplier_id' => 7]);
});

test('15 §12.1 outbox gagal (payload melanggar kontrak) → mutasi & HPP ikut batal', function () {
    expect(fn () => $this->post->handle($this->gd, $this->product, '10', '1250', '2026-11-16', 503, 7, breakPayload: true))
        ->toThrow(InvalidArgumentException::class);

    ledgerNothingWritten();
});

test('ADR-115 lock date kosong = semua tanggal terbuka', function () {
    $this->post->handle($this->gd, $this->product, '1', '1000', '2020-01-01', 504, 7);

    expect(AccountingEvent::query()->count())->toBe(1)->and(StockMovement::query()->count())->toBe(1);
});
