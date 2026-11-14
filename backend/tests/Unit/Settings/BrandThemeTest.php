<?php

use App\Domain\Settings\Branding\BrandTheme;
use App\Domain\Settings\Validation\ContrastRatio;

/*
 * BR-021: warna utama kustom diterapkan ke token tema tanpa merusak kontras AA, terang & gelap.
 */

test('konstanta warna default sama dengan default registri', function () {
    expect(BrandTheme::DEFAULT_PRIMARY)->toBe(BrandTheme::registryDefault());
});

test('BR-021 warna default tidak menghasilkan override (token desain di app.css dipakai apa adanya)', function () {
    expect(BrandTheme::css(BrandTheme::DEFAULT_PRIMARY))->toBeNull()
        ->and(BrandTheme::css(strtolower(BrandTheme::DEFAULT_PRIMARY)))->toBeNull();
});

test('BR-021 warna kustom menjadi token terang & gelap', function () {
    $css = BrandTheme::css('#1F4E79');
    $palette = BrandTheme::palette('#1F4E79');

    expect($css)->toStartWith('html:root{')
        ->toContain('--primary:#1F4E79;')
        ->toContain('--ring:#1F4E79;')
        ->toContain('--sidebar-primary:#1F4E79;')
        ->toContain('html.dark{')
        ->toContain('--primary:'.$palette['dark']['--primary'].';');
});

test('BR-021 kontras palet hasil turunan tetap ≥ 4.5:1', function (string $hex) {
    expect(ContrastRatio::problem($hex))->toBeNull(); // hanya warna yang lolos validasi registri

    $p = BrandTheme::palette($hex);

    // Mode gelap: teks/tombol primer di atas latar gelap, dan teks gelap di atas tombol primer.
    expect(ContrastRatio::between($p['dark']['--primary'], ContrastRatio::DARK_BACKGROUND))->toBeGreaterThanOrEqual(4.5)
        ->and(ContrastRatio::between($p['dark']['--primary'], BrandTheme::DARK_PRIMARY_FOREGROUND))->toBeGreaterThanOrEqual(4.5);

    // Lencana logo & varian kuat: ikon/teks putih.
    foreach (['light', 'dark'] as $mode) {
        expect(ContrastRatio::between($p[$mode]['--primary-strong'], '#FFFFFF'))->toBeGreaterThanOrEqual(4.5);

        // Panel login: teks putih & teks redup di bagian gradien yang paling terang.
        expect(ContrastRatio::between('#FFFFFF', $p[$mode]['--brand-panel-from']))->toBeGreaterThanOrEqual(4.5)
            ->and(ContrastRatio::between($p[$mode]['--brand-panel-muted'], $p[$mode]['--brand-panel-from']))->toBeGreaterThanOrEqual(4.5);
    }
})->with(['#1F4E79', '#8B1E1E', '#5B2C83', '#3D3D3D', '#2F6B3B', '#0F5257', '#7A4B00']);

test('BR-021 nilai rusak (diisi langsung ke DB) tidak pernah dicetak ke CSS', function (string $value) {
    expect(BrandTheme::css($value))->toBeNull();
})->with(['#000;}</style><script>', 'red', '#12345', '#1234567', 'url(x)', '']);
