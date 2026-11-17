<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductRegistration;
use App\Domain\Purchasing\Enums\ReceiptLineStatus;
use App\Domain\Purchasing\Support\ReceiptLineRules;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;

/*
 * T-080 — aturan murni baris penerimaan (BR-103, BR-158(4), BR-159). Tanpa DB.
 */

test('BR-103 sisa umur bulan 1 desimal tanpa float', function (string $received, ?string $expiry, ?string $expected) {
    expect(ReceiptLineRules::shelfLifeMonths($received, $expiry))->toBe($expected);
})->with([
    'tanpa ED' => ['2026-11-17', null, null],
    'tepat 4 bulan' => ['2026-11-17', '2027-03-17', '4.0'],
    '6 bulan kurang sehari' => ['2026-11-17', '2027-05-16', '5.9'],
    'setengah bulan' => ['2026-11-01', '2026-11-16', '0.5'],
    'akhir bulan' => ['2026-01-31', '2026-02-28', '0.9'],
    'dua tahun' => ['2026-11-17', '2028-11-17', '24.0'],
    'sudah lewat' => ['2026-11-17', '2026-10-17', '-1.0'],
]);

test('BR-103 ED ≤ tanggal terima → BATCH_EXPIRED_ON_RECEIPT; sehari sesudah lolos', function () {
    foreach (['2026-11-17', '2026-11-16'] as $expiry) {
        try {
            ReceiptLineRules::assertNotExpired('2026-11-17', $expiry);
            $this->fail('Harus ditolak');
        } catch (ApiError $e) {
            expect($e->errorCode)->toBe(ErrorCode::BATCH_EXPIRED_ON_RECEIPT)->and($e->status())->toBe(422);
        }
    }
    ReceiptLineRules::assertNotExpired('2026-11-17', '2026-11-18');
    ReceiptLineRules::assertNotExpired('2026-11-17', null);
    expect(true)->toBeTrue();
});

test('BR-103 BR-158(4) status baris: izin tidak cocok didahulukan; batas produk menggantikan setting; 0 = tanpa batas', function () {
    $product = (new Product)->forceFill(['requires_registration' => true, 'min_shelf_life_months' => null]);
    $active = (new ProductRegistration)->forceFill(['registration_no' => 'RI 01/2026']);

    expect(ReceiptLineRules::status($product, '12.0', 'ri01/2026', $active, 6))->toBe(ReceiptLineStatus::Ok)
        ->and(ReceiptLineRules::status($product, '12.0', 'RI 02/2026', $active, 6))->toBe(ReceiptLineStatus::RegistrationMismatch)
        ->and(ReceiptLineRules::status($product, '12.0', 'RI 01/2026', null, 6))->toBe(ReceiptLineStatus::RegistrationMismatch)
        ->and(ReceiptLineRules::status($product, '2.0', 'RI 02/2026', $active, 6))->toBe(ReceiptLineStatus::RegistrationMismatch)
        ->and(ReceiptLineRules::status($product, '5.9', 'RI 01/2026', $active, 6))->toBe(ReceiptLineStatus::ShortShelfLife)
        ->and(ReceiptLineRules::status($product, '6.0', 'RI 01/2026', $active, 6))->toBe(ReceiptLineStatus::Ok)
        ->and(ReceiptLineRules::status($product, '1.0', 'RI 01/2026', $active, 0))->toBe(ReceiptLineStatus::Ok);

    $own = (new Product)->forceFill(['requires_registration' => false, 'min_shelf_life_months' => 12]);
    expect(ReceiptLineRules::status($own, '8.0', null, null, 6))->toBe(ReceiptLineStatus::ShortShelfLife)
        ->and(ReceiptLineRules::status($own, null, null, null, 6))->toBe(ReceiptLineStatus::Ok);
});

test('BR-159 SEC-104 biaya per satuan dasar dari baris PO: harga × (1 − diskon%) / faktor, 4 desimal half-up', function () {
    expect(ReceiptLineRules::unitCostFromPo('240000.00', '10.0000', '24'))->toBe('9000.0000')
        ->and(ReceiptLineRules::unitCostFromPo('100000.00', '0', '3'))->toBe('33333.3333')
        ->and(ReceiptLineRules::unitCostFromPo('20000.00', '0', '3'))->toBe('6666.6667')
        ->and(ReceiptLineRules::unitCostFromPo('57500.00', '1.0000', '1'))->toBe('56925.0000');
});
