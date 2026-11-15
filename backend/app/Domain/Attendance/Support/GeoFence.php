<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Organization\Enums\AttendanceMode;
use App\Domain\Organization\Models\Location;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;

/**
 * Validasi lokasi absen (BR-041, BR-064, SEC-023).
 *
 * - Akurasi > `attendance.max_accuracy_m` → `ATTENDANCE_LOW_ACCURACY`.
 * - Toleransi area = radius + min(akurasi, 50 m): GPS yang wajar meleset tidak langsung ditolak,
 *   tetapi akurasi buruk tidak bisa "memperluas" radius tanpa batas.
 * - `location`: area = lokasi penugasan utama (tanpa koordinat → ditolak, BR-041).
 * - `any_location`: area = semua lokasi aktif ber-koordinat; dipilih yang terdekat di antara yang
 *   memuat titik (bukan sekadar yang terdekat: lokasi dengan radius besar bisa memuat titik yang
 *   lebih dekat ke lokasi lain).
 * - `anywhere`: tidak ditolak; area terdekat & jarak tetap dicatat (`within_radius` → anomali).
 *
 * `$extraAreas` = area tambahan yang juga sah (T-036A: tujuan tugas luar kota yang disetujui),
 * digabung ke area mode biasa tanpa mengubah aturan di atas.
 */
final class GeoFence
{
    /** Batas tambahan toleransi dari akurasi GPS (BR-064). */
    public const MAX_ACCURACY_BONUS_M = 50;

    /**
     * @param  list<GeoArea>  $extraAreas
     */
    public function check(float $latitude, float $longitude, float $accuracyM, AttendanceMode $mode, ?Location $primary, array $extraAreas = []): GeoResult
    {
        if ($accuracyM > (int) setting('attendance.max_accuracy_m')) {
            throw new ApiError(ErrorCode::ATTENDANCE_LOW_ACCURACY, ['accuracy_m' => (int) round($accuracyM), 'max_accuracy_m' => (int) setting('attendance.max_accuracy_m')]);
        }

        $bonus = min($accuracyM, self::MAX_ACCURACY_BONUS_M);

        if ($mode === AttendanceMode::Location) {
            $home = $primary === null ? null : GeoArea::fromLocation($primary);
            if ($home === null && $extraAreas === []) {
                // BR-041: tanpa titik lokasi tidak ada yang bisa dibandingkan.
                throw new ApiError(ErrorCode::ATTENDANCE_OUT_OF_RADIUS, ['location_name' => $primary?->name], 'Koordinat lokasi belum diatur. Hubungi HRD.');
            }

            return $this->strict(array_values(array_filter([$home, ...$extraAreas])), $latitude, $longitude, $bonus);
        }

        $areas = [...self::activeLocationAreas(), ...$extraAreas];

        if ($mode === AttendanceMode::AnyLocation) {
            if ($areas === []) {
                throw new ApiError(ErrorCode::ATTENDANCE_OUT_OF_RADIUS, [], 'Koordinat lokasi belum diatur. Hubungi HRD.');
            }

            return $this->strict($areas, $latitude, $longitude, $bonus);
        }

        // anywhere: catat yang terdekat (atau yang memuat titik bila ada), tidak pernah menolak.
        if ($areas === []) {
            return new GeoResult(null, null, false);
        }

        [$area, $distance, $within] = self::pick($areas, $latitude, $longitude, $bonus);

        return new GeoResult($area, (int) round($distance), $within);
    }

    /**
     * Area yang memuat titik wajib ada; bila tidak, tolak dengan detail area terdekat (05 §2.3).
     *
     * @param  list<GeoArea>  $areas
     */
    private function strict(array $areas, float $latitude, float $longitude, float $bonus): GeoResult
    {
        if ($areas === []) {
            throw new ApiError(ErrorCode::ATTENDANCE_OUT_OF_RADIUS, [], 'Koordinat lokasi belum diatur. Hubungi HRD.');
        }

        [$area, $distance, $within] = self::pick($areas, $latitude, $longitude, $bonus);

        if (! $within) {
            throw new ApiError(ErrorCode::ATTENDANCE_OUT_OF_RADIUS, [
                'distance_m' => (int) round($distance),
                'radius_m' => $area->radiusM,
                'location_name' => $area->name,
            ]);
        }

        return new GeoResult($area, (int) round($distance), true);
    }

    /**
     * Area terdekat yang memuat titik; bila tidak ada, area terdekat secara mutlak.
     *
     * @param  non-empty-list<GeoArea>  $areas
     * @return array{0: GeoArea, 1: float, 2: bool}
     */
    private static function pick(array $areas, float $latitude, float $longitude, float $bonus): array
    {
        $nearest = null;
        $nearestInside = null;

        foreach ($areas as $area) {
            $distance = $area->distanceTo($latitude, $longitude);
            if ($nearest === null || $distance < $nearest[1]) {
                $nearest = [$area, $distance];
            }
            if ($distance <= $area->radiusM + $bonus && ($nearestInside === null || $distance < $nearestInside[1])) {
                $nearestInside = [$area, $distance];
            }
        }

        return $nearestInside !== null ? [...$nearestInside, true] : [...$nearest, false];
    }

    /** @return list<GeoArea> lokasi aktif yang punya koordinat */
    private static function activeLocationAreas(): array
    {
        $areas = [];
        $locations = Location::query()->where('is_active', true)->whereNotNull('latitude')->whereNotNull('longitude')->get();
        foreach ($locations as $location) {
            $area = GeoArea::fromLocation($location);
            if ($area !== null) {
                $areas[] = $area;
            }
        }

        return $areas;
    }
}
