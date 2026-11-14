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
     * validasi registri. Hasilnya hanya memuat `#0-9A-F:;{}.-` sehingga aman dicetak dengan `{{ }}`.
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

        $dark = self::darkPrimary($hex);

        return [
            'light' => [
                ...array_fill_keys(self::PRIMARY_TOKENS, $hex),
                '--primary-strong' => self::shift($hex, -0.07),
                '--logo-from' => $hex,
                ...self::panel($hex, [-0.03, -0.06, -0.14]),
            ],
            'dark' => [
                ...array_fill_keys(self::PRIMARY_TOKENS, $dark),
                '--primary-strong' => self::shift($hex, -0.04),
                '--logo-from' => self::shift($hex, 0.055),
                ...self::panel($hex, [-0.04, -0.11, -0.19]),
            ],
        ];
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
     * gelap di atas tombol primer (`--primary-foreground` mode gelap) juga ≥ 4.5:1.
     */
    private static function darkPrimary(string $hex): string
    {
        $dark = ContrastRatio::darkVariant($hex) ?? $hex; // problem() === null menjamin varian ada

        while (ContrastRatio::between($dark, self::DARK_PRIMARY_FOREGROUND) < ContrastRatio::MIN && ContrastRatio::toHsl($dark)[2] < 0.95) {
            $dark = self::shift($dark, 0.01);
        }

        return $dark;
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
