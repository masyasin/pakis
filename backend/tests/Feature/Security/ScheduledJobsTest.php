<?php

use App\Domain\Attendance\Enums\AttendanceType;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Audit\Notifications\SecurityAlert;
use App\Models\User;
use App\Support\Export\ExportFile;
use App\Support\Export\ExportStatus;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportRow;
use App\Support\Import\ImportStatus;
use App\Support\Integrity\InlineComponent;
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

test('SEC-041 Scheduled alert: ambang login gagal, jendela, dan masa redam diatur dari setting', function () {
    setSetting('monitoring.login_burst_count', 5);
    setSetting('monitoring.login_burst_minutes', 3);
    setSetting('monitoring.alert_mute_minutes', 5);
    foreach (range(1, 5) as $i) {
        sjEvent(SecurityEventType::LoginFailed, at: CarbonImmutable::now()->subMinutes(2));
    }
    // Di luar jendela 3 menit → tidak dihitung, jadi IP kedua hanya 4 dalam jendela.
    foreach (range(1, 4) as $i) {
        sjEvent(SecurityEventType::LoginFailed, ip: '198.51.100.9');
    }
    sjEvent(SecurityEventType::LoginFailed, ip: '198.51.100.9', at: CarbonImmutable::now()->subMinutes(4));

    $this->artisan('security:scan-alerts')->assertSuccessful();
    expect(sjAlerts())->toBe(1);
    Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $n) => str_contains($n->lines[0], '5 login gagal dari IP 203.0.113.7 dalam 3 menit'));

    // Lewat masa redam 5 menit, serangan yang masih berjalan dilaporkan lagi.
    $this->travel(6)->minutes();
    foreach (range(1, 5) as $i) {
        sjEvent(SecurityEventType::LoginFailed);
    }
    $this->artisan('security:scan-alerts')->assertSuccessful();
    expect(sjAlerts())->toBe(2);
});

test('SEC-041 Scheduled alert: jam kerja ekspor massal dari setting, termasuk melewati tengah malam', function () {
    $admin = User::factory()->create();
    setSetting('monitoring.work_hour_start', 8);
    setSetting('monitoring.work_hour_end', 17);

    // 10.00 WIB di dalam 08–17 → tanpa alert; 18.00 → alert.
    sjEvent(SecurityEventType::MassExport, $admin, context: ['row_count' => 3000]);
    $this->artisan('security:scan-alerts')->assertSuccessful();
    expect(sjAlerts())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-11-16 18:00', 'Asia/Jakarta'));
    sjEvent(SecurityEventType::MassExport, $admin, context: ['row_count' => 3000]);
    $this->artisan('security:scan-alerts')->assertSuccessful();
    expect(sjAlerts())->toBe(1);
    Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $n) => str_contains($n->lines[0], 'di luar jam kerja (08.00–17.00)'));

    // Jam kerja 22–06 (shift malam): 23.00 di dalam, 10.00 esok di luar.
    setSetting('monitoring.work_hour_start', 22);
    setSetting('monitoring.work_hour_end', 6);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 23:00', 'Asia/Jakarta'));
    sjEvent(SecurityEventType::MassExport, $admin, context: ['row_count' => 3000]);
    $this->artisan('security:scan-alerts')->assertSuccessful();
    expect(sjAlerts())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Jakarta'));
    sjEvent(SecurityEventType::MassExport, $admin, context: ['row_count' => 3000]);
    $this->artisan('security:scan-alerts')->assertSuccessful();
    expect(sjAlerts())->toBe(2);
});

test('SEC-041 Scheduled alert: tanpa email penerima, alert tetap tercatat di log tanpa email', function () {
    setSetting('monitoring.alert_emails', []);
    sjEvent(SecurityEventType::AttendanceMockDetected, User::factory()->create());

    $this->artisan('security:scan-alerts')->expectsOutputToContain('Alert dikirim: 1')->assertSuccessful();

    Notification::assertNothingSent();
});

/** View Blade terkompilasi ASLI (persis hasil BladeCompiler) untuk sumber di `$base/resources/views`. */
function sjCompiledView(string $base, string $view, string $source = 'Halo {{ $nama }}'): string
{
    $src = $base.'/resources/views/'.$view.'.blade.php';
    file_put_contents($src, $source);

    return sjCompile($base, $src);
}

/** Kompilasi sumber apa pun ke folder view terkompilasi `$base` (meniru BladeCompiler::compile). */
function sjCompile(string $base, string $src): string
{
    $compiler = clone app('blade.compiler');
    $compiler->setPath($src);
    $target = $base.'/storage/framework/views/'.basename($compiler->getCompiledPath($src));
    file_put_contents($target, $compiler->compileString((string) file_get_contents($src))."<?php /**PATH {$src} ENDPATH**/ ?>");

    return $target;
}

/** Komponen inline seperti Component::createBladeViewFromString + hasil kompilasinya. */
function sjInline(string $base, string $blade): array
{
    $src = $base.'/storage/framework/views/'.hash('xxh128', $blade).'.blade.php';
    file_put_contents($src, $blade);

    return [$src, sjCompile($base, $src)];
}

/** Baris temuan alert integritas (gabungan). */
function sjIntegrityText(): string
{
    $text = '';
    Notification::assertSentOnDemand(SecurityAlert::class, function (SecurityAlert $n) use (&$text) {
        $text = implode("\n", $n->lines);

        return $n->title === 'Integritas berkas';
    });

    return $text;
}

describe('SEC-070 Scheduled integritas berkas', function () {
    beforeEach(function () {
        $this->base = storage_path('framework/testing/integrity-'.Str::random(8));
        foreach (['app/Domain', 'public/build', 'resources/views', 'storage/framework/views', 'storage/app', 'vendor/paket/views'] as $dir) {
            mkdir($this->base.'/'.$dir, 0777, true);
        }
        file_put_contents($this->base.'/app/Domain/Kode.php', '<?php // kode rilis');
        file_put_contents($this->base.'/public/index.php', '<?php // pintu masuk');
        file_put_contents($this->base.'/public/.htaccess', 'RewriteEngine On');
        file_put_contents($this->base.'/public/build/app.js', 'console.log(1)');
        file_put_contents($this->base.'/vendor/paket/views/kartu.blade.php', '<p>{{ $judul }}</p>');
        file_put_contents($this->base.'/vendor/paket/Kelas.php', '<?php // bukan view, tidak di-hash');
        $this->genuineView = sjCompiledView($this->base, 'sapa');
        config(['integrity.base' => $this->base, 'integrity.manifest' => $this->base.'/.integrity-manifest.json']);

        $this->artisan('integrity:check --write-manifest')->assertSuccessful();
    });

    afterEach(fn () => app('files')->deleteDirectory($this->base));

    test('tanpa perubahan: bersih (view terkompilasi asli tidak dilaporkan), tanpa alert', function () {
        $this->artisan('integrity:check')->expectsOutputToContain('tidak ada temuan')->assertSuccessful();
        Notification::assertNothingSent();
    });

    test('berkas .php baru di storage/ terdeteksi → alert', function () {
        file_put_contents($this->base.'/storage/app/shell.php', '<?php // dummy uji');

        $this->artisan('integrity:check')->assertFailed();

        expect(sjIntegrityText())->toContain('storage/app/shell.php')->not->toContain('framework/views');
    });

    test('.php baru di public/ dan berkas app/ yang berubah terdeteksi', function () {
        file_put_contents($this->base.'/public/slot.php', '<?php // dummy uji');
        file_put_contents($this->base.'/app/Domain/Kode.php', '<?php // disisipi');

        $this->artisan('integrity:check')->assertFailed();

        expect(sjIntegrityText())
            ->toContain('Berkas PHP/eksekusi asing (public/ atau storage/) — 1: public/slot.php')
            ->toContain('Berkas berubah dari rilis — 1: app/Domain/Kode.php');
    });

    test('SEC-070 webshell yang meniru nama & komentar view terkompilasi tetap terdeteksi', function () {
        $views = $this->base.'/storage/framework/views/';
        // 1) nama hex saja, tanpa komentar PATH.
        file_put_contents($views.str_repeat('b', 32).'.php', '<?php // dummy uji tanpa PATH');
        // 2) nama + komentar PATH sah untuk sumber nyata, tetapi isinya disisipi.
        $forged = sjCompiledView($this->base, 'palsu');
        file_put_contents($forged, '<?php /* dummy uji sisipan */ ?>'.file_get_contents($forged));
        // 3) komentar PATH menunjuk sumber di luar resources/views & vendor.
        $outside = $this->base.'/storage/app/luar.blade.php';
        file_put_contents($outside, 'x');
        file_put_contents($views.str_repeat('c', 32).'.php', "x<?php /**PATH {$outside} ENDPATH**/ ?>");
        // 4) "komponen inline" yang namanya bukan hash isinya dan memuat tag PHP.
        file_put_contents($views.str_repeat('d', 32).'.blade.php', '<?php // dummy uji');

        $this->artisan('integrity:check')->assertFailed();

        expect(sjIntegrityText())->toContain(str_repeat('b', 32).'.php')
            ->toContain(basename($forged))
            ->toContain(str_repeat('c', 32).'.php')
            ->toContain(str_repeat('d', 32).'.blade.php')
            ->not->toContain(basename($this->genuineView));
    });

    test('SEC-070 ekstensi PHP-like, ekstensi ganda, .htaccess, .user.ini terdeteksi', function () {
        $names = ['a.phtml', 'b.php.jpg', 'c.PHAR', 'd.inc', 'e.php5', 'f.pht', 'g.phps', 'h.phpt', '.htaccess', '.user.ini'];
        foreach ($names as $name) {
            file_put_contents($this->base.'/storage/app/'.$name, 'dummy uji');
        }
        file_put_contents($this->base.'/storage/app/foto.jpg', 'jpeg');
        file_put_contents($this->base.'/public/build/.user.ini', 'dummy uji');

        $this->artisan('integrity:check')->assertFailed();

        $text = sjIntegrityText();
        foreach ($names as $name) {
            expect($text)->toContain('storage/app/'.$name);
        }
        expect($text)->toContain('public/build/.user.ini')->not->toContain('foto.jpg');
    });

    test('SEC-070 symlink di storage/ dilaporkan', function () {
        $link = $this->base.'/storage/app/tautan.jpg';
        if (! @symlink($this->base.'/app/Domain/Kode.php', $link)) {
            $this->markTestSkipped('OS ini tidak mengizinkan membuat symlink (Windows tanpa Developer Mode).');
        }

        $this->artisan('integrity:check')->assertFailed();

        expect(sjIntegrityText())->toContain('storage/app/tautan.jpg (symlink)');
    });

    test('SEC-070 sumber view harus tercatat di manifest dengan sha256 yang sama', function () {
        // Sumber + hasil kompilasi diganti bersamaan (penyerang menulis keduanya): sumber berbeda dari rilis.
        file_put_contents($this->base.'/resources/views/sapa.blade.php', 'Halo {{ system(\'id\') }}');
        $tampered = sjCompile($this->base, $this->base.'/resources/views/sapa.blade.php');
        // Sumber baru yang tidak ada di manifest.
        $new = sjCompiledView($this->base, 'baru');
        // Sumber view di vendor/ yang tercatat di manifest tetap sah.
        $vendor = sjCompile($this->base, $this->base.'/vendor/paket/views/kartu.blade.php');

        $this->artisan('integrity:check')->assertFailed();

        expect(sjIntegrityText())->toContain(basename($tampered))
            ->toContain(basename($new))
            ->toContain('Berkas berubah dari rilis — 1: resources/views/sapa.blade.php')
            ->toContain('resources/views/baru.blade.php')
            ->not->toContain(basename($vendor));
    });

    test('SEC-070 komponen inline: bentuk sederhana sah, ekspresi kode dilaporkan', function () {
        [$okSource, $okCompiled] = sjInline($this->base, "@if(\$response)\n{!! \$response->head !!}\n@else\n<div id=\"{{ \$id }}\">{!! \$slot !!}</div>\n@endif");
        [$badSource, $badCompiled] = sjInline($this->base, '<b>{{ system($cmd) }}</b>');
        [$badDirective] = sjInline($this->base, "@if(file_put_contents(\$a, \$b))\nx\n@endif");

        $this->artisan('integrity:check')->assertFailed();

        expect(sjIntegrityText())->toContain(basename($badSource))
            ->toContain(basename($badCompiled))
            ->toContain(basename($badDirective))
            ->not->toContain(basename($okSource))
            ->not->toContain(basename($okCompiled));
    });

    test('SEC-070 komponen inline: tag komponen + binding, @include, panggilan method dilaporkan', function () {
        $views = $this->base.'/storage/framework/views/';
        $sources = [
            '<x-foo :a="system(\'id\')"/>',
            "<div>@include('lain')</div>",
            '<p>{{ $a->b() }}</p>',
        ];
        // Hanya sumbernya (tag `<x-foo>` tidak bisa dikompilasi tanpa kelas komponen).
        foreach ($sources as $blade) {
            file_put_contents($views.hash('xxh128', $blade).'.blade.php', $blade);
        }

        $this->artisan('integrity:check')->assertFailed();

        $text = sjIntegrityText();
        foreach ($sources as $blade) {
            expect($text)->toContain(hash('xxh128', $blade).'.blade.php');
        }
    });

    test('SEC-070 tanpa manifest (local): view hanya dicek isinya → peringatan, bukan lolos diam-diam', function () {
        unlink($this->base.'/.integrity-manifest.json');

        $this->artisan('integrity:check')
            ->expectsOutputToContain('view terkompilasi hanya dicek isinya')
            ->assertSuccessful();
    });

    test('SEC-070 folder pemeriksaan yang hilang menjadi temuan', function () {
        app('files')->deleteDirectory($this->base.'/vendor');

        $this->artisan('integrity:check')->assertFailed();

        expect(sjIntegrityText())->toContain('Folder pemeriksaan tidak ada/berupa symlink — 1: vendor (tidak ada)');
    });

    test('SEC-070 symlink di app/ (dicatat link:target) dan folder akar berupa symlink menjadi temuan', function () {
        if (! @symlink($this->base.'/storage/app', $this->base.'/app/Domain/Tautan.php')) {
            $this->markTestSkipped('OS ini tidak mengizinkan membuat symlink (Windows tanpa Developer Mode).');
        }
        rename($this->base.'/resources/views', $this->base.'/views-asli');
        symlink($this->base.'/views-asli', $this->base.'/resources/views');

        $this->artisan('integrity:check')->assertFailed();

        expect(sjIntegrityText())->toContain('app/Domain/Tautan.php')
            ->toContain('resources/views (symlink)');
    });

    test('SEC-070 manifest rusak selalu temuan; manifest hilang = temuan di luar local/testing', function () {
        file_put_contents($this->base.'/.integrity-manifest.json', '{rusak');
        $this->artisan('integrity:check')->assertFailed();
        expect(sjIntegrityText())->toContain('Manifest rilis rusak');

        unlink($this->base.'/.integrity-manifest.json');
        Notification::fake();
        $this->artisan('integrity:check')->expectsOutputToContain('Manifest rilis tidak ditemukan')->assertSuccessful();
        Notification::assertNothingSent();

        $env = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->artisan('integrity:check')->assertFailed();
        } finally {
            $this->app['env'] = $env;
        }
        expect(sjIntegrityText())->toContain('Manifest rilis tidak ditemukan');
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
        ->and($events->get('integrity:check')?->expression)->toBe('15 3 * * *')
        // T-067 (15 §11.1): eskalasi approval tiap 15 menit, zona general.timezone.
        ->and($events->get('approvals:escalate')?->expression)->toBe('*/15 * * * *')
        ->and($events->get('approvals:escalate')?->timezone)->toBe('Asia/Jakarta')
        ->and($events->get('approvals:escalate')?->withoutOverlapping)->toBeTrue()
        ->and($events->get('approvals:escalate')?->onOneServer)->toBeTrue()
        // T-073 (BR-167, 15 §11.1 ApplyScheduledAssignments): 00.05 waktu lokal.
        ->and($events->get('customers:apply-assignments')?->expression)->toBe('5 0 * * *')
        ->and($events->get('customers:apply-assignments')?->timezone)->toBe('Asia/Jakarta')
        ->and($events->get('customers:apply-assignments')?->withoutOverlapping)->toBeTrue()
        ->and($events->get('customers:apply-assignments')?->onOneServer)->toBeTrue();
});

dataset('sj_inline_blade', [
    'Inertia head' => ["@if(\$response)\n{!! \$response->head !!}\n@else\n{!! \$slot !!}\n@endif", true],
    'Inertia body' => ["@if(\$response)\n{!! \$response->body !!}\n@else\n<script data-page=\"{{ \$id }}\" type=\"application/json\">{!! \$pageJson !!}</script><div id=\"{{ \$id }}\"></div>\n@endif", true],
    'indeks & isset' => ["@isset(\$a['k'])\n{{ \$a['k'][0] }}\n@endisset\n@unless(\$b)x@endunless", true],
    'komponen + binding' => ['<x-foo :a="system(\'id\')"/>', false],
    'x-slot' => ['<x-slot name="a">{{ $a }}</x-slot>', false],
    '@include' => ["@include('lain')", false],
    '@props' => ["@props(['a'])", false],
    'panggilan method' => ['{{ $a->b() }}', false],
    'panggilan fungsi di @if' => ['@if(system($a))x@endif', false],
    'variabel variabel' => ['{{ $$a }}', false],
    'static' => ['{{ Foo::$a }}', false],
    'tag php' => ['<?php echo 1; ?>', false],
]);

test('SEC-070 Scheduled InlineComponent: daftar izin lewat PHP hasil kompilasi', function (string $blade, bool $safe) {
    expect(InlineComponent::isSafe($blade, app('blade.compiler')))->toBe($safe);
})->with('sj_inline_blade');
