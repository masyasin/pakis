<?php

use App\Domain\Numbering\Enums\ResetPeriod;
use App\Domain\Numbering\Models\DocumentSequence;
use App\Domain\Numbering\NumberGenerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
 * Kriteria T-009 / ADR-009: proses yang benar-benar paralel tidak pernah mendapat nomor sama.
 *
 * Suite ini TANPA RefreshDatabase: proses lain hanya melihat data yang sudah di-commit, dan lock
 * baris hanya teruji bila ada transaksi lain yang bersaing. Data dibersihkan sendiri di akhir.
 */

uses(TestCase::class);

const STRESS_CODE = 'uji_konkurensi';

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    DB::table('document_sequence_counters')->whereIn('document_sequence_id', DocumentSequence::query()->where('code', STRESS_CODE)->select('id'))->delete();
    DocumentSequence::query()->where('code', STRESS_CODE)->delete();
    DocumentSequence::query()->create([
        'code' => STRESS_CODE, 'name' => 'Uji konkurensi', 'pattern' => 'UJI-{SEQ:4}', 'reset_period' => ResetPeriod::Never,
    ]);
});

afterEach(function () {
    $ids = DocumentSequence::query()->where('code', STRESS_CODE)->pluck('id');
    DB::table('document_sequence_counters')->whereIn('document_sequence_id', $ids)->delete();
    DocumentSequence::query()->whereIn('id', $ids)->delete();
});

test('ADR-009 50 proses paralel → 50 nomor unik berurutan', function () {
    // Proses anak memakai koneksi DB yang sama dengan test ini (DB test, bukan DB dev).
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => config('database.connections.'.config('database.default').'.host'),
        'DB_PORT' => (string) config('database.connections.'.config('database.default').'.port'),
        'DB_DATABASE' => config('database.connections.'.config('database.default').'.database'),
        'DB_USERNAME' => config('database.connections.'.config('database.default').'.username'),
        'DB_PASSWORD' => (string) config('database.connections.'.config('database.default').'.password'),
        'CACHE_STORE' => 'array',
    ];

    $processes = [];
    foreach (range(1, 50) as $i) {
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/Numbering/next-number.php'), STRESS_CODE], base_path(), $env, null, 120);
        $process->start();
        $processes[] = $process;
    }

    $numbers = [];
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $numbers[] = trim($process->getOutput());
    }

    sort($numbers);
    $expected = array_map(fn ($i) => sprintf('UJI-%04d', $i), range(1, 50));

    expect($numbers)->toBe($expected);
});

test('ADR-009 di luar transaksi pemanggil ditolak', function () {
    expect(DB::transactionLevel())->toBe(0)
        ->and(fn () => app(NumberGenerator::class)->next(STRESS_CODE))
        ->toThrow(LogicException::class, 'wajib dipanggil di dalam transaksi');
});
