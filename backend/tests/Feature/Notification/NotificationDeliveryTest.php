<?php

use App\Domain\Access\Notifications\AccountLocked;
use App\Domain\Access\Notifications\PasswordChanged;
use App\Domain\Notification\Channels\ChannelAvailability;
use App\Domain\Notification\Channels\ChannelGuard;
use App\Domain\Notification\Channels\ExternalChannel;
use App\Domain\Notification\Channels\WhatsAppSender;
use App\Domain\Notification\Enums\DeliveryChannel;
use App\Domain\Notification\Enums\DeliveryStatus;
use App\Domain\Notification\Models\NotificationDelivery;
use App\Models\User;
use App\Support\Export\Events\ExportReady;
use App\Support\Export\ExportFile;
use App\Support\Export\ExportStatus;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'pegawai@pakis-demo.test', 'phone' => '081234567890']);
});

/** Kanal WhatsApp driver `log` aktif + jenis `account_locked` lewat kanal tertentu. */
function useChannels(array $global, array $forAccountLocked): void
{
    config(['notify.whatsapp.driver' => 'log']);
    setSetting('notification.channels', $global);
    setSetting('notification.on_account_locked', $forAccountLocked);
}

function lockNotify(User $user): void
{
    $user->notify(new AccountLocked(now()->addMinutes(15), '10.0.0.9'));
}

/** @return list<array{0: string, 1: string, 2: string|null}> [kanal, status, cadangan dari] */
function deliveries(): array
{
    return NotificationDelivery::query()->orderBy('id')->get()
        ->map(fn (NotificationDelivery $d) => [$d->channel->value, $d->status->value, $d->fallback_from?->value])
        ->all();
}

test('BR-070 notifikasi aplikasi selalu dibuat walau semua kanal luar mati', function () {
    setSetting('notification.channels', []);

    $this->user->notify(new PasswordChanged(now()));

    $row = DatabaseNotification::query()->sole();
    expect($row->type)->toBe('password_changed')
        ->and($row->data['title'])->toBe('Password akun diganti')
        ->and($row->read_at)->toBeNull()
        ->and(deliveries())->toBe([]);
});

test('BR-070 email & kanal luar lewat antrean, notifikasi aplikasi langsung', function () {
    Queue::fake();

    $this->user->notify(new PasswordChanged(now()));

    // Queue::fake juga menahan koneksi `sync`, jadi yang diperiksa koneksinya: notifikasi aplikasi
    // di `sync` (langsung, lonceng segera bertambah), kanal luar di antrean bawaan.
    Queue::assertPushed(SendQueuedNotifications::class, 2);
    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->channels === ['database'] && $job->connection === 'sync');
    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->channels === [ExternalChannel::class] && $job->connection !== 'sync');
});

test('BR-070 kanal luar = kanal jenis ∩ kanal global ∩ alamat penerima', function () {
    useChannels(['email', 'whatsapp'], ['email', 'telegram']);

    lockNotify($this->user);

    // Telegram diminta jenis ini tetapi tidak aktif global (dan penerima belum menautkan bot).
    expect(deliveries())->toBe([['email', 'sent', null]]);
});

test('BR-070 penerima tanpa alamat kanal tidak dikirimi kanal itu', function () {
    useChannels(['email', 'whatsapp'], ['whatsapp']);
    $this->user->forceFill(['phone' => null])->save();

    lockNotify($this->user);

    expect(deliveries())->toBe([])
        ->and(DatabaseNotification::query()->count())->toBe(1);
});

test('BR-070 kuota harian WhatsApp habis → dialihkan ke email', function () {
    useChannels(['email', 'whatsapp'], ['whatsapp']);
    setSetting('notification.whatsapp_daily_limit', 1);

    lockNotify($this->user);
    lockNotify($this->user);

    expect(deliveries())->toBe([
        ['whatsapp', 'sent', null],
        ['whatsapp', 'skipped_quota', null],
        ['email', 'sent', 'whatsapp'],
    ]);
});

test('BR-070 batas WhatsApp per orang per jam tidak memakai jatah harian', function () {
    useChannels(['email', 'whatsapp'], ['whatsapp']);
    setSetting('notification.whatsapp_hourly_per_user', 1);
    $other = User::factory()->create(['phone' => '081200000000']);

    lockNotify($this->user);
    lockNotify($this->user);
    lockNotify($other);

    expect(deliveries())->toBe([
        ['whatsapp', 'sent', null],
        ['whatsapp', 'skipped_quota', null],
        ['email', 'sent', 'whatsapp'],
        ['whatsapp', 'sent', null],
    ]);
});

test('BR-070 batas harian 0 = WhatsApp tidak dipakai untuk notifikasi', function () {
    useChannels(['email', 'whatsapp'], ['whatsapp']);
    setSetting('notification.whatsapp_daily_limit', 0);

    lockNotify($this->user);

    expect(deliveries())->toBe([['whatsapp', 'skipped_quota', null], ['email', 'sent', 'whatsapp']]);
});

test('BR-070 kanal gagal beruntun dijeda, cadangan dipakai, peringatan dikirim sekali', function () {
    useChannels(['email', 'whatsapp'], ['whatsapp']);
    setSetting('notification.circuit_failures', 3);
    setSetting('monitoring.alert_emails', ['it@pakis-demo.test']);
    app()->instance(WhatsAppSender::class, new class extends WhatsAppSender
    {
        public function send(string $phone, string $text): bool
        {
            return false;
        }
    });

    foreach (range(1, 5) as $_) {
        lockNotify($this->user);
    }

    $statuses = collect(deliveries())->where(0, 'whatsapp')->pluck(1)->all();
    expect($statuses)->toBe(['failed', 'failed', 'failed', 'skipped_paused', 'skipped_paused'])
        ->and(collect(deliveries())->where(0, 'email')->count())->toBe(5)
        ->and(Cache::has(ChannelGuard::pausedKey(DeliveryChannel::WhatsApp)))->toBeTrue();

    $alerts = collect(app('mailer')->getSymfonyTransport()->messages())
        ->filter(fn ($m) => str_contains((string) $m->getOriginalMessage()->getSubject(), 'Peringatan kanal notifikasi'));
    expect($alerts)->toHaveCount(1);
});

test('BR-070 galat kanal dicatat tanpa isi pesan atau alamat', function () {
    useChannels(['email', 'whatsapp'], ['whatsapp']);
    app()->instance(WhatsAppSender::class, new class extends WhatsAppSender
    {
        public function send(string $phone, string $text): bool
        {
            throw new RuntimeException("gagal kirim ke {$phone}: {$text}");
        }
    });

    lockNotify($this->user);

    $failed = NotificationDelivery::query()->where('status', DeliveryStatus::Failed)->sole();
    expect($failed->error)->toBe('RuntimeException')
        ->and($failed->kind)->toBe('account_locked');
});

test('BR-070 Telegram dikirim lewat bot ke chat yang ditautkan', function () {
    config(['otp.telegram.bot_token' => '123:uji', 'otp.telegram.bot_username' => 'pakis_uji_bot']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    setSetting('notification.channels', ['telegram']);
    setSetting('notification.on_password_changed', ['telegram']);
    $this->user->forceFill(['telegram_chat_id' => '777'])->save();

    $this->user->notify(new PasswordChanged(now()));

    Http::assertSent(fn ($request) => $request['chat_id'] === '777'
        && str_contains($request['text'], 'Password akun diganti'));
    expect(deliveries())->toBe([['telegram', 'sent', null]]);
});

test('BR-070 driver WhatsApp `log` ditolak di produksi', function () {
    config(['notify.whatsapp.driver' => 'log']);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => ChannelAvailability::assertSafeForEnvironment())->toThrow(RuntimeException::class);
    expect(ChannelAvailability::available(DeliveryChannel::WhatsApp))->toBeFalse();

    app()->detectEnvironment(fn () => 'testing');
});

test('BR-070 Pengaturan menolak WhatsApp/Telegram tanpa driver dan kanal tak dikenal', function () {
    Gate::define('settings.view', fn () => true);
    Gate::define('settings.update', fn () => true);

    $this->actingAs($this->user)
        ->put('/pengaturan/notification', ['values' => ['notification__channels' => ['email', 'whatsapp']]])
        ->assertSessionHasErrors('notification__channels');

    $this->actingAs($this->user)
        ->put('/pengaturan/notification', ['values' => ['notification__on_export_ready' => ['sms']]])
        ->assertSessionHasErrors('notification__on_export_ready');

    // Kosong boleh: hanya notifikasi aplikasi.
    $this->actingAs($this->user)
        ->put('/pengaturan/notification', ['values' => ['notification__channels' => []]])
        ->assertSessionHasNoErrors();
});

test('BR-070 ekspor antrean selesai → notifikasi ke pembuat', function () {
    $file = ExportFile::query()->create([
        'listing' => 'audit_logs', 'params' => [], 'status' => ExportStatus::Ready,
        'path' => 'exports/x.xlsx', 'row_count' => 2500, 'expires_at' => now()->addDays(7),
        'created_by' => $this->user->id,
    ]);

    ExportReady::dispatch($file);

    $row = DatabaseNotification::query()->sole();
    expect($row->notifiable_id)->toBe($this->user->id)
        ->and($row->type)->toBe('export_ready')
        ->and($row->data['body'])->toContain('2.500 baris')
        ->and($row->data['url'])->toBe('/unduhan');
});
