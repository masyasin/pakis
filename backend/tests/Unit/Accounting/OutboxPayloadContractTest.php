<?php

use App\Domain\Accounting\Outbox\AccountingOutbox;
use App\Domain\Accounting\Outbox\EventCode;

/*
 * Kontrak payload event outbox Paket A (14 §7 aturan 1, 15 §11.4, ADR-104): peran dari katalog
 * 20 §4.2.1, nilai string desimal tanpa float, kualifier lokasi & produk per baris, refs wajib.
 * Satu contoh valid per event + pelanggaran per aturan.
 */

/** Baris jurnal valid untuk event inventory.* (lokasi stok 3, produk 7). */
function outboxLine(string $role, string $side, string $amount, array $extra = []): array
{
    return ['role' => $role, $side => $amount, 'stock_location_id' => 3, 'product_id' => 7, ...$extra];
}

/** @return array<string, mixed> contoh payload valid per event */
function validOutboxPayload(EventCode $code): array
{
    return match ($code) {
        EventCode::InventoryGoodsReceived => [
            'lines' => [
                outboxLine('inventory', 'debit', '1250000.00', ['qty_base' => '100.000', 'stock_batch_id' => 11]),
                outboxLine('goods_received_not_invoiced', 'credit', '1250000.00'),
            ],
            'refs' => ['supplier_id' => 5, 'purchase_order_id' => 9],
        ],
        EventCode::InventoryPurchaseReturned => [
            'lines' => [
                outboxLine('goods_received_not_invoiced', 'debit', '120000.00'),
                outboxLine('inventory_variance', 'debit', '5000.00'),
                outboxLine('inventory', 'credit', '125000.00'),
            ],
            'refs' => ['supplier_id' => 5],
        ],
        EventCode::InventoryAdjusted => [
            'lines' => [outboxLine('inventory', 'debit', '0.50'), outboxLine('inventory_variance', 'credit', '0.50')],
        ],
        EventCode::InventoryWrittenOff, EventCode::InventoryTransferVariance => [
            'lines' => [outboxLine('inventory_loss', 'debit', '75000.00'), outboxLine('inventory', 'credit', '75000.00')],
            'refs' => $code === EventCode::InventoryTransferVariance ? ['from_stock_location_id' => 3, 'to_stock_location_id' => 4] : [],
        ],
        EventCode::InventoryOpeningBalance => [
            'lines' => [outboxLine('inventory', 'debit', '9999999999999999.99'), outboxLine('opening_balance_equity', 'credit', '9999999999999999.99')],
        ],
        EventCode::InventoryTransferred => [
            'lines' => [],
            'refs' => [
                'from_stock_location_id' => 3,
                'to_stock_location_id' => 4,
                'items' => [['product_id' => 7, 'qty_base' => '10.000', 'value' => '125000.00']],
            ],
            'memo' => 'Mutasi diterima',
        ],
    };
}

test('ADR-104 contoh payload valid diterima untuk setiap event Paket A', function (EventCode $code) {
    AccountingOutbox::assertPayload($code, validOutboxPayload($code));

    expect(true)->toBeTrue();
})->with(EventCode::cases());

test('ADR-104 katalog event hanya inventory.* Paket A dengan peran dari 20 §4.2.1', function () {
    $paketARoles = ['inventory', 'goods_received_not_invoiced', 'payable', 'inventory_variance', 'inventory_loss', 'opening_balance_equity'];

    expect(array_map(fn (EventCode $c) => $c->value, EventCode::cases()))->toBe([
        'inventory.goods_received', 'inventory.purchase_returned', 'inventory.adjusted', 'inventory.written_off',
        'inventory.transferred', 'inventory.opening_balance', 'inventory.transfer_variance',
    ]);
    foreach (EventCode::cases() as $code) {
        expect(array_diff($code->roles(), $paketARoles))->toBe([], "Peran {$code->value}");
    }
});

/**
 * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
 */
function outboxRejects(EventCode $code, callable $mutate, string $message): void
{
    expect(fn () => AccountingOutbox::assertPayload($code, $mutate(validOutboxPayload($code))))
        ->toThrow(InvalidArgumentException::class, $message);
}

test('ADR-104 setiap baris inventory.* wajib membawa stock_location_id & product_id (15 §11.4)', function (EventCode $code, string $key) {
    outboxRejects($code, function (array $p) use ($key) {
        unset($p['lines'][0][$key]);

        return $p;
    }, "`{$key}` wajib berupa ID positif");

    outboxRejects($code, function (array $p) use ($key) {
        $p['lines'][1][$key] = '3'; // ID sebagai string bukan ID

        return $p;
    }, "`{$key}` wajib berupa ID positif");
})->with(array_filter(EventCode::cases(), fn (EventCode $c) => $c->journaled()))->with(['stock_location_id', 'product_id']);

test('ADR-104 refs.supplier_id wajib untuk goods_received & purchase_returned', function (EventCode $code) {
    outboxRejects($code, function (array $p) {
        unset($p['refs']['supplier_id']);

        return $p;
    }, '`refs.supplier_id` wajib');
})->with([EventCode::InventoryGoodsReceived, EventCode::InventoryPurchaseReturned]);

test('ADR-104 refs.from/to_stock_location_id wajib untuk transferred & transfer_variance', function (EventCode $code, string $key) {
    outboxRejects($code, function (array $p) use ($key) {
        unset($p['refs'][$key]);

        return $p;
    }, "`refs.{$key}` wajib");
})->with([EventCode::InventoryTransferred, EventCode::InventoryTransferVariance])
    ->with(['from_stock_location_id', 'to_stock_location_id']);

test('ADR-104 inventory.transferred tanpa jurnal: lines wajib kosong', function () {
    outboxRejects(EventCode::InventoryTransferred, function (array $p) {
        $p['lines'] = [outboxLine('inventory', 'debit', '1.00')];

        return $p;
    }, 'wajib `lines: []`');
});

test('ADR-104 event berjurnal wajib punya baris', function () {
    outboxRejects(EventCode::InventoryAdjusted, fn (array $p) => [...$p, 'lines' => []], 'wajib punya baris');
});

test('ADR-104 peran di luar katalog event ditolak', function (string $role) {
    outboxRejects(EventCode::InventoryGoodsReceived, function (array $p) use ($role) {
        $p['lines'][1]['role'] = $role;

        return $p;
    }, 'tidak dikenal untuk event ini');
})->with(['payable', 'receivable', 'Inventory', '']);

test('ADR-101 nilai baris wajib string desimal berskala 2, tanpa float', function (mixed $amount) {
    outboxRejects(EventCode::InventoryAdjusted, function (array $p) use ($amount) {
        $p['lines'][0]['debit'] = $amount;

        return $p;
    }, 'debit');
})->with([
    'float' => 1250000.5,
    'int' => 1250000,
    'tanpa sen' => '1250000',
    'satu digit sen' => '1250000.5',
    'tiga digit sen' => '1250000.000',
    'negatif' => '-5.00',
    'format id-ID' => '1.250.000,00',
    'terlalu panjang' => '12345678901234567.00',
]);

test('ADR-101 float di mana pun dalam payload ditolak (termasuk refs)', function () {
    outboxRejects(EventCode::InventoryTransferred, function (array $p) {
        $p['refs']['items'][0]['qty_base'] = 10.0;

        return $p;
    }, 'nilai float di `refs.items.0.qty_base`');
});

test('ADR-104 baris wajib tepat satu dari debit atau credit', function (array $sides) {
    outboxRejects(EventCode::InventoryAdjusted, function (array $p) use ($sides) {
        $p['lines'][0] = ['role' => 'inventory', 'stock_location_id' => 3, 'product_id' => 7, ...$sides];

        return $p;
    }, 'tepat satu dari `debit` atau `credit`');
})->with([
    'keduanya' => [['debit' => '1.00', 'credit' => '1.00']],
    'tidak ada' => [[]],
]);

test('ADR-104 struktur payload: kunci atas dikenal, lines berupa daftar, refs berupa objek', function (array $payload, string $message) {
    expect(fn () => AccountingOutbox::assertPayload(EventCode::InventoryAdjusted, $payload))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'kunci tak dikenal' => [['lines' => [], 'accounts' => []], 'kunci tidak dikenal'],
    'tanpa lines' => [['refs' => []], '`lines` wajib berupa daftar'],
    'lines objek' => [['lines' => ['a' => []]], '`lines` wajib berupa daftar'],
    'refs daftar' => [['lines' => [], 'refs' => [1, 2]], '`refs` harus objek'],
    'memo bukan string' => [['lines' => [], 'memo' => 5], '`memo` harus string'],
    'baris bukan objek' => [['lines' => ['inventory']], 'baris harus objek'],
]);
