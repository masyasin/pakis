<?php

use App\Domain\Accounting\Period\PeriodGuard;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Carbon\CarbonImmutable;

/*
 * ADR-115 / PF-3 (T-077, 15 §11.4): dokumen ber-outbox bertanggal bisnis ≤ accounting.lock_date
 * ditolak 422 PERIOD_LOCKED; tanggal sesudahnya dan lock date kosong lolos.
 */

function periodLockedError(callable $call): ?ApiError
{
    try {
        $call();
    } catch (ApiError $e) {
        return $e;
    }

    return null;
}

test('ADR-115 lock date kosong: semua tanggal terbuka', function () {
    expect(PeriodGuard::lockDate())->toBeNull();

    PeriodGuard::assertOpen('2000-01-01');
    PeriodGuard::assertOpen(CarbonImmutable::parse('2026-11-17'));

    expect(PeriodGuard::isOpen('2000-01-01'))->toBeTrue();
});

test('ADR-115 tanggal sama dengan atau sebelum lock date ditolak PERIOD_LOCKED 422', function (string $date) {
    setSetting('accounting.lock_date', '2026-10-31');

    $error = periodLockedError(fn () => PeriodGuard::assertOpen($date));

    expect($error)->not->toBeNull()
        ->and($error->errorCode)->toBe(ErrorCode::PERIOD_LOCKED)
        ->and($error->status())->toBe(422)
        ->and($error->publicMessage())->toContain('31 Okt 2026')
        ->and($error->details)->toBe([])
        ->and(PeriodGuard::isOpen($date))->toBeFalse();
})->with(['2026-10-31', '2026-10-30', '2025-12-31']);

test('ADR-115 tanggal sesudah lock date lolos', function () {
    setSetting('accounting.lock_date', '2026-10-31');

    PeriodGuard::assertOpen('2026-11-01');
    PeriodGuard::assertOpen(CarbonImmutable::parse('2026-11-01 00:00:00'));

    expect(PeriodGuard::isOpen('2026-11-01'))->toBeTrue()
        ->and(PeriodGuard::lockDate())->toBe('2026-10-31');
});

test('ADR-115 objek tanggal dibandingkan per tanggal kalender, bukan jam', function () {
    setSetting('accounting.lock_date', '2026-10-31');

    // 23.59 di hari kunci tetap hari kunci → ditolak.
    expect(periodLockedError(fn () => PeriodGuard::assertOpen(CarbonImmutable::parse('2026-10-31 23:59:59'))))
        ->not->toBeNull();
});

test('ADR-115 nama field opsional menjadi galat field untuk form web (PA-16)', function () {
    setSetting('accounting.lock_date', '2026-10-31');

    $error = periodLockedError(fn () => PeriodGuard::assertOpen('2026-10-01', 'received_date'));

    expect($error?->details)->toHaveKey('received_date')
        ->and($error?->details['received_date'])->toBe($error?->publicMessage());
});

test('ADR-115 tanggal bisnis tidak sah ditolak sebagai galat pemrograman', function (string $date) {
    PeriodGuard::assertOpen($date);
})->with(['31/10/2026', '2026-02-30', ''])->throws(InvalidArgumentException::class);
