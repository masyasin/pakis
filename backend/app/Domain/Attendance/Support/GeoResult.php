<?php

namespace App\Domain\Attendance\Support;

/**
 * Hasil GeoFence untuk disimpan di log: area cocok/terdekat, jarak (meter bulat), dalam radius, dan
 * tugas luar kota yang membuat absen ini sah (null = sah menurut aturan biasa, BR-071).
 */
final readonly class GeoResult
{
    public function __construct(
        public ?GeoArea $area,
        public ?int $distanceM,
        public bool $withinRadius,
        public ?int $fieldTripId = null,
    ) {}
}
