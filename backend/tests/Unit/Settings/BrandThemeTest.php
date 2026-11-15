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

/**
 * Token `--x: nilai;` dari satu blok app.css (`:root` atau `.dark`).
 *
 * @return array<string, string>
 */
function cssBlockTokens(string $selector): array
{
    $css = (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/app.css');
    preg_match('/^'.preg_quote($selector, '/').' \{(.*?)^\}/ms', $css, $m);
    preg_match_all('/(--[a-z0-9-]+):\s*([^;]+);/', $m[1] ?? '', $pairs, PREG_SET_ORDER);

    return array_column($pairs, 2, 1);
}

test('salinan token netral di BrandTheme sama persis dengan app.css', function () {
    $light = cssBlockTokens(':root');
    $dark = cssBlockTokens('.dark');

    foreach (BrandTheme::NEUTRALS_LIGHT as $token => $value) {
        expect($light[$token] ?? null)->toBe($value, "app.css :root {$token}");
    }
    foreach (BrandTheme::NEUTRALS_DARK as $token => $value) {
        expect($dark[$token] ?? null)->toBe($value, "app.css .dark {$token}");
    }
});

test('BR-021 warna default menurunkan netral yang identik dengan desain (tint = identitas)', function () {
    $p = BrandTheme::palette(BrandTheme::DEFAULT_PRIMARY);

    foreach (['light' => BrandTheme::NEUTRALS_LIGHT, 'dark' => BrandTheme::NEUTRALS_DARK] as $mode => $tokens) {
        foreach ($tokens as $token => $value) {
            expect(strtolower($p[$mode][$token]))->toBe($value, "{$mode} {$token}");
        }
    }
});

test('BR-021 latar, kepala tabel, menu aktif, dan garis ikut hue warna kustom', function () {
    $p = BrandTheme::palette('#1F4E79'); // navy

    foreach (['--background', '--muted', '--sidebar-accent', '--border'] as $token) {
        [$r, $g, $b] = sscanf($p['light'][$token], '#%2x%2x%2x');
        expect($b)->toBeGreaterThan($g, $token)->and($b)->toBeGreaterThan($r, $token);
    }
    [$r, $g, $b] = sscanf($p['dark']['--background'], '#%2x%2x%2x');
    expect($b)->toBeGreaterThan($g);

    expect($p['light']['--shadow-color'])->toMatch('/^\d{1,3} \d{1,3}% \d{1,3}%$/')
        ->not->toBe(BrandTheme::NEUTRALS_LIGHT['--shadow-color']);
});

test('BR-021 warna primer abu-abu menghasilkan netral abu-abu murni', function () {
    $p = BrandTheme::palette('#3D3D3D');

    [$r, $g, $b] = sscanf($p['light']['--background'], '#%2x%2x%2x');
    expect($r)->toBe($g)->and($g)->toBe($b);
});

test('BR-021 kontras netral hasil tint tetap AA (teks 4.5:1, garis isian 3:1)', function (string $hex) {
    $p = BrandTheme::palette($hex);

    foreach (['light', 'dark'] as $mode) {
        $t = $p[$mode];
        $surfaces = [$t['--background'], $t['--card'] ?? '#FFFFFF', $t['--muted'], $t['--secondary'], $t['--accent']];

        foreach (['--foreground', '--muted-foreground', '--sidebar-foreground', '--accent-foreground'] as $text) {
            foreach ($surfaces as $surface) {
                expect(ContrastRatio::between($t[$text], $surface))->toBeGreaterThanOrEqual(4.5, "{$mode} {$text} di {$surface}");
            }
        }

        expect(ContrastRatio::between($t['--sidebar-accent-foreground'], $t['--sidebar-accent']))->toBeGreaterThanOrEqual(4.5, "{$mode} menu aktif")
            ->and(ContrastRatio::between($t['--status-muted'], $t['--status-muted-bg']))->toBeGreaterThanOrEqual(4.5, "{$mode} status netral")
            ->and(ContrastRatio::between($t['--input'], $t['--background']))->toBeGreaterThanOrEqual(3.0, "{$mode} garis isian");
    }

    // Primer mode gelap juga terbaca di atas latar/kartu/sidebar yang sudah di-tint.
    foreach (['--background', '--card', '--sidebar'] as $surface) {
        expect(ContrastRatio::between($p['dark']['--primary'], $p['dark'][$surface]))->toBeGreaterThanOrEqual(4.5, "dark primer di {$surface}");
    }
})->with(['#1F4E79', '#8B1E1E', '#5B2C83', '#3D3D3D', '#2F6B3B', '#0F5257', '#7A4B00']);

test('BR-021 nilai rusak (diisi langsung ke DB) tidak pernah dicetak ke CSS', function (string $value) {
    expect(BrandTheme::css($value))->toBeNull();
})->with(['#000;}</style><script>', 'red', '#12345', '#1234567', 'url(x)', '']);
