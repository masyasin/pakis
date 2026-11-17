<?php

use Tests\Support\Arch\OutboxGuardScanner;

/*
 * ADR-115 / PF-3 (T-077, 15 §11.4): setiap kelas di app/ (semua domain, bukan hanya
 * Sales/Receivable/Cash/Pos) yang memancarkan event lewat AccountingOutbox wajib memanggil
 * PeriodGuard::assertOpen() dengan tanggal bisnis dokumen. Paket berikutnya tidak membuat arch
 * test kedua; cukup pastikan pemancarnya lolos test ini.
 */

function periodGuardAppRoot(): string
{
    return str_replace('\\', '/', dirname(__DIR__, 2).'/app');
}

function periodGuardFixture(string $name): string
{
    return str_replace('\\', '/', dirname(__DIR__).'/Fixtures/Accounting/'.$name.'.php');
}

test('ADR-115 setiap pemancar AccountingOutbox di app/ memanggil PeriodGuard::assertOpen', function () {
    $files = array_values(array_filter(
        OutboxGuardScanner::phpFiles(periodGuardAppRoot()),
        // Outbox sendiri tidak memancarkan event dokumen.
        fn (string $f) => ! str_ends_with($f, '/Domain/Accounting/Outbox/AccountingOutbox.php'),
    ));

    expect(count($files))->toBeGreaterThan(100)
        ->and(OutboxGuardScanner::violations($files))->toBe([]);
});

test('ADR-104 di luar domain Accounting tidak ada yang menyentuh accounting_events langsung (model & nama tabel)', function () {
    // Pemancar wajib lewat AccountingOutbox::record (validasi kontrak payload); domain Accounting
    // (mesin posting Paket F) satu-satunya yang membaca/mengubah status event.
    $outside = array_filter(
        OutboxGuardScanner::phpFiles(periodGuardAppRoot()),
        fn (string $f) => ! str_contains($f, '/Domain/Accounting/'),
    );

    $offenders = array_values(array_filter($outside, function (string $f) {
        $found = OutboxGuardScanner::inspect($f);

        return $found['event_model'] || $found['table_name'];
    }));

    expect($offenders)->toBe([]);
});

/**
 * Varian sintaks yang dirapikan Pint (impor grup, nama lengkap) tidak bisa disimpan sebagai
 * fixture, jadi ditulis ke berkas sementara.
 */
function periodGuardTempFixture(string $code): string
{
    $file = sys_get_temp_dir().'/pakis-arch-'.bin2hex(random_bytes(4)).'.php';
    file_put_contents($file, "<?php\n\nnamespace Tests\\Fixtures\\Accounting;\n\n".$code);

    return str_replace('\\', '/', $file);
}

test('ADR-115 pemindai mendeteksi fixture pelanggar (statis, alias + instance, impor grup, nama lengkap)', function () {
    $grouped = periodGuardTempFixture(<<<'PHP'
        use App\Domain\Accounting\{Outbox\AccountingOutbox as Box, Outbox\EventCode, Period\PeriodGuard};

        final class GroupedViolation
        {
            public function handle(): void
            {
                Box::record(EventCode::InventoryAdjusted, new \App\Domain\Accounting\Outbox\EventSource('x', 1), '2026-11-17', ['lines' => []]);
            }
        }
        PHP);
    $qualified = periodGuardTempFixture(<<<'PHP'
        final class QualifiedViolation
        {
            public function handle(): void
            {
                \App\Domain\Accounting\Period\PeriodGuard::isOpen('2026-11-17');
                \App\Domain\Accounting\Outbox\AccountingOutbox::record(...);
            }
        }
        PHP);
    $groupedCompliant = periodGuardTempFixture(<<<'PHP'
        use App\Domain\Accounting\{Outbox\AccountingOutbox, Period\PeriodGuard as Guard};

        final class GroupedCompliant
        {
            public function handle(string $date): void
            {
                Guard::assertOpen($date);
                app(AccountingOutbox::class)::record(...);
            }
        }
        PHP);

    try {
        $violating = [periodGuardFixture('ViolatingOutboxAction'), periodGuardFixture('AliasedViolatingOutboxAction'), $grouped, $qualified];

        expect(OutboxGuardScanner::violations([...$violating, periodGuardFixture('CompliantOutboxAction'), $groupedCompliant]))->toBe($violating);
    } finally {
        array_map('unlink', [$grouped, $qualified, $groupedCompliant]);
    }
});

test('ADR-115 pemindai tidak terkecoh komentar, string, atau PeriodGuard tanpa assertOpen', function () {
    expect(OutboxGuardScanner::inspect(periodGuardFixture('ViolatingOutboxAction')))->toMatchArray(['outbox' => true, 'guard' => false])
        ->and(OutboxGuardScanner::inspect(periodGuardFixture('AliasedViolatingOutboxAction')))->toMatchArray(['outbox' => true, 'guard' => false])
        ->and(OutboxGuardScanner::inspect(periodGuardFixture('CompliantOutboxAction')))->toMatchArray(['outbox' => true, 'guard' => true]);
});

test('ADR-115 pemindai mendeteksi pelanggar di folder domain mana pun (simulasi berkas app/Domain baru)', function () {
    $dir = sys_get_temp_dir().'/pakis-arch-'.bin2hex(random_bytes(4)).'/Domain/Sales/Actions';
    mkdir($dir, 0777, true);
    copy(periodGuardFixture('ViolatingOutboxAction'), $dir.'/PostInvoice.php');
    copy(periodGuardFixture('CompliantOutboxAction'), $dir.'/PostReceipt.php');

    try {
        $violations = OutboxGuardScanner::violations(OutboxGuardScanner::phpFiles(dirname($dir, 3)));
        expect($violations)->toHaveCount(1)
            ->and($violations[0])->toEndWith('/Domain/Sales/Actions/PostInvoice.php');
    } finally {
        array_map('unlink', glob($dir.'/*.php') ?: []);
        rmdir($dir);
        rmdir(dirname($dir));
        rmdir(dirname($dir, 2));
        rmdir(dirname($dir, 3));
    }
});
