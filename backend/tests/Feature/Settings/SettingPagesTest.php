<?php

use App\Domain\Settings\SettingRegistry;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'admin@pakis-demo.test']);
});

function grantSettings(bool $update = true): void
{
    Gate::define('settings.view', fn () => true);
    Gate::define('settings.update', fn () => $update);
}

test('tanpa izin halaman pengaturan 403 (deny-by-default sampai T-018)', function () {
    $this->actingAs($this->user)->get('/pengaturan')->assertForbidden();
    $this->actingAs($this->user)->put('/pengaturan/general', ['values' => []])->assertForbidden();
});

test('halaman pengaturan menampilkan tab grup dan field otomatis dari registri', function () {
    grantSettings();

    $this->actingAs($this->user)->get('/pengaturan/attendance')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('site-settings/edit')
            ->where('group', 'attendance')
            // Paket A menambah grup bertahap (15 §9); tab = seluruh GROUPS registri.
            ->has('groups', count(SettingRegistry::GROUPS))
            ->where('fields.1.key', 'attendance.default_radius_m')
            ->where('fields.1.value', 100)
            ->where('fields.1.default', 100)
            ->where('fields.1.customized', false)
            ->where('fields.1.min', 20)
            ->where('fields.1.max', 5000));
});

test('grup tak dikenal 404', function () {
    grantSettings();

    $this->actingAs($this->user)->get('/pengaturan/rahasia')->assertNotFound();
});

test('grup security & auth minta konfirmasi password sebelum dibuka dan disimpan', function (string $group) {
    grantSettings();

    $this->actingAs($this->user)->get("/pengaturan/{$group}")->assertRedirect(route('password.confirm'));
    $this->actingAs($this->user)->put("/pengaturan/{$group}", ['values' => []])->assertRedirect(route('password.confirm'));

    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()])
        ->get("/pengaturan/{$group}")->assertOk();
})->with(['security', 'auth']);

test('pemegang settings.view tanpa settings.update tidak bisa menyimpan', function () {
    grantSettings(update: false);

    $this->actingAs($this->user)->get('/pengaturan')
        ->assertInertia(fn (Assert $page) => $page->where('canUpdate', false));
    $this->actingAs($this->user)->put('/pengaturan/general', ['values' => ['general__app_name' => 'X Y']])->assertForbidden();
});

test('email uji dikirim ke pengguna sendiri', function () {
    grantSettings();
    Mail::fake();

    $this->actingAs($this->user)->from('/pengaturan/notification')->post('/pengaturan/uji-email')
        ->assertRedirect('/pengaturan/notification');

    Mail::assertSentCount(1);
});
