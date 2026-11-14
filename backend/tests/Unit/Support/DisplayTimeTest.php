<?php

use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Zona tampilan dibaca dari setting (DB) sejak T-008.
uses(TestCase::class, RefreshDatabase::class);

test('waktu UTC ditampilkan dalam zona tampilan', function () {
    setSetting('general.timezone', 'Asia/Jakarta');

    $stored = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC');
    $local = DisplayTime::toLocal($stored);

    expect($local->format('Y-m-d H:i'))->toBe('2026-01-01 07:00')
        ->and($local->getTimezone()->getName())->toBe('Asia/Jakarta');
});

test('konversi tidak mengubah objek asal (nilai simpanan tetap UTC)', function () {
    setSetting('general.timezone', 'Asia/Jayapura');

    $stored = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC');
    DisplayTime::toLocal($stored);

    expect($stored->getTimezone()->getName())->toBe('UTC');
});

test('zona penyimpanan aplikasi adalah UTC', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC');
});

test('sesi database berjalan di UTC', function () {
    $offset = DB::selectOne('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS diff')->diff;

    expect((int) $offset)->toBe(0);
});
