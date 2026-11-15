<?php

namespace App\Domain\Attendance\Support;

/**
 * Jarak permukaan bumi dua titik (BR-064). Jari-jari ekuator WGS-84 (6 378 137 m): 1° lintang ≈
 * 111 320 m, sama dengan konversi yang dipakai form lokasi & test. Selisih dengan jari-jari
 * rata-rata (< 0,2 %) tidak berarti untuk radius absen puluhan–ratusan meter.
 */
final class Haversine
{
    public const EARTH_RADIUS_M = 6_378_137;

    public static function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }
}
