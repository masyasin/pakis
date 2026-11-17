<?php

use App\Domain\Purchasing\Support\ReturnJournal;

/*
 * T-081 — baris jurnal `inventory.purchase_returned` (BR-159, 15 §11.4). Tanpa DB.
 */

$dims = ['stock_location_id' => 3, 'product_id' => 9];

test('BR-159 biaya batch < HPP: selisih di debit inventory_variance; D = K', function () use ($dims) {
    expect(ReturnJournal::lines('4.000', '1000.0000', '6000.00', $dims))->toBe([
        ['role' => 'goods_received_not_invoiced', 'debit' => '4000.00', ...$dims],
        ['role' => 'inventory_variance', 'debit' => '2000.00', ...$dims],
        ['role' => 'inventory', 'credit' => '6000.00', ...$dims],
    ]);
});

test('BR-159 biaya batch > HPP: selisih di kredit inventory_variance; sama → tanpa baris selisih', function () use ($dims) {
    expect(ReturnJournal::lines('4.000', '2000.0000', '6000.00', $dims))->toBe([
        ['role' => 'goods_received_not_invoiced', 'debit' => '8000.00', ...$dims],
        ['role' => 'inventory', 'credit' => '6000.00', ...$dims],
        ['role' => 'inventory_variance', 'credit' => '2000.00', ...$dims],
    ])->and(ReturnJournal::lines('1.500', '3333.3333', '5000.00', $dims))->toBe([
        ['role' => 'goods_received_not_invoiced', 'debit' => '5000.00', ...$dims],
        ['role' => 'inventory', 'credit' => '5000.00', ...$dims],
    ]);
});

test('BR-159 nilai nol tidak ditulis (biaya batch nol → seluruh nilai ke selisih)', function () use ($dims) {
    expect(ReturnJournal::lines('2.000', '0.0000', '300.00', $dims))->toBe([
        ['role' => 'inventory_variance', 'debit' => '300.00', ...$dims],
        ['role' => 'inventory', 'credit' => '300.00', ...$dims],
    ]);
});
