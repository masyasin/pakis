<?php

use App\Domain\Access\Notifications\PasswordChanged;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Notification\Otp\OtpChannel;
use App\Domain\Notification\Otp\OtpManager;
use App\Domain\Notification\Otp\OtpService;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * FR-006, BR-013, BR-016: lupa password dengan OTP lewat WhatsApp atau Telegram.
 */

/** Kanal OTP palsu yang menyimpan pesan terkirim. */
final class RecordingOtpChannel implements OtpChannel
{
    /** @var list<array{address: string, message: string}> */
    public array $sent = [];

    public function send(string $address, string $message): void
    {
        $this->sent[] = ['address' => $address, 'message' => $message];
    }

    public function lastCode(): ?string
    {
        $last = end($this->sent);

        return $last && preg_match('/\b(\d{6,8})\b/', $last['message'], $m) ? $m[1] : null;
    }
}

beforeEach(function () {
    Notification::fake();
    setSetting('security.password_check_breached', false);
    config(['otp.channels.whatsapp.driver' => 'log', 'otp.channels.telegram.driver' => 'log']);
    setSetting('auth.forgot_channels', ['email', 'whatsapp', 'telegram']);

    $this->otp = new RecordingOtpChannel;
    app(OtpManager::class)->fake($this->otp);

    $this->user = User::factory()->create([
        'username' => 'siti.aminah', 'phone' => '6281234567890', 'telegram_chat_id' => '987654321',
    ]);
});

function requestOtp(string $login, string $channel, string $ip = '10.0.0.5')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->from('/forgot-password')
        ->post('/forgot-password', ['login' => $login, 'channel' => $channel]);
}

function resetWithOtp(string $login, ?string $code, string $password = 'kopi pagi di gudang')
{
    return test()->from('/reset-password-otp')->post('/reset-password-otp', [
        'login' => $login, 'code' => $code, 'password' => $password, 'password_confirmation' => $password,
    ]);
}

test('halaman lupa password menawarkan kanal yang aktif & tersedia', function () {
    $this->get('/forgot-password')->assertInertia(fn ($page) => $page->where('channels', [
        ['value' => 'email', 'label' => 'Email'],
        ['value' => 'whatsapp', 'label' => 'WhatsApp'],
        ['value' => 'telegram', 'label' => 'Telegram'],
    ]));

    config(['otp.channels.telegram.driver' => 'none']);
    $this->get('/forgot-password')->assertInertia(fn ($page) => $page->has('channels', 2)->where('channels.1.value', 'whatsapp'));
});

test('BR-013 kode dikirim ke nomor WA / chat Telegram akun, panjang ikut setting', function (string $channel, string $address) {
    setSetting('auth.otp_length', 8);

    requestOtp('siti.aminah', $channel)->assertRedirect('/reset-password-otp')->assertSessionHas('status', 'Jika akun ditemukan, kami sudah mengirim petunjuk reset.');

    expect($this->otp->sent)->toHaveCount(1)
        ->and($this->otp->sent[0]['address'])->toBe($address)
        ->and($this->otp->lastCode())->toHaveLength(8);
})->with([
    'WhatsApp' => ['whatsapp', '6281234567890'],
    'Telegram' => ['telegram', '987654321'],
]);

test('SEC-004 respons identik untuk akun tanpa nomor/chat Telegram dan akun yang tidak ada', function () {
    User::factory()->create(['username' => 'budi', 'phone' => null, 'telegram_chat_id' => null]);

    foreach (['budi', 'tidak.ada', 'siti.aminah'] as $i => $login) {
        requestOtp($login, 'telegram', "10.0.3.{$i}")
            ->assertRedirect('/reset-password-otp')
            ->assertSessionHas('status', 'Jika akun ditemukan, kami sudah mengirim petunjuk reset.');
    }

    expect($this->otp->sent)->toHaveCount(1);
});

test('BR-013 kode disimpan sebagai hash, bukan teks asli', function () {
    requestOtp('siti.aminah', 'whatsapp');
    $code = $this->otp->lastCode();

    $stored = Cache::get('otp:password-reset:'.$this->user->id);
    expect($stored)->toBeArray()
        ->and(json_encode($stored))->not->toContain($code);
});

test('BR-013 kode benar → password direset dengan efek BR-012, kode tidak bisa dipakai lagi', function () {
    $this->user->update(['must_change_password' => true]);
    requestOtp('siti.aminah', 'telegram');
    $code = $this->otp->lastCode();

    resetWithOtp('Siti.Aminah', $code)->assertRedirect(route('login'))->assertSessionHasNoErrors();

    $this->user->refresh();
    expect(Hash::check('kopi pagi di gudang', $this->user->password))->toBeTrue()
        ->and($this->user->must_change_password)->toBeFalse()
        ->and(SecurityEvent::query()->where('event', 'password_reset_completed')->exists())->toBeTrue();
    Notification::assertSentTo($this->user, PasswordChanged::class);

    resetWithOtp('siti.aminah', $code, 'teh sore di toko kalirejo')->assertSessionHasErrors('code');
});

test('BR-013 salah 5× → kode hangus, kode benar pun ditolak', function () {
    requestOtp('siti.aminah', 'whatsapp');
    $code = $this->otp->lastCode();

    foreach (range(1, 5) as $i) {
        resetWithOtp('siti.aminah', '000000')->assertSessionHasErrors(['code' => 'Kode salah atau sudah tidak berlaku.']);
    }

    resetWithOtp('siti.aminah', $code)->assertSessionHasErrors(['code' => 'Kode salah atau sudah tidak berlaku.']);
    expect(Hash::check('kopi pagi di gudang', $this->user->refresh()->password))->toBeFalse();
});

test('BR-013 kode kedaluwarsa mengikuti auth.otp_minutes', function () {
    requestOtp('siti.aminah', 'whatsapp');
    $code = $this->otp->lastCode();

    $this->travel(11)->minutes();
    resetWithOtp('siti.aminah', $code)->assertSessionHasErrors('code');
});

test('BR-013 kirim ulang < 60 detik ditolak (sama untuk identifier tak ada), sesudahnya boleh', function () {
    requestOtp('siti.aminah', 'whatsapp');
    requestOtp('siti.aminah', 'whatsapp')->assertSessionHasErrors(['login' => 'Tunggu 60 detik sebelum meminta kode lagi.']);

    requestOtp('tidak.ada', 'whatsapp', '10.0.4.1');
    requestOtp('tidak.ada', 'whatsapp', '10.0.4.2')->assertSessionHasErrors(['login' => 'Tunggu 60 detik sebelum meminta kode lagi.']);

    $this->travel(61)->seconds();
    requestOtp('siti.aminah', 'whatsapp')->assertSessionHasNoErrors();
    expect($this->otp->sent)->toHaveCount(2);
});

test('SEC-004 aturan password diperiksa SETELAH kode sah', function () {
    $this->user->update(['password' => 'kopi pagi di gudang']);
    requestOtp('siti.aminah', 'whatsapp');

    resetWithOtp('siti.aminah', '000000', 'kopi pagi di gudang')
        ->assertSessionHasErrors('code')
        ->assertSessionDoesntHaveErrors('password');
});

test('BR-011 kode benar tapi password melanggar kebijakan → kode tetap bisa dipakai lagi', function () {
    requestOtp('siti.aminah', 'whatsapp');
    $code = $this->otp->lastCode();

    resetWithOtp('siti.aminah', $code, 'siti.aminah-2026')->assertSessionHasErrors('password');
    resetWithOtp('siti.aminah', $code)->assertSessionHasNoErrors();
});

test('verifikasi OTP dibatasi per identifier walau berganti IP (pesan sama untuk identifier tak ada)', function () {
    foreach (['siti.aminah', 'tidak.ada'] as $login) {
        foreach (range(1, 10) as $i) {
            test()->withServerVariables(['REMOTE_ADDR' => "10.9.{$i}.1"])->from('/reset-password-otp')
                ->post('/reset-password-otp', ['login' => $login, 'code' => '000000', 'password' => 'x', 'password_confirmation' => 'x']);
        }

        test()->withServerVariables(['REMOTE_ADDR' => '10.9.99.1'])->from('/reset-password-otp')
            ->post('/reset-password-otp', ['login' => $login, 'code' => '000000', 'password' => 'x', 'password_confirmation' => 'x'])
            ->assertSessionHasErrors(['code' => 'Terlalu banyak percobaan. Coba lagi nanti.']);
    }
});

test('BR-013 jatah percobaan dihitung atomik: kode benar setelah 5 pemeriksaan tetap ditolak', function () {
    requestOtp('siti.aminah', 'whatsapp');
    $code = $this->otp->lastCode();
    $service = app(OtpService::class);

    foreach (range(1, 4) as $i) {
        expect($service->check($this->user, '000000'))->toBeFalse();
    }
    expect($service->check($this->user, $code))->toBeTrue()      // percobaan ke-5 masih boleh
        ->and($service->check($this->user, $code))->toBeFalse(); // ke-6: jatah habis, kode hangus
});
