<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * FR-019 / FR-020: halaman audit & log keamanan hanya untuk pemegang izin.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
});

function grantAudit(bool $export = false): void
{
    Gate::define('audit.view', fn () => true);
    Gate::define('audit.export', fn () => $export);
}

test('tanpa izin audit.view halaman audit & log keamanan 403 (deny-by-default sampai T-018)', function () {
    $this->actingAs($this->user)->get('/audit')->assertForbidden();
    $this->actingAs($this->user)->get('/log-keamanan')->assertForbidden();
});

test('tamu diarahkan ke login', function () {
    $this->get('/audit')->assertRedirect('/login');
});

test('daftar audit default 7 hari terakhir menyembunyikan catatan lebih lama', function () {
    grantAudit();

    $this->travelTo(now()->subDays(10));
    app(AuditLogger::class)->record('lama');
    $this->travelBack();
    app(AuditLogger::class)->record('baru');

    $this->actingAs($this->user)->get('/audit')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audit/index')
            ->has('rows', 1)
            ->where('rows.0.event', 'baru')
            ->where('listing.state.filters.occurred_at.to', now()->setTimezone('Asia/Jakarta')->toDateString())
            ->where('canExport', false));
});

test('cari objek dengan format tipe#id', function () {
    grantAudit();
    $target = User::factory()->create();
    app(AuditLogger::class)->record('user.updated', $target, ['name' => 'a'], ['name' => 'b']);
    app(AuditLogger::class)->record('user.updated', $this->user, ['name' => 'c'], ['name' => 'd']);

    $this->actingAs($this->user)->get('/audit?q=user%23'.$target->id)
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.object', 'User#'.$target->id));
});

test('halaman detail menampilkan diff lama/baru per kunci', function () {
    grantAudit();
    app(AuditLogger::class)->record('user.updated', $this->user, ['name' => 'Lama', 'role' => 'x'], ['name' => 'Baru', 'role' => 'y']);
    $log = AuditLog::query()->sole();

    $this->actingAs($this->user)->get("/audit/{$log->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audit/show')
            ->where('log.event', 'user.updated')
            ->where('changes.0', ['key' => 'name', 'old' => 'Lama', 'new' => 'Baru', 'changed' => true]));
});

test('ekspor audit butuh audit.export dan konfirmasi ulang password', function () {
    grantAudit(export: false);
    $this->actingAs($this->user)->get('/audit/ekspor')->assertForbidden();

    grantAudit(export: true);
    $this->actingAs($this->user)->get('/audit/ekspor')->assertRedirect(route('password.confirm'));

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get('/audit/ekspor')
        ->assertOk()
        ->assertDownload();
});
