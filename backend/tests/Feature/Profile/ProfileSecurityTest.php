<?php

use App\Domain\Access\Actions\ListSessions;
use App\Domain\Access\Actions\RevokeOtherSessions;
use App\Domain\Access\Actions\RevokeSession;
use App\Domain\Access\Support\SessionKey;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Profil → Keamanan: ubah password (FR-004), daftar & cabut sesi web (FR-003, SEC-005, BR-018),
 * Telegram untuk OTP lupa password (BR-013).
 */

beforeEach(function () {
    Notification::fake();
    setSetting('security.password_check_breached', false);
    $this->user = User::factory()->create(['username' => 'siti.aminah']);
    $this->other = User::factory()->create(['username' => 'budi.santoso']);
});

function sessionRow(User $user, string $id, array $attributes = []): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '10.0.0.7',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
        ...$attributes,
    ]);
}

function securityPage()
{
    return test()->withSession(['auth.password_confirmed_at' => time()])->get('/profil/keamanan');
}

test('halaman keamanan butuh konfirmasi password', function () {
    $this->actingAs($this->user)->get('/profil/keamanan')->assertRedirect(route('password.confirm'));
});

test('SEC-005 daftar sesi hanya milik sendiri, aktif dalam batas idle, tanpa ID sesi asli', function () {
    setSetting('security.session_idle_minutes', 30);
    sessionRow($this->user, str_repeat('a', 40));
    sessionRow($this->user, str_repeat('b', 40), ['last_activity' => now()->subMinutes(31)->getTimestamp()]);
    sessionRow($this->other, str_repeat('c', 40));

    $this->actingAs($this->user);
    $response = securityPage()->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('profile/security')
        ->has('sessions', 1)
        ->where('sessions.0.key', SessionKey::of(str_repeat('a', 40)))
        ->where('sessions.0.browser', 'Chrome')
        ->where('sessions.0.platform', 'Windows')
        ->where('sessions.0.ip', '10.0.0.7')
        ->where('sessions.0.is_current', false));

    expect($response->getContent())->not->toContain(str_repeat('a', 40))->not->toContain(str_repeat('c', 40));
});

test('SEC-005 sesi yang sedang dipakai ditandai is_current', function () {
    sessionRow($this->user, str_repeat('a', 40));
    sessionRow($this->user, str_repeat('d', 40));

    $sessions = app(ListSessions::class)->handle($this->user, str_repeat('d', 40));

    expect(collect($sessions)->firstWhere('is_current', true)['key'])->toBe(SessionKey::of(str_repeat('d', 40)))
        ->and($sessions[0]['is_current'])->toBeTrue(); // sesi ini selalu di urutan pertama
});

test('FR-003 cabut satu sesi: wajib password, baris sesi terhapus, event session_revoked', function () {
    sessionRow($this->user, str_repeat('a', 40));
    $key = SessionKey::of(str_repeat('a', 40));
    $this->actingAs($this->user);

    $this->delete("/profil/sesi/{$key}", ['current_password' => 'salah'])->assertSessionHasErrors('current_password');
    expect(DB::table('sessions')->where('id', str_repeat('a', 40))->exists())->toBeTrue();

    $this->delete("/profil/sesi/{$key}", ['current_password' => 'password'])->assertSessionHasNoErrors()->assertRedirect();

    expect(DB::table('sessions')->where('id', str_repeat('a', 40))->exists())->toBeFalse();
    $event = SecurityEvent::query()->where('event', 'session_revoked')->sole();
    expect($event->user_id)->toBe($this->user->id)
        ->and(json_encode($event->context))->not->toContain(str_repeat('a', 40));
});

test('IDOR: mencabut sesi milik pengguna lain → 404, sesinya tetap ada', function () {
    sessionRow($this->other, str_repeat('c', 40));

    $this->actingAs($this->user)
        ->delete('/profil/sesi/'.SessionKey::of(str_repeat('c', 40)), ['current_password' => 'password'])
        ->assertNotFound();

    expect(DB::table('sessions')->where('id', str_repeat('c', 40))->exists())->toBeTrue();
});

test('kunci sesi acak / sesi yang sedang dipakai tidak bisa dicabut lewat RevokeSession', function () {
    sessionRow($this->user, str_repeat('d', 40));

    expect(fn () => app(RevokeSession::class)->handle($this->user, SessionKey::of(str_repeat('d', 40)), str_repeat('d', 40)))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RevokeSession::class)->handle($this->user, str_repeat('0', 64), str_repeat('d', 40)))
        ->toThrow(ModelNotFoundException::class);

    $this->actingAs($this->user)->delete('/profil/sesi/bukan-kunci', ['current_password' => 'password'])->assertNotFound();
});

test('FR-003 keluarkan semua sesi lain: wajib password, sesi ini & milik orang lain tetap', function () {
    sessionRow($this->user, str_repeat('a', 40));
    sessionRow($this->user, str_repeat('b', 40));
    sessionRow($this->user, str_repeat('d', 40));
    sessionRow($this->other, str_repeat('c', 40));

    $this->actingAs($this->user)->delete('/profil/sesi', ['current_password' => 'salah'])->assertSessionHasErrors('current_password');
    expect(DB::table('sessions')->count())->toBe(4);

    $count = app(RevokeOtherSessions::class)->handle($this->user, str_repeat('d', 40));

    expect($count)->toBe(2)
        ->and(DB::table('sessions')->pluck('id')->sort()->values()->all())->toBe([str_repeat('c', 40), str_repeat('d', 40)])
        ->and(SecurityEvent::query()->where('event', 'session_revoked')->sole()->context['count'])->toBe(2);
});

test('keluarkan semua sesi lain lewat HTTP menghapus sesi lain milik sendiri', function () {
    sessionRow($this->user, str_repeat('a', 40));
    sessionRow($this->other, str_repeat('c', 40));

    $this->actingAs($this->user)->delete('/profil/sesi', ['current_password' => 'password'])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('user_id', $this->user->id)->where('id', str_repeat('a', 40))->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', str_repeat('c', 40))->exists())->toBeTrue();
});

test('BR-013 hubungkan Telegram: wajib password, hanya bila driver bot aktif, menghasilkan tautan t.me', function () {
    config(['otp.channels.telegram.driver' => 'bot', 'otp.telegram.bot_token' => '1:dummy', 'otp.telegram.bot_username' => 'PakisDemoBot']);
    $this->actingAs($this->user);

    $this->post('/profil/telegram', ['current_password' => 'salah'])->assertSessionHasErrors('current_password');

    $this->post('/profil/telegram', ['current_password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('telegram_link');
    expect(session('inertia.flash_data')['telegram_link'])
        ->toMatch('#^https://t\.me/PakisDemoBot\?start=[A-Za-z0-9]{40}$#');

    securityPage()->assertInertia(fn (Assert $page) => $page
        ->where('telegram.available', true)
        ->where('telegram.linked_at', null));
});

test('Telegram tidak tersedia (driver bukan bot) → tombol disembunyikan, permintaan tautan 404', function () {
    config(['otp.channels.telegram.driver' => 'log']);
    $this->actingAs($this->user);

    securityPage()->assertInertia(fn (Assert $page) => $page->where('telegram.available', false));
    $this->post('/profil/telegram', ['current_password' => 'password'])->assertNotFound();
});

test('putuskan Telegram mengosongkan chat_id tanpa mencatat chat_id di audit', function () {
    $this->user->forceFill(['telegram_chat_id' => '987654321', 'telegram_linked_at' => now()])->save();

    $this->actingAs($this->user)->delete('/profil/telegram')->assertSessionHasNoErrors()->assertRedirect();

    $this->user->refresh();
    expect($this->user->telegram_chat_id)->toBeNull()->and($this->user->telegram_linked_at)->toBeNull();
    $log = AuditLog::query()->where('event', 'user.updated')->sole();
    expect(json_encode([$log->old_values, $log->new_values]))->not->toContain('987654321');
});
