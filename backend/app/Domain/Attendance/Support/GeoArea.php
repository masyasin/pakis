<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Organization\Models\Location;
use App\Support\Geo\Haversine;

/**
 * Area absen yang sah: titik + radius. Biasanya lokasi Pakis; T-036A (tugas luar kota) kelak
 * menambah area tujuan tugas (tanpa `locationId`) lewat parameter tambahan GeoFence.
 */
final readonly class GeoArea
{
    public function __construct(
        public string $name,
        public float $latitude,
        public float $longitude,
        public int $radiusM,
        public ?int $locationId = null,
        /** Area ini sah karena tugas luar kota itu (BR-071), bukan aturan biasa. */
        public ?int $fieldTripId = null,
    ) {}

    /** Null bila lokasi belum punya koordinat (BR-041: tidak bisa dipakai validasi radius). */
    public static function fromLocation(Location $location, ?int $fieldTripId = null): ?self
    {
        if (! $location->hasCoordinates()) {
            return null;
        }

        return new self($location->name, (float) $location->latitude, (float) $location->longitude, $location->effectiveRadius(), $location->id, $fieldTripId);
    }

    public function distanceTo(float $latitude, float $longitude): float
    {
        return Haversine::meters($this->latitude, $this->longitude, $latitude, $longitude);
    }
}
