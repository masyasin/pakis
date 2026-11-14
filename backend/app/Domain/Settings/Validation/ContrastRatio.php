<?php

namespace App\Domain\Settings\Validation;

/**
 * Rasio kontras WCAG 2.x dan varian gelap warna primer (BR-021).
 *
 * Warna primer dipakai sebagai latar tombol/teks di atas putih; harus ≥ 4.5:1 (AA). Mode gelap
 * memakai varian lebih terang yang juga harus ≥ 4.5:1 di atas latar gelap `#121814` (07 §1.1).
 */
final class ContrastRatio
{
    public const MIN = 4.5;

    public const LIGHT_BACKGROUND = '#FFFFFF';

    public const DARK_BACKGROUND = '#121814';

    public static function between(string $foreground, string $background): float
    {
        $a = self::luminance($foreground);
        $b = self::luminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * Varian untuk mode gelap: lightness HSL dinaikkan bertahap sampai kontras terhadap latar
     * gelap ≥ 4.5:1. Mengembalikan null bila tidak tercapai.
     */
    public static function darkVariant(string $hex): ?string
    {
        [$h, $s, $l] = self::toHsl($hex);

        for ($lightness = $l; $lightness <= 0.95; $lightness += 0.02) {
            $candidate = self::fromHsl($h, $s, $lightness);
            if (self::between($candidate, self::DARK_BACKGROUND) >= self::MIN) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Pesan galat Bahasa Indonesia bila warna tidak memenuhi BR-021, atau null bila lolos.
     */
    public static function problem(string $hex): ?string
    {
        $light = self::between($hex, self::LIGHT_BACKGROUND);
        if ($light < self::MIN) {
            return sprintf('Kontras terhadap putih %.2f:1, minimal 4.5:1. Pilih warna lebih gelap.', $light);
        }

        if (self::darkVariant($hex) === null) {
            return 'Varian mode gelap tidak mencapai kontras 4.5:1. Pilih warna lain.';
        }

        return null;
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public static function toHsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn (int $c) => $c / 255, self::rgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h / 6, $s, $l];
    }

    public static function fromHsl(float $h, float $s, float $l): string
    {
        if ($s == 0.0) {
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $r = self::hue($p, $q, $h + 1 / 3);
            $g = self::hue($p, $q, $h);
            $b = self::hue($p, $q, $h - 1 / 3);
        }

        return sprintf('#%02X%02X%02X', (int) round($r * 255), (int) round($g * 255), (int) round($b * 255));
    }

    private static function hue(float $p, float $q, float $t): float
    {
        $t += $t < 0 ? 1 : ($t > 1 ? -1 : 0);

        return match (true) {
            $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
            $t < 1 / 2 => $q,
            $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
            default => $p,
        };
    }
}
