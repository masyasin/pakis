<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Settings\Actions\UpdateSettingGroup;
use App\Domain\Settings\SettingStore;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/*
 * BR-020–BR-024: penyimpanan per grup, validasi, cache, audit, kembalikan default.
 */

beforeEach(function () {
    Gate::define('settings.view', fn () => true);
    Gate::define('settings.update', fn () => true);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function putSettings(string $group, array $values = [], array $reset = [])
{
    return test()->from("/pengaturan/{$group}")->put("/pengaturan/{$group}", ['values' => $values, 'reset' => $reset]);
}

test('BR-020 nilai valid tersimpan dan dibaca dengan tipe yang benar', function () {
    putSettings('attendance', [
        'attendance__default_radius_m' => '150',
        'attendance__allow_unscheduled' => '1',
        'attendance__daily_close_time' => '22:00',
    ])->assertRedirect('/pengaturan/attendance')->assertSessionHasNoErrors();

    expect(setting('attendance.default_radius_m'))->toBe(150)
        ->and(setting('attendance.allow_unscheduled'))->toBeTrue()
        ->and(setting('attendance.daily_close_time'))->toBe('22:00');
});

test('BR-020 kunci tak dikenal atau milik grup lain ditolak 422', function () {
    putSettings('attendance', ['attendance__tidak_ada' => 1])->assertSessionHasErrors('attendance__tidak_ada');
    putSettings('attendance', ['general__app_name' => 'Bocor'])->assertSessionHasErrors('general__app_name');

    expect(setting('general.app_name'))->toBe('PAKIS ONE');
});

test('BR-020 nilai di luar batas ditolak dengan pesan per field', function (string $group, string $field, mixed $value) {
    putSettings($group, [$field => $value])->assertSessionHasErrors($field);
})->with([
    'radius terlalu kecil' => ['attendance', 'attendance__default_radius_m', 5],
    'radius bukan angka' => ['attendance', 'attendance__default_radius_m', 'jauh'],
    'nama aplikasi terlalu pendek' => ['general', 'general__app_name', 'P'],
    'zona waktu asing' => ['general', 'general__timezone', 'Europe/London'],
    'website http' => ['general', 'general__website', 'http://pakis.test'],
    'telepon ngawur' => ['general', 'general__phone', '12ab'],
    'per halaman bukan opsi' => ['ui', 'ui__default_per_page', 30],
    'jam salah' => ['attendance', 'attendance__daily_close_time', '25:00'],
    'semver salah' => ['mobile', 'mobile__min_version', '1.0'],
    'email monitoring > 5' => ['monitoring', 'monitoring__alert_emails', "a@x.test\nb@x.test\nc@x.test\nd@x.test\ne@x.test\nf@x.test"],
    'email monitoring rusak' => ['monitoring', 'monitoring__alert_emails', 'bukan-email'],
]);

test('BR-021 warna kontras rendah #9BE59B ditolak, #2F6B3B diterima', function () {
    putSettings('branding', ['branding__primary_color' => '#9BE59B'])->assertSessionHasErrors('branding__primary_color');
    putSettings('branding', ['branding__primary_color' => '#2F6B3B'])->assertSessionHasNoErrors();
});

test('BR-027 versi terbaru tidak boleh lebih rendah dari minimum (lintas kunci)', function () {
    putSettings('mobile', ['mobile__min_version' => '2.0.0', 'mobile__latest_version' => '1.9.9'])
        ->assertSessionHasErrors('mobile__latest_version');

    putSettings('mobile', ['mobile__min_version' => '2.0.0', 'mobile__latest_version' => '2.1.0'])->assertSessionHasNoErrors();
    expect(setting('mobile.latest_version'))->toBe('2.1.0');
});

test('kanal WhatsApp ditolak di produksi bila driver OTP masih log', function () {
    // Action dipanggil langsung: mengganti environment ke production lewat HTTP juga menyalakan CSRF.
    app()->detectEnvironment(fn () => 'production');
    config(['otp.channels.whatsapp.driver' => 'log']);

    expect(fn () => app(UpdateSettingGroup::class)->handle('auth', ['auth__forgot_channels' => ['email', 'whatsapp']], [], [], null))
        ->toThrow(ValidationException::class, 'penyedia OTP belum dikonfigurasi');

    // Driver lokal di non-produksi tersedia.
    app()->detectEnvironment(fn () => 'testing');
    app(UpdateSettingGroup::class)->handle('auth', ['auth__forgot_channels' => ['email', 'whatsapp']], [], [], null);
    app()->forgetScopedInstances();

    expect(setting('auth.forgot_channels'))->toBe(['email', 'whatsapp']);
});

test('BR-023 setiap kunci yang berubah diaudit dengan nilai lama & baru', function () {
    putSettings('export', ['export__sync_max_rows' => 500, 'export__max_rows' => 100000])->assertSessionHasNoErrors();

    $logs = AuditLog::query()->where('event', 'setting.updated')->get();

    // max_rows tidak berubah (sama dengan default) → tidak diaudit.
    expect($logs)->toHaveCount(1)
        ->and($logs[0]->old_values)->toBe(['export.sync_max_rows' => 2000])
        ->and($logs[0]->new_values)->toBe(['export.sync_max_rows' => 500])
        ->and($logs[0]->user_id)->toBe($this->user->id);
});

test('BR-022 cache grup dibersihkan saat disimpan', function () {
    expect(setting('ui.max_per_page'))->toBe(100); // mengisi cache

    putSettings('ui', ['ui__max_per_page' => 50])->assertSessionHasNoErrors();
    app()->forgetScopedInstances();

    expect(setting('ui.max_per_page'))->toBe(50);
});

test('BR-024 kembalikan default menghapus nilai tersimpan dan diaudit', function () {
    putSettings('attendance', ['attendance__default_radius_m' => 300]);
    expect(app(SettingStore::class)->isCustomized('attendance.default_radius_m'))->toBeTrue();

    putSettings('attendance', [], ['attendance__default_radius_m'])->assertSessionHasNoErrors();
    app()->forgetScopedInstances();

    expect(setting('attendance.default_radius_m'))->toBe(100)
        ->and(app(SettingStore::class)->isCustomized('attendance.default_radius_m'))->toBeFalse()
        ->and(AuditLog::query()->where('event', 'setting.updated')->count())->toBe(2);
});

test('nilai setting dipakai mesin listing (ui.default_per_page)', function () {
    setSetting('ui.default_per_page', 50);

    expect(runListing()->paginator()->perPage())->toBe(50);
});
