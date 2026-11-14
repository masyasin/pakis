<?php

namespace App\Domain\Access\Support;

/**
 * Label "Chrome · Windows" untuk daftar sesi (FR-003). Sengaja sederhana dan tanpa dependency:
 * hanya untuk membantu pengguna mengenali sesinya, bukan untuk keputusan keamanan apa pun.
 * Urutan pencocokan penting: UA Edge/Opera juga memuat "Chrome", UA Chrome juga memuat "Safari".
 */
final class UserAgentLabel
{
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser/' => 'Samsung Internet',
        'Firefox/' => 'Firefox',
        'FxiOS/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    private const PLATFORMS = [
        'Android' => 'Android',
        'iPhone' => 'iOS',
        'iPad' => 'iPadOS',
        'Windows' => 'Windows',
        'CrOS' => 'ChromeOS',
        'Mac OS X' => 'macOS',
        'Macintosh' => 'macOS',
        'Linux' => 'Linux',
    ];

    /**
     * @return array{browser: string, platform: string}
     */
    public static function parse(?string $userAgent): array
    {
        $agent = (string) $userAgent;

        return [
            'browser' => self::match($agent, self::BROWSERS),
            'platform' => self::match($agent, self::PLATFORMS),
        ];
    }

    /**
     * @param  array<string, string>  $map
     */
    private static function match(string $agent, array $map): string
    {
        foreach ($map as $needle => $label) {
            if (str_contains($agent, $needle)) {
                return $label;
            }
        }

        return 'Tidak dikenal';
    }
}
