<?php

use App\Domain\Access\Notifications\PasswordChanged;
use App\Domain\Notification\Notifications\ExportReadyNotification;
use App\Models\User;
use App\Support\Export\ExportFile;
use App\Support\Export\ExportStatus;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    setSetting('notification.channels', []);
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
});

function exportNotice(User $user, int $rows = 10): void
{
    $file = ExportFile::query()->create([
        'listing' => 'audit_logs', 'params' => [], 'status' => ExportStatus::Ready, 'path' => 'x.xlsx',
        'row_count' => $rows, 'expires_at' => now()->addDay(), 'created_by' => $user->id,
    ]);
    $user->notify(new ExportReadyNotification($file));
}

function ownNotification(User $user): DatabaseNotification
{
    return DatabaseNotification::query()->whereMorphedTo('notifiable', $user)->latest()->firstOrFail();
}

test('BR-070 daftar hanya menampilkan notifikasi milik sendiri, terbaru dulu', function () {
    $this->user->notify(new PasswordChanged(now()->subHour()));
    $this->travel(1)->minutes();
    exportNotice($this->user);
    exportNotice($this->other);

    $this->actingAs($this->user)->get('/notifikasi')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('notifications/index')
            ->has('rows', 2)
            ->where('rows.0.type', 'export_ready')
            ->where('rows.0.url', '/unduhan')
            ->where('rows.0.read', false)
            ->where('rows.1.type', 'password_changed'));
});

test('BR-070 cari judul/isi, filter status baca & jenis', function () {
    $this->user->notify(new PasswordChanged(now()));
    $this->travel(1)->minutes();
    exportNotice($this->user, 2500);
    ownNotification($this->user)->markAsRead();

    $this->actingAs($this->user)->get('/notifikasi?q=EKSPOR')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.type', 'export_ready'));

    $this->actingAs($this->user)->get('/notifikasi?q=2.500')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1));

    $this->actingAs($this->user)->get('/notifikasi?filter[read]=unread')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.type', 'password_changed'));

    $this->actingAs($this->user)->get('/notifikasi?filter[type][]=export_ready')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.read', true));
});

test('BR-070 sort waktu naik/turun', function () {
    $this->user->notify(new PasswordChanged(now()));
    $this->travel(1)->minutes();
    exportNotice($this->user);

    $this->actingAs($this->user)->get('/notifikasi?sort=created_at')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.type', 'password_changed'));
});

test('SEC-033 tandai dibaca notifikasi orang lain → 404 dan tidak berubah', function () {
    exportNotice($this->other);
    $foreign = ownNotification($this->other);

    $this->actingAs($this->user)->post("/notifikasi/{$foreign->id}/baca")->assertNotFound();

    expect($foreign->fresh()->read_at)->toBeNull();
});

test('BR-070 tandai dibaca lalu buka tautan internal', function () {
    exportNotice($this->user);
    $own = ownNotification($this->user);

    $this->actingAs($this->user)->post("/notifikasi/{$own->id}/baca?open=1")->assertRedirect('/unduhan');

    expect($own->fresh()->read_at)->not->toBeNull();
});

test('SEC-033 tautan notifikasi selain path internal tidak dipakai untuk redirect', function () {
    exportNotice($this->user);
    $own = ownNotification($this->user);
    $own->forceFill(['data' => ['title' => 'x', 'body' => 'y', 'url' => '//contoh-jahat.test']])->save();

    $this->actingAs($this->user)->from('/notifikasi')->post("/notifikasi/{$own->id}/baca?open=1")->assertRedirect('/notifikasi');
});

test('BR-070 baca semua & jumlah belum dibaca hanya milik sendiri', function () {
    exportNotice($this->user);
    exportNotice($this->user);
    exportNotice($this->other);

    $this->actingAs($this->user)->getJson('/notifikasi/jumlah')->assertExactJson(['count' => 2]);
    $this->actingAs($this->user)->post('/notifikasi/baca-semua')->assertRedirect();
    $this->actingAs($this->user)->getJson('/notifikasi/jumlah')->assertExactJson(['count' => 0]);

    $this->actingAs($this->other)->getJson('/notifikasi/jumlah')->assertExactJson(['count' => 1]);
});

test('notifikasi butuh login', function () {
    $this->get('/notifikasi')->assertRedirect('/login');
    $this->getJson('/notifikasi/jumlah')->assertUnauthorized();
});
