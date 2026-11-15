<?php

namespace App\Domain\Settings\Branding;

use App\Domain\Settings\SettingRegistry;
use App\Domain\Settings\SettingStore;
use App\Domain\Settings\Validation\ContrastRatio;

/**
 * Branding dari Pengaturan → tampilan web (T-008, BR-021).
 *
 * Warna default TIDAK menghasilkan override: token hasil desain di `resources/css/app.css`
 * (yang disetel manual & terukur) tetap dipakai. Hanya warna kustom yang diturunkan menjadi
 * token terang/gelap di sini, dengan kontras AA yang dijaga (lihat tests/Unit/Settings/BrandThemeTest).
 */
final class BrandTheme
{
    public const DEFAULT_PRIMARY = '#2F6B3B';

    /** `--primary-foreground` mode gelap di app.css (teks gelap di atas tombol hijau muda). */
    public const DARK_PRIMARY_FOREGROUND = '#0E1A11';

    private const HEX = '/^#[0-9A-F]{6}$/';

    /** Token yang ikut warna primer (app.css `:root` / `.dark`). */
    private const PRIMARY_TOKENS = ['--primary', '--ring', '--chart-1', '--sidebar-primary', '--sidebar-ring'];

    /**
     * Token netral bawaan (salinan persis app.css, dijaga test) yang di-tint ke hue warna kustom:
     * latar, kartu, kepala tabel (`muted`), menu aktif (`sidebar-accent`), garis, bayangan.
     * Putih murni mode terang tidak dicantumkan karena tetap putih setelah di-tint.
     */
    public const NEUTRALS_LIGHT = [
        '--background' => '#f3f6f2',
        '--foreground' => '#16241b',
        '--card-foreground' => '#16241b',
        '--popover-foreground' => '#16241b',
        '--secondary' => '#e8eee9',
        '--secondary-foreground' => '#16241b',
        '--muted' => '#ebf0ec',
        '--muted-foreground' => '#56675b',
        '--accent' => '#e6eee7',
        '--accent-foreground' => '#16241b',
        '--border' => '#d8e2da',
        '--input' => '#7f9384',
        '--sidebar-foreground' => '#16241b',
        '--sidebar-accent' => '#e5f0e7',
        '--sidebar-border' => '#d8e2da',
        '--status-muted' => '#4a5a4f',
        '--status-muted-bg' => '#ecf0ed',
        '--shadow-color' => '140 25% 12%',
    ];

    public const NEUTRALS_DARK = [
        '--background' => '#121814',
        '--foreground' => '#e5ede7',
        '--card' => '#1a221d',
        '--card-foreground' => '#e5ede7',
        '--popover' => '#1a221d',
        '--popover-foreground' => '#e5ede7',
        '--secondary' => '#232d26',
        '--secondary-foreground' => '#e5ede7',
        '--muted' => '#222b25',
        '--muted-foreground' => '#a3b3a8',
        '--accent' => '#253029',
        '--accent-foreground' => '#e5ede7',
        '--border' => '#2f3c33',
        '--input' => '#6b7e71',
        '--sidebar' => '#0e1410',
        '--sidebar-foreground' => '#e5ede7',
        '--sidebar-accent' => '#1e3325',
        '--sidebar-accent-foreground' => '#9bd0aa',
        '--sidebar-border' => '#232d26',
        '--status-muted' => '#b5c3b9',
        '--status-muted-bg' => '#252e28',
        '--shadow-color' => '150 30% 2%',
    ];

    /** Batas kontras komponen non-teks (garis isian), WCAG 1.4.11. */
    private const NON_TEXT_MIN = 3.0;

    /** Batas penguatan saturasi netral agar warna mencolok tidak membuat latar terlalu berwarna. */
    private const MAX_SATURATION_GAIN = 1.3;

    public function __construct(private readonly SettingStore $settings) {}

    /**
     * Data untuk `resources/views/app.blade.php`.
     *
     * @return array{title: string, favicon: string|null, css: string|null}
     */
    public function forView(): array
    {
        return [
            'title' => (string) $this->settings->get('general.app_name'),
            'favicon' => ImageProcessor::publicUrl($this->stringOrNull('branding.favicon')),
            'css' => self::css((string) $this->settings->get('branding.primary_color')),
        ];
    }

    /**
     * Props bersama Inertia (`branding`).
     *
     * @return array{company: string, logoLight: string|null, logoDark: string|null, loginTagline: string|null}
     */
    public function shared(): array
    {
        return [
            'company' => (string) $this->settings->get('general.company_name'),
            'logoLight' => ImageProcessor::publicUrl($this->stringOrNull('branding.logo_light')),
            'logoDark' => ImageProcessor::publicUrl($this->stringOrNull('branding.logo_dark')),
            'loginTagline' => $this->stringOrNull('branding.login_tagline'),
        ];
    }

    /**
     * Blok CSS override, atau null bila warna default / tidak sah.
     *
     * Nilai yang tidak lolos regex hex tidak pernah dicetak, walau DB diisi langsung tanpa
     * validasi registri. Hasilnya hanya memuat `#0-9A-F:;{}.-%` dan spasi (nilai `--shadow-color`)
     * sehingga aman dicetak dengan `{{ }}`.
     */
    public static function css(string $hex): ?string
    {
        $palette = strtoupper(trim($hex)) === self::DEFAULT_PRIMARY ? null : self::palette($hex);
        if ($palette === null) {
            return null;
        }

        $block = fn (array $tokens) => implode('', array_map(fn ($k, $v) => "{$k}:{$v};", array_keys($tokens), $tokens));

        return 'html:root{'.$block($palette['light']).'}html.dark{'.$block($palette['dark']).'}';
    }

    /**
     * Token turunan untuk satu warna sah (juga untuk warna default, agar bisa diuji); null bila tidak sah.
     *
     * @return array{light: array<string, string>, dark: array<string, string>}|null
     */
    public static function palette(string $hex): ?array
    {
        $hex = strtoupper(trim($hex));
        if (! preg_match(self::HEX, $hex) || ContrastRatio::problem($hex) !== null) {
            return null;
        }

        $light = self::tintNeutrals(self::NEUTRALS_LIGHT, $hex);
        // Teks menu aktif mode terang = warna primer itu sendiri (seperti app.css), dijaga di atas tint-nya.
        $light['--sidebar-accent-foreground'] = $hex;
        $light = self::guardText($light, -1);

        $darkNeutrals = self::guardText(self::tintNeutrals(self::NEUTRALS_DARK, $hex), 1);
        $dark = self::darkPrimary($hex, [$darkNeutrals['--background'], $darkNeutrals['--card'], $darkNeutrals['--sidebar']]);

        return [
            'light' => [
                ...array_fill_keys(self::PRIMARY_TOKENS, $hex),
                '--primary-strong' => self::shift($hex, -0.07),
                '--logo-from' => $hex,
                ...self::panel($hex, [-0.03, -0.06, -0.14]),
                ...$light,
            ],
            'dark' => [
                ...array_fill_keys(self::PRIMARY_TOKENS, $dark),
                '--primary-strong' => self::shift($hex, -0.04),
                '--logo-from' => self::shift($hex, 0.055),
                ...self::panel($hex, [-0.04, -0.11, -0.19]),
                ...$darkNeutrals,
            ],
        ];
    }

    /**
     * Putar hue token netral sebesar selisih hue primer kustom vs default dan skalakan saturasinya
     * sebanding (primer abu-abu → netral abu-abu murni). Lightness tidak diubah sehingga hierarki
     * terang-gelap hasil desain tetap, hanya "nadanya" yang mengikuti warna brand.
     *
     * @param  array<string, string>  $tokens
     * @return array<string, string>
     */
    private static function tintNeutrals(array $tokens, string $hex): array
    {
        [$h, $s] = ContrastRatio::toHsl($hex);
        [$h0, $s0] = ContrastRatio::toHsl(self::DEFAULT_PRIMARY);
        $deltaHue = $h - $h0;
        $gain = min(self::MAX_SATURATION_GAIN, $s / $s0);

        $out = [];
        foreach ($tokens as $token => $value) {
            if ($token === '--shadow-color') {
                // Triplet "H S% L%" untuk `hsl(var(--shadow-color) / a)` di app.css.
                [$th, $ts, $tl] = array_map('floatval', explode(' ', str_replace('%', '', $value)));
                $out[$token] = sprintf('%d %d%% %d%%', (int) round(self::wrap($th / 360 + $deltaHue) * 360), (int) round(min(100.0, $ts * $gain)), (int) round($tl));

                continue;
            }

            [$th, $ts, $tl] = ContrastRatio::toHsl($value);
            $out[$token] = ContrastRatio::fromHsl(self::wrap($th + $deltaHue), min(1.0, $ts * $gain), $tl);
        }

        return $out;
    }

    /**
     * Rotasi hue mengubah luminans (biru lebih gelap daripada hijau pada lightness sama), maka tiap
     * pasangan teks/latar dijaga ulang: teks ≥ 4.5:1, garis isian ≥ 3:1. `$direction` -1 = gelapkan
     * teks (mode terang), +1 = terangkan (mode gelap).
     *
     * @param  array<string, string>  $t
     * @return array<string, string>
     */
    private static function guardText(array $t, int $direction): array
    {
        $card = $t['--card'] ?? ContrastRatio::LIGHT_BACKGROUND;
        $surfaces = [$t['--background'], $card, $t['--muted'], $t['--secondary'], $t['--accent']];

        foreach (self::TEXT_TOKENS as $token) {
            $t[$token] = self::ensureContrast($t[$token], $surfaces, ContrastRatio::MIN, $direction);
        }

        $t['--sidebar-accent-foreground'] = self::ensureContrast($t['--sidebar-accent-foreground'], [$t['--sidebar-accent']], ContrastRatio::MIN, $direction);
        $t['--status-muted'] = self::ensureContrast($t['--status-muted'], [$t['--status-muted-bg']], ContrastRatio::MIN, $direction);
        $t['--input'] = self::ensureContrast($t['--input'], [$t['--background'], $card], self::NON_TEXT_MIN, $direction);

        return $t;
    }

    /** Token teks yang harus terbaca di atas semua permukaan netral. */
    private const TEXT_TOKENS = [
        '--foreground', '--card-foreground', '--popover-foreground', '--secondary-foreground',
        '--accent-foreground', '--sidebar-foreground', '--muted-foreground',
    ];

    /**
     * Geser lightness `$fg` bertahap sampai kontrasnya terhadap SEMUA `$backgrounds` ≥ `$min`.
     *
     * @param  non-empty-list<string>  $backgrounds
     */
    private static function ensureContrast(string $fg, array $backgrounds, float $min, int $direction): string
    {
        $passes = fn (string $c): bool => min(array_map(fn (string $bg) => ContrastRatio::between($c, $bg), $backgrounds)) >= $min;

        for ($i = 0; $i < 100 && ! $passes($fg); $i++) {
            $fg = self::shift($fg, 0.01 * $direction);
        }

        return $fg;
    }

    private static function wrap(float $hue): float
    {
        return $hue - floor($hue);
    }

    /**
     * Gradien panel login + warna teks redup. Panel digelapkan bertahap sampai teks redup
     * (dan otomatis teks putih) ≥ 4.5:1 di bagian gradien paling terang.
     *
     * @param  array{0: float, 1: float, 2: float}  $offsets  lightness from/via/to relatif terhadap primer
     * @return array<string, string>
     */
    private static function panel(string $hex, array $offsets): array
    {
        [$h, $s] = ContrastRatio::toHsl($hex);
        $muted = ContrastRatio::fromHsl($h, $s * 0.7, 0.85);
        $from = self::shift($hex, $offsets[0]);

        for ($extra = 0.0; $extra <= 0.3; $extra += 0.02) {
            $from = self::shift($hex, $offsets[0] - $extra);
            if (ContrastRatio::between($muted, $from) >= ContrastRatio::MIN) {
                break;
            }
        }

        return [
            '--brand-panel-from' => $from,
            '--brand-panel-via' => self::shift($hex, $offsets[1] - $extra),
            '--brand-panel-to' => self::shift($hex, $offsets[2] - $extra),
            '--brand-panel-muted' => $muted,
        ];
    }

    /**
     * Varian gelap BR-021 (≥ 4.5:1 terhadap latar gelap), dinaikkan lagi bila perlu agar teks
     * gelap di atas tombol primer (`--primary-foreground` mode gelap) dan primer di atas permukaan
     * gelap yang sudah di-tint (latar, kartu, sidebar) juga ≥ 4.5:1.
     *
     * @param  list<string>  $surfaces
     */
    private static function darkPrimary(string $hex, array $surfaces): string
    {
        $dark = ContrastRatio::darkVariant($hex) ?? $hex; // problem() === null menjamin varian ada

        return self::ensureContrast($dark, [self::DARK_PRIMARY_FOREGROUND, ...$surfaces], ContrastRatio::MIN, 1);
    }

    /** Geser lightness HSL, dijepit ke 0.04–0.96 agar tidak menjadi hitam/putih murni. */
    private static function shift(string $hex, float $delta): string
    {
        [$h, $s, $l] = ContrastRatio::toHsl($hex);

        return ContrastRatio::fromHsl($h, $s, max(0.04, min(0.96, $l + $delta)));
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->settings->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Untuk test & dokumentasi: warna default registri harus sama dengan konstanta di atas. */
    public static function registryDefault(): string
    {
        return strtoupper((string) SettingRegistry::get('branding.primary_color')->default);
    }
}
