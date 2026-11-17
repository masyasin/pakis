<?php

use App\Support\Phone\PhoneNormalizer;

/*
 * BR-166(1) / SEC-108 (T-073): satu normalizer HP bersama di `App\Support\Phone` untuk Employee,
 * akun, dan kios. Perilaku normalize()/forSearch() diuji di EmployeeSupportTest (BR-050).
 */

test('BR-166(1) PhoneNormalizer satu-satunya di app/: kelas lama di Domain\Employee\Support sudah tidak ada', function () {
    // Unit test tanpa aplikasi Laravel: app_path() tidak tersedia.
    $app = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'app';
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() === 'php' && preg_match('/\b(class|final class)\s+PhoneNormalizer\b/', (string) file_get_contents($file->getPathname())) === 1) {
            $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($app) + 1));
        }
    }

    expect($found)->toBe(['Support/Phone/PhoneNormalizer.php'])
        ->and(class_exists('App\Domain\Employee\Support\PhoneNormalizer'))->toBeFalse();
});

test('SEC-108 PhoneNormalizer::mask: 4 digit awal + 5 titik + 3 digit akhir, panjang asli tidak terbaca', function (?string $input, ?string $expected) {
    expect(PhoneNormalizer::mask($input))->toBe($expected);
})->with([
    'nomor 12 digit' => ['628123456789', '0812•••••789'],
    'nomor 13 digit' => ['6281234567890', '0812•••••890'],
    'nomor 10 digit' => ['6281234567', '0812•••••567'],
    'data lama pendek' => ['0812345', '•••••'],
    'kosong' => ['', null],
    'null' => [null, null],
]);

test('BR-166(1) PhoneNormalizer::forInput: kosong → null, sah → 62…, tak sah dikembalikan tanpa pemisah', function (mixed $input, mixed $expected) {
    expect(PhoneNormalizer::forInput($input))->toBe($expected);
})->with([
    'format 08' => ['0812-3456-7890', '6281234567890'],
    'format +62' => ['+62 812 3456 7890', '6281234567890'],
    'spasi saja' => ['   ', null],
    'tak sah' => ['12 34', '1234'],
    'bukan string' => [null, null],
]);

test('BR-166(1) STORED_PATTERN cocok dengan semua hasil normalize() dan menolak bentuk mentah', function () {
    foreach (['0812345678', '08123456789012', '+6281234567890'] as $raw) {
        expect(preg_match(PhoneNormalizer::STORED_PATTERN, (string) PhoneNormalizer::normalize($raw)))->toBe(1);
    }

    expect(preg_match(PhoneNormalizer::STORED_PATTERN, '081234567890'))->toBe(0)
        ->and(preg_match(PhoneNormalizer::STORED_PATTERN, '+6281234567890'))->toBe(0);
});
