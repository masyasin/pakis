<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * SEC-040: retensi lewat audit:purge, tanpa membuka celah append-only setelahnya.
 */

beforeEach(function () {
    config(['pakis.retention.audit_years' => 5, 'pakis.retention.security_days' => 365]);

    $this->travelTo(now()->subYears(6));
    app(AuditLogger::class)->record('lama.sekali');
    $this->travelBack();

    $this->travelTo(now()->subDays(400));
    app(SecurityLogger::class)->record(SecurityEventType::LoginFailed, SecurityChannel::Web);
    $this->travelBack();

    app(AuditLogger::class)->record('baru');
    app(SecurityLogger::class)->record(SecurityEventType::LoginSuccess, SecurityChannel::Web);
});

test('SEC-040 audit:purge hanya menghapus yang melewati masa simpan', function () {
    $this->artisan('audit:purge')->assertSuccessful();

    expect(DB::table('audit_logs')->pluck('event')->all())->toBe(['baru'])
        ->and(DB::table('security_events')->pluck('event')->all())->toBe(['login_success']);
});

test('SEC-040 setelah purge, penghapusan biasa kembali ditolak', function () {
    $this->artisan('audit:purge')->assertSuccessful();

    expect(DB::selectOne('SELECT @audit_purge AS flag')->flag)->toBeNull()
        ->and(fn () => DB::table('audit_logs')->delete())->toThrow(QueryException::class, 'delete blocked');
});

test('purge terjadwal harian pukul 02.30 waktu lokal', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'audit:purge'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 2 * * *')
        ->and($event->timezone)->toBe('Asia/Jakarta');
});
