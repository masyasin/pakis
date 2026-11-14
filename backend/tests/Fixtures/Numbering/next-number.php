<?php

/*
 * Dipanggil sebagai PROSES TERPISAH oleh tests/Concurrency/NumberGeneratorConcurrencyTest.
 * Boot aplikasi Laravel, ambil satu nomor di transaksinya sendiri, cetak nomornya ke stdout.
 *
 *     php tests/Fixtures/Numbering/next-number.php <kode>
 */

use App\Domain\Numbering\NumberGenerator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$code = $argv[1] ?? 'employee';

echo DB::transaction(fn () => $app->make(NumberGenerator::class)->next($code));
