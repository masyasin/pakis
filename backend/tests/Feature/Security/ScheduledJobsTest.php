<?php

use App\Domain\Attendance\Enums\AttendanceType;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Audit\Notifications\SecurityAlert;
use App\Domain\Employee\Enums\ImportStatus;
use App\Domain\Employee\Models\ImportBatch;
use App\Domain\Employee\Models\ImportRow;
use App\Models\User;
use App\Support\Export\ExportFile;
use App\Support\Export\ExportStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\AttendanceWorld;

/*
 * T-045 — job terjadwal: retensi (SEC-013, NFR-011), alert (SEC-041), integritas (SEC-070),
 * pencabutan sesi insiden (09 §6). Semua data fiktif. "Sekarang" = Senin 16 Nov 2026 10:00 WIB.
 */

const SJ_ALERT_EMAIL = 'keamanan@pakis-demo.test';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Jakarta'));
    Storage::fake('local');
    Notification::fake();
    setSetting('monitoring.alert_emails', [SJ_ALERT_EMAIL]);
});

/** Log absen dengan selfie di disk privat, direkam `$daysAgo` hari lalu. */
function sjLog(array $phone, int $daysAgo): AttendanceLog
{
    $at = now()->subDays($daysAgo);
    $path = 'selfies/uji/'.Str::ulid().'.jpg';
    Storage::disk('local')->put($path, 'jpeg-uji');

    return AttendanceLog::query()->create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $phone['employee']->id,
        'user_id' => $phone['user']->id,
        'device_id' => $phone['device']->id,
        'type' => AttendanceType::In,
        'recorded_at' => $at,
        'server_received_at' => $at,
        'client_time' => $at,
        'is_offline' => false,
        'latitude' => AttendanceWorld::GUDANG[0],
        'longitude' => AttendanceWorld::GUDANG[1],
        'accuracy_m' => 10,
        'is_mock' => false,
        'distance_m' => 5,
        'within_radius' => true,
        'selfie_path' => $path,
    ]);
}

/** Event keamanan langsung di tabel (meniru SecurityLogger dari IP tertentu, waktu tertentu). */
function sjEvent(SecurityEventType $type, ?User $user = null, string $ip = '203.0.113.7', ?CarbonImmutable $at = null, array $context = []): SecurityEvent
{
    return SecurityEvent::query()->create([
        'occurred_at' => $at ?? now(),
        'user_id' => $user?->id,
        'identifier' => $user?->username ?? 'tebak'.Str::random(4),
        'event' => $type,
        'channel' => SecurityChannel::Web,
        'ip' => $ip,
        'context' => $context,
    ]);
}

function sjAlerts(): int
{
    return Notification::sent(new AnonymousNotifiable, SecurityAlert::class)->count();
}

function sjAlertTitles(): array
{
    $titles = [];
    Notification::assertSentOnDemand(SecurityAlert::class, function (SecurityAlert $n, array $channels, AnonymousNotifiable $to) use (&$titles) {
        expect($to->routes['mail'])->toBe([SJ_ALERT_EMAIL]);
        $titles[] = $n->title;

        return true;
    });

    return $titles;
}

test('SEC-013 Scheduled purge selfie: foto lewat retensi dihapus dari disk, selfie_purged_at terisi, baris log tetap', function () {
    setSetting('attendance.selfie_retention_days', 365);
    $world = AttendanceWorld::make($this);
    $phone = $world->phone();
    $old = sjLog($phone, 400);
    $fresh = sjLog($phone, 30);
    $oldPath = (string) $old->selfie_path;

    $this->artisan('attendance:purge-selfies')->assertSuccessful();

    $old->refresh();
    expect(Storage::disk('local')->exists($oldPath))->toBeFalse()
        ->and($old->selfie_path)->toBeNull()
        ->and($old->selfie_purged_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and(AttendanceLog::query()->count())->toBe(2)
        ->and(Storage::disk('local')->exists((string) $fresh->refresh()->selfie_path))->toBeTrue()
        ->and($fresh->selfie_purged_at)->toBeNull();

    // Idempoten: putaran kedua tidak mengubah apa pun.
    $this->artisan('attendance:purge-selfies')->expectsOutputToContain('Selfie dihapus: 0')->assertSuccessful();
});

test('SEC-013 Scheduled purge selfie: nilai retensi di bawah batas registri tidak menghapus foto baru', function () {
    setSetting('attendance.selfie_retention_days', 1);
    $world = AttendanceWorld::make($this);
    $log = sjLog($world->phone(), 10);

    $this->artisan('attendance:purge-selfies')->assertSuccessful();

    expect($log->refresh()->selfie_path)->not->toBeNull();
});

test('SEC-041 Scheduled alert: 20 login gagal dari satu IP dalam 10 menit → email alert sekali', function () {
    foreach (range(1, 20) as $i) {
        sjEvent(SecurityEventType::LoginFailed, at: CarbonImmutable::now()->subMinutes(9)->addSeconds($i));
    }
    // 19 dari IP lain dan percobaan lama dari IP pertama tidak ikut dihitung.
    foreach (range(1, 19) as $i) {
        sjEvent(SecurityEventType::LoginFailed, ip: '198.51.100.9');
    }
    sjEvent(SecurityEventType::LoginFailed, ip: '198.51.100.9', at: CarbonImmutable::now()->subMinutes(11));

    $this->artisan('security:scan-alerts')->assertSuccessful();

    expect(sjAlerts())->toBe(1)->and(sjAlertTitles())->toBe(['Login gagal beruntun']);
    Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $n) => str_contains($n->lines[0], '203.0.113.7')
        && str_contains($n->lines[0], '20 login gagal'));

    // Putaran berikutnya (5 menit kemudian, serangan masih dalam jendela) diredam.
    $this->travel(5)->minutes();
    $this->artisan('security:scan-alerts')->assertSuccessful();
    expect(sjAlerts())->toBe(1);
});

test('SEC-041 Scheduled alert: lockout admin, lokasi palsu, ekspor massal di luar jam — masing-masing sekali', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
    $staff = User::factory()->create()->assignRole('pegawai');

    sjEvent(SecurityEventType::Lockout, $admin);
    sjEvent(SecurityEventType::Lockout, $staff); // bukan admin → tanpa alert
    sjEvent(SecurityEventType::AttendanceMockDetected, $staff);
    sjEvent(SecurityEventType::MassExport, $admin, context: ['row_count' => 5000]); // 10.00 WIB → wajar

    $this->artisan('security:scan-alerts')->assertSuccessful();
    expect(sjAlertTitles())->toEqualCanonicalizing(['Akun terkunci', 'Lokasi palsu terdeteksi']);

    // Putaran berikutnya hanya membaca event SETELAH penanda: ekspor 22.30 WIB = di luar jam.
    $this->travelTo(CarbonImmutable::parse('2026-11-16 22:30', 'Asia/Jakarta'));
    sjEvent(SecurityEventType::MassExport, $admin, context: ['row_count' => 7000]);
    $this->artisan('security:scan-alerts')->assertSuccessful();
    $this->artisan('security:scan-alerts')->assertSuccessful(); // tidak mengulang event yang sama

    expect(sjAlertTitles())->toEqualCanonicalizing(['Akun terkunci', 'Lokasi palsu terdeteksi', 'Ekspor massal']);
    Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $n) => $n->title === 'Ekspor massal' && str_contains($n->lines[0], '7000 baris'));
});

test('SEC-041 Scheduled alert: tanpa email penerima, alert tetap tercatat di log tanpa email', function () {
    setSetting('monitoring.alert_emails', []);
    sjEvent(SecurityEventType::AttendanceMockDetected, User::factory()->create());

    $this->artisan('security:scan-alerts')->expectsOutputToContain('Alert dikirim: 1')->assertSuccessful();

    Notification::assertNothingSent();
});

describe('SEC-070 Scheduled integritas berkas', function () {
    beforeEach(function () {
        $this->base = storage_path('framework/testing/integrity-'.Str::random(8));
        foreach (['app/Domain', 'public/build', 'storage/framework/views', 'storage/app'] as $dir) {
            mkdir($this->base.'/'.$dir, 0777, true);
        }
        file_put_contents($this->base.'/app/Domain/Kode.php', '<?php // kode rilis');
        file_put_contents($this->base.'/public/index.php', '<?php // pintu masuk');
        file_put_contents($this->base.'/public/build/app.js', 'console.log(1)');
        file_put_contents($this->base.'/storage/framework/views/'.str_repeat('a', 32).'.php', '<?php // view terkompilasi');
        config(['integrity.base' => $this->base, 'integrity.manifest' => $this->base.'/.integrity-manifest.json']);

        $this->artisan('integrity:check --write-manifest')->assertSuccessful();
    });

    afterEach(fn () => app('files')->deleteDirectory($this->base));

    test('tanpa perubahan: bersih, tanpa alert', function () {
        $this->artisan('integrity:check')->expectsOutputToContain('tidak ada temuan')->assertSuccessful();
        Notification::assertNothingSent();
    });

    test('berkas .php baru di storage/ terdeteksi → alert', function () {
        file_put_contents($this->base.'/storage/app/shell.php', '<?php // dummy uji');

        $this->artisan('integrity:check')->assertFailed();

        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $n) => $n->title === 'Integritas berkas'
            && str_contains(implode("\n", $n->lines), 'storage/app/shell.php')
            && ! str_contains(implode("\n", $n->lines), 'framework/views'));
    });

    test('.php baru di public/ dan berkas app/ yang berubah terdeteksi', function () {
        file_put_contents($this->base.'/public/slot.php', '<?php // dummy uji');
        file_put_contents($this->base.'/app/Domain/Kode.php', '<?php // disisipi');

        $this->artisan('integrity:check')->assertFailed();

        Notification::assertSentOnDemand(SecurityAlert::class, function (SecurityAlert $n) {
            $text = implode("\n", $n->lines);

            return str_contains($text, 'Berkas PHP asing (public/ atau storage/) — 1: public/slot.php')
                && str_contains($text, 'Berkas berubah dari rilis — 1: app/Domain/Kode.php');
        });
    });
});

test('NFR-011 Scheduled ekspor kedaluwarsa: berkas dihapus & status expired; yang masih berlaku tetap', function () {
    $user = User::factory()->create();
    Storage::disk('local')->put('exports/lama.xlsx', 'x');
    Storage::disk('local')->put('exports/baru.xlsx', 'x');
    $old = ExportFile::query()->create(['listing' => 'users', 'params' => [], 'status' => ExportStatus::Ready, 'path' => 'exports/lama.xlsx', 'expires_at' => now()->subHour(), 'created_by' => $user->id]);
    $fresh = ExportFile::query()->create(['listing' => 'users', 'params' => [], 'status' => ExportStatus::Ready, 'path' => 'exports/baru.xlsx', 'expires_at' => now()->addDay(), 'created_by' => $user->id]);

    $this->artisan('exports:expire')->assertSuccessful();

    expect($old->refresh()->status)->toBe(ExportStatus::Expired)
        ->and($old->path)->toBeNull()
        ->and(Storage::disk('local')->exists('exports/lama.xlsx'))->toBeFalse()
        ->and($fresh->refresh()->status)->toBe(ExportStatus::Ready)
        ->and(Storage::disk('local')->exists('exports/baru.xlsx'))->toBeTrue();
});

test('NFR-011 Scheduled retensi: riwayat kirim notifikasi & batch impor lama dihapus, yang baru tetap', function () {
    setSetting('retention.security_days', 365);
    setSetting('retention.import_days', 90);
    $user = User::factory()->create();

    foreach ([400, 10] as $days) {
        DB::table('notification_deliveries')->insert([
            'notification_id' => (string) Str::uuid(), 'user_id' => $user->id, 'kind' => 'account_locked',
            'channel' => 'email', 'status' => 'sent', 'created_at' => now()->subDays($days),
        ]);
    }

    $batch = fn (int $days, ImportStatus $status) => tap(new ImportBatch, function (ImportBatch $b) use ($days, $status, $user) {
        Storage::disk('local')->put("imports/{$days}.xlsx", 'x');
        $b->forceFill([
            'type' => 'employees', 'original_name' => "{$days}.xlsx", 'stored_path' => "imports/{$days}.xlsx",
            'file_hash' => str_repeat('0', 64), 'status' => $status, 'created_by' => $user->id,
            'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days),
        ])->save();
        ImportRow::query()->forceCreate(['import_batch_id' => $b->id, 'row_number' => 2, 'data' => [], 'status' => 'valid']);
    });
    $old = $batch(120, ImportStatus::Completed);
    $stuck = $batch(120, ImportStatus::Processing);
    $fresh = $batch(5, ImportStatus::Validated);

    $this->artisan('retention:purge')->assertSuccessful();

    expect(DB::table('notification_deliveries')->count())->toBe(1)
        ->and(ImportBatch::query()->pluck('id')->sort()->values()->all())->toBe(collect([$stuck->id, $fresh->id])->sort()->values()->all())
        ->and(ImportRow::query()->where('import_batch_id', $old->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists('imports/5.xlsx'))->toBeTrue();
});

test('09 §6 Scheduled security:revoke --user mencabut sesi web & token API akun itu saja', function () {
    $world = AttendanceWorld::make($this);
    $target = $world->phone();
    $other = $world->phone();
    foreach ([$target['user'], $other['user']] as $u) {
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $u->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'uji', 'payload' => '', 'last_activity' => time()]);
    }

    $this->artisan('security:revoke', ['--user' => $target['user']->username])->assertSuccessful();

    expect(DB::table('sessions')->where('user_id', $target['user']->id)->exists())->toBeFalse()
        ->and($target['user']->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $other['user']->id)->exists())->toBeTrue()
        ->and($other['user']->tokens()->count())->toBe(1)
        ->and(SecurityEvent::query()->where('event', SecurityEventType::SessionRevoked)->where('user_id', $target['user']->id)->exists())->toBeTrue();

    $this->artisan('security:revoke')->assertExitCode(2);
});

test('T-045 Scheduled: semua job terdaftar di scheduler dengan jadwalnya', function () {
    $events = collect(app(Schedule::class)->events())
        ->mapWithKeys(fn ($e) => [trim((string) preg_replace('/^.*artisan["\']?\s+/', '', (string) $e->command)) => $e]);

    expect($events->get('attendance:purge-selfies')?->expression)->toBe('45 2 * * *')
        ->and($events->get('attendance:purge-selfies')?->timezone)->toBe('Asia/Jakarta')
        ->and($events->get('retention:purge')?->expression)->toBe('0 3 * * *')
        ->and($events->get('exports:expire')?->expression)->toBe('0 * * * *')
        ->and($events->get('security:scan-alerts')?->expression)->toBe('*/5 * * * *')
        ->and($events->get('integrity:check')?->expression)->toBe('15 3 * * *');
});
