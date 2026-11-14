<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * T-008 bag. 2: branding dari Pengaturan diterapkan ke halaman web (BR-021, SEC-063).
 */

function brandStyleBlock(string $html): ?string
{
    return preg_match('/<style nonce="([^"]+)">(html:root\{.*?)<\/style>/s', $html, $m) ? $m[0] : null;
}

test('default: tidak ada override warna, nama aplikasi dari setting', function () {
    $html = $this->get('/login')->assertOk()->getContent();

    expect(brandStyleBlock($html))->toBeNull()
        ->and($html)->toContain('<meta name="application-name" content="PAKIS ONE">')
        ->and($html)->toContain('<link rel="icon" href="/favicon.ico" sizes="any">');
});

test('SEC-063 BR-021 warna kustom dicetak di <style> ber-nonce yang sama dengan header CSP', function () {
    config(['pakis.csp.mode' => 'enforce']);
    setSetting('branding.primary_color', '#1F4E79');

    $response = $this->get('/login')->assertOk();
    $html = $response->getContent();

    preg_match('/<style nonce="([^"]+)">html:root\{/', $html, $m);
    expect($m[1] ?? null)->not->toBeNull()
        ->and($response->headers->get('Content-Security-Policy'))->toContain("'nonce-{$m[1]}'")
        ->and(brandStyleBlock($html))->toContain('--primary:#1F4E79;');
});

test('nama aplikasi & favicon kustom dipakai di dokumen HTML', function () {
    setSetting('general.app_name', 'Pakis Demo');
    setSetting('branding.favicon', 'branding/favicon-uji.png');

    $html = $this->get('/login')->getContent();

    expect($html)->toContain('<meta name="application-name" content="Pakis Demo">')
        ->and($html)->toContain('<link rel="icon" href="/storage/branding/favicon-uji.png">')
        ->and($html)->not->toContain('href="/favicon.ico"');
});

test('branding dibagikan ke Inertia dengan URL logo relatif (lolos CSP img-src self)', function () {
    setSetting('general.app_name', 'Pakis Demo');
    setSetting('general.company_name', 'CV Contoh Tani');
    setSetting('branding.login_tagline', 'Tagline uji');
    setSetting('branding.logo_light', 'branding/logo-terang.png');

    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('name', 'Pakis Demo')
            ->where('branding.company', 'CV Contoh Tani')
            ->where('branding.loginTagline', 'Tagline uji')
            ->where('branding.logoLight', '/storage/branding/logo-terang.png')
            ->where('branding.logoDark', null));
});

test('tamu di halaman login juga menerima branding', function () {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page
        ->where('name', 'PAKIS ONE')
        ->where('branding.company', 'CV Pakis Jaya Abadi')
        ->where('branding.loginTagline', 'Distribusi pestisida & alat pertanian'));
});

test('simpan grup branding lewat Inertia memuat ulang halaman penuh agar warna baru langsung tampil', function () {
    Gate::define('settings.view', fn () => true);
    Gate::define('settings.update', fn () => true);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->put('/pengaturan/branding', ['values' => ['branding__primary_color' => '#1F4E79']])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('admin.settings.edit', ['group' => 'branding']));

    // Grup lain tetap redirect Inertia biasa (tanpa muat ulang).
    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->put('/pengaturan/ui', ['values' => []])
        ->assertRedirect(route('admin.settings.edit', ['group' => 'ui']));
});
