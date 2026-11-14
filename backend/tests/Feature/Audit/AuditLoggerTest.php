<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

/*
 * SEC-040 / SEC-041: siapa, kapan, dari mana, dan request mana yang tercatat.
 */

test('SEC-040 audit mencatat pelaku, objek, IP, perangkat, dan request_id yang sama dengan header', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create(['name' => 'Lama']);

    Route::middleware('web')->post('/__uji/audit', function () use ($target) {
        app(AuditLogger::class)->record('user.updated', $target,
            ['name' => 'Lama', 'email' => $target->email, 'password' => 'hash-lama'],
            ['name' => 'Baru', 'email' => $target->email, 'password' => 'hash-baru'],
        );

        return response('ok');
    });

    $response = $this->actingAs($actor)
        ->withHeader('User-Agent', 'UjiBrowser/1.0')
        ->post('/__uji/audit');

    $log = AuditLog::query()->sole();

    expect($log->user_id)->toBe($actor->id)
        ->and($log->event)->toBe('user.updated')
        ->and($log->auditable_type)->toBe($target->getMorphClass())
        ->and($log->auditable_id)->toBe($target->id)
        ->and($log->ip)->toBe('127.0.0.1')
        ->and($log->user_agent)->toBe('UjiBrowser/1.0')
        ->and($log->request_id)->toBe($response->headers->get('X-Request-Id'))
        // Hanya kunci yang berubah; password diredaksi; email tidak berubah → tidak dicatat.
        ->and($log->old_values)->toBe(['name' => 'Lama', 'password' => '***'])
        ->and($log->new_values)->toBe(['name' => 'Baru', 'password' => '***']);
});

test('waktu audit disimpan UTC dengan milidetik', function () {
    app(AuditLogger::class)->record('uji.waktu');

    $raw = DB::table('audit_logs')->value('occurred_at');

    expect($raw)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/')
        ->and(abs(now()->diffInSeconds(CarbonImmutable::parse($raw, 'UTC'))))->toBeLessThan(5);
});

test('audit tanpa pengguna login memakai label pelaku', function () {
    app(AuditLogger::class)->record('import.processed', null, null, ['rows' => 10], 'import#12');

    expect(AuditLog::query()->sole())
        ->user_id->toBeNull()
        ->actor_label->toBe('import#12');
});

test('SEC-041 log keamanan mencatat identifier yang dicoba dan meredaksi context', function () {
    app(SecurityLogger::class)->record(
        SecurityEventType::LoginFailed,
        SecurityChannel::Web,
        identifier: 'agus@contoh.test',
        context: ['reason' => 'password_salah', 'password' => 'yang-dicoba'],
    );

    $event = SecurityEvent::query()->sole();

    expect($event->event)->toBe(SecurityEventType::LoginFailed)
        ->and($event->channel)->toBe(SecurityChannel::Web)
        ->and($event->identifier)->toBe('agus@contoh.test')
        ->and($event->context['password'])->toBe('***')
        ->and($event->context['reason'])->toBe('password_salah')
        ->and($event->context)->toHaveKey('request_id');
});

test('enum SecurityEventType identik dengan ENUM database', function () {
    $column = DB::selectOne("SHOW COLUMNS FROM security_events LIKE 'event'");
    preg_match_all("/'([a-z_]+)'/", $column->Type, $m);

    expect($m[1])->toBe(array_map(fn ($c) => $c->value, SecurityEventType::cases()));
});
