<?php

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CorrectionWorld;

/*
 * Notifikasi milik sendiri di Pakis Pro (T-043): 05 §5.1 `api.v1.notifications.*`, 07 §3.4
 * `my_notifications`, FR-030, BR-070, SEC-033. Data fiktif.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 05:00:00');
    Storage::fake('local');
    Notification::fake();
    $this->c = CorrectionWorld::make($this);
});

afterEach(fn () => Carbon::setTestNow());

function apiNotice(User $user, string $title, string $body = 'Isi uji', ?string $readAt = null, string $at = '2026-11-16 04:00:00'): DatabaseNotification
{
    $notification = new DatabaseNotification;
    $notification->forceFill([
        'id' => (string) Str::uuid(),
        'type' => 'correction_decided',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => ['title' => $title, 'body' => $body, 'url' => '/koreksi-absen'],
        'read_at' => $readAt,
        'created_at' => $at,
        'updated_at' => $at,
    ])->save();

    return $notification;
}

test('FR-030 my_notifications: hanya milik sendiri, terbaru dulu, tanpa tautan web (SEC-033)', function () {
    $me = $this->c->staff['user'];
    apiNotice($me, 'Koreksi disetujui', at: '2026-11-15 04:00:00');
    apiNotice($me, 'Koreksi ditolak', at: '2026-11-16 04:00:00');
    apiNotice($this->c->boss['user'], 'Milik atasan');

    $response = CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/notifications')->assertOk()
        ->assertJsonPath('meta.pagination.total', 2)
        ->assertJsonPath('data.0.title', 'Koreksi ditolak')
        ->assertJsonPath('data.0.read', false)
        ->assertJsonPath('data.0.created_at', '2026-11-16T11:00:00+07:00')
        ->assertJsonPath('data.1.title', 'Koreksi disetujui');
    expect($response->json('data.0'))->not->toHaveKey('url');
});

test('FR-030 my_notifications: cari judul/isi, filter status baca, sort naik', function () {
    $me = $this->c->staff['user'];
    apiNotice($me, 'Koreksi disetujui', 'Koreksi KOR-1 disetujui', readAt: '2026-11-16 04:30:00', at: '2026-11-15 04:00:00');
    apiNotice($me, 'Perangkat disetujui', 'HP baru aktif', at: '2026-11-16 04:00:00');

    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/notifications?q=kor-1')->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.title', 'Koreksi disetujui');
    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/notifications?filter[read]=unread')->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.title', 'Perangkat disetujui');
    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/notifications?sort=created_at')->assertOk()
        ->assertJsonPath('data.0.title', 'Koreksi disetujui');
});

test('FR-030 tandai dibaca: milik sendiri 200; milik orang lain / tidak ada → 404 (SEC-033)', function () {
    $mine = apiNotice($this->c->staff['user'], 'Punya saya');
    $other = apiNotice($this->c->boss['user'], 'Punya atasan');

    CorrectionWorld::call($this, $this->c->staff, 'POST', "/api/v1/notifications/{$mine->id}/read")->assertOk()
        ->assertJsonPath('data.id', $mine->id)
        ->assertJsonPath('data.read', true);
    expect($mine->refresh()->read_at)->not->toBeNull();

    CorrectionWorld::call($this, $this->c->staff, 'POST', "/api/v1/notifications/{$other->id}/read")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');
    expect($other->refresh()->read_at)->toBeNull();

    CorrectionWorld::call($this, $this->c->staff, 'POST', '/api/v1/notifications/'.Str::uuid().'/read')->assertNotFound();
});

test('FR-030 tandai semua dibaca: hanya milik sendiri, mengembalikan jumlah', function () {
    apiNotice($this->c->staff['user'], 'Satu');
    apiNotice($this->c->staff['user'], 'Dua');
    apiNotice($this->c->staff['user'], 'Lama', readAt: '2026-11-16 03:00:00');
    $other = apiNotice($this->c->boss['user'], 'Punya atasan');

    CorrectionWorld::call($this, $this->c->staff, 'POST', '/api/v1/notifications/read-all')->assertOk()
        ->assertJsonPath('data.marked', 2);
    expect(DatabaseNotification::query()->whereMorphedTo('notifiable', $this->c->staff['user'])->whereNull('read_at')->count())->toBe(0)
        ->and($other->refresh()->read_at)->toBeNull();
});

test('FR-030 notifikasi API wajib token + perangkat terikat', function () {
    $this->getJson('/api/v1/notifications', ['X-App-Version' => '1.0.0'])->assertUnauthorized();
});
