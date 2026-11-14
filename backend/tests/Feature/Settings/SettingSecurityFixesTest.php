<?php

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/*
 * Perbaikan temuan review keamanan T-008.
 */

beforeEach(function () {
    Storage::fake('public');
    Gate::define('settings.view', fn () => true);
    Gate::define('settings.update', fn () => true);
    $this->user = User::factory()->create();
});

test('SEC-040 berkas ke field non-gambar ditolak, nilai retensi tidak berubah', function () {
    $this->actingAs($this->user)
        ->from('/pengaturan/retention')
        ->put('/pengaturan/retention', ['retention__audit_years' => UploadedFile::fake()->image('x.png', 10, 10)])
        ->assertSessionHasErrors('retention__audit_years');

    expect(setting('retention.audit_years'))->toBe(5);
    Storage::disk('public')->assertDirectoryEmpty('branding');
});

test('SEC-040 audit:purge tetap menyimpan audit < 1 tahun walau setting rusak bernilai 0', function () {
    setSetting('retention.audit_years', 0);
    setSetting('retention.security_days', 0);

    // Tanpa batas bawah, "0 tahun" berarti batasnya = sekarang dan audit 2 hari ini ikut terhapus.
    $this->travelTo(now()->subDays(2));
    app(AuditLogger::class)->record('dua.hari.lalu');
    $this->travelBack();

    $this->artisan('audit:purge')->assertSuccessful();

    expect(DB::table('audit_logs')->pluck('event')->all())->toBe(['dua.hari.lalu']);
});

test('masa konfirmasi password mengikuti security.password_confirm_minutes', function (int $minutesAgo, bool $mustConfirm) {
    setSetting('security.password_confirm_minutes', 15);

    $response = $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->subMinutes($minutesAgo)->getTimestamp()])
        ->get('/pengaturan/security');

    $mustConfirm ? $response->assertRedirect(route('password.confirm')) : $response->assertOk();
})->with([
    'dikonfirmasi 10 menit lalu' => [10, false],
    'dikonfirmasi 20 menit lalu' => [20, true],
]);

test('ekspor audit (RequirePassword) juga mengikuti setting, bukan 3 jam bawaan', function () {
    Gate::define('audit.view', fn () => true);
    Gate::define('audit.export', fn () => true);
    setSetting('security.password_confirm_minutes', 15);

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->subMinutes(20)->getTimestamp()])
        ->get('/audit/ekspor')
        ->assertRedirect(route('password.confirm'));
});
