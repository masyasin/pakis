<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Enums\AttendanceType;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/** Kiriman absen yang sudah lolos validasi bentuk (StoreAttendanceRequest, 05 §5.4). */
final readonly class AttendanceInput
{
    public function __construct(
        public string $clientUuid,
        public AttendanceType $type,
        public CarbonImmutable $clientTime,
        public bool $isOffline,
        public float $latitude,
        public float $longitude,
        public float $accuracyM,
        public bool $isMock,
        public ?int $deviceClockOffsetS,
        public ?UploadedFile $selfie,
        public ?string $appVersion,
    ) {}
}
