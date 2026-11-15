<?php

namespace App\Domain\Device\Support;

/**
 * Perbandingan versi semver sederhana `MAJOR.MINOR.PATCH` (BR-027). Bagian pre-release/build
 * (`-beta`, `+5`) diabaikan; nilai yang tidak bisa dibaca dianggap `0.0.0` agar klien tanpa
 * header versi yang sah tetap diminta memperbarui (fail-closed).
 */
final class SemVer
{
    /** @return array{int, int, int} */
    public static function parse(?string $version): array
    {
        if ($version === null || preg_match('/^\s*v?(\d{1,5})(?:\.(\d{1,5}))?(?:\.(\d{1,5}))?/', $version, $m) !== 1) {
            return [0, 0, 0];
        }

        return [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0)];
    }

    public static function lessThan(?string $version, string $minimum): bool
    {
        return self::parse($version) < self::parse($minimum);
    }

    /** `1.2.3` dari header; null bila tidak sah (disimpan di `devices.app_version`). */
    public static function normalize(?string $version): ?string
    {
        if ($version === null || preg_match('/^\s*v?\d{1,5}(\.\d{1,5}){0,2}/', $version) !== 1) {
            return null;
        }

        return implode('.', self::parse($version));
    }
}
