<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\SecurityLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * SEC-040 / SEC-041: audit & log keamanan append-only, dijaga trigger di level database.
 */

beforeEach(function () {
    app(AuditLogger::class)->record('uji.dibuat', null, null, ['nilai' => 1]);
    app(SecurityLogger::class)->record(SecurityEventType::LoginFailed, SecurityChannel::Web, identifier: 'agus');
});

test('SEC-040 UPDATE langsung ke audit_logs ditolak database', function () {
    expect(fn () => DB::table('audit_logs')->update(['event' => 'dipalsukan']))
        ->toThrow(QueryException::class, 'audit_logs is append-only');
});

test('SEC-040 DELETE langsung ke audit_logs ditolak database', function () {
    expect(fn () => DB::table('audit_logs')->delete())
        ->toThrow(QueryException::class, 'audit_logs delete blocked');
});

test('SEC-041 UPDATE dan DELETE ke security_events ditolak database', function () {
    expect(fn () => DB::table('security_events')->update(['identifier' => 'x']))
        ->toThrow(QueryException::class, 'security_events is append-only')
        ->and(fn () => DB::table('security_events')->delete())
        ->toThrow(QueryException::class, 'security_events delete blocked');
});

test('SEC-040 model menolak mengubah atau menghapus baris lama', function () {
    $log = AuditLog::query()->firstOrFail();

    $log->event = 'dipalsukan';
    expect(fn () => $log->save())->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});

test('isi tetap utuh setelah upaya perubahan gagal', function () {
    rescue(fn () => DB::table('audit_logs')->update(['event' => 'dipalsukan']), report: false);

    expect(DB::table('audit_logs')->pluck('event')->all())->toBe(['uji.dibuat']);
});
