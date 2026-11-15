<?php

namespace App\Domain\Attendance\Support;

/** Hasil GeoFence untuk disimpan di log: area cocok/terdekat, jarak (meter bulat), dalam radius. */
final readonly class GeoResult
{
    public function __construct(
        public ?GeoArea $area,
        public ?int $distanceM,
        public bool $withinRadius,
    ) {}
}
