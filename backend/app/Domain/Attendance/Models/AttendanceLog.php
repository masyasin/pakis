<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\AttendanceType;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kiriman absen yang DITERIMA (04 §2.13). Append-only: tidak pernah diubah setelah dibuat,
 * kecuali purge selfie oleh job retensi (T-045). Lokasi palsu tidak pernah tersimpan (BR-065).
 * Koordinat presisi hanya di sini (akses `attendance.view_photo`/detail, T-038), tidak di log/audit.
 *
 * @property int $id
 * @property string $client_uuid
 * @property int $employee_id
 * @property int $user_id
 * @property int $device_id
 * @property AttendanceType $type
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable $server_received_at
 * @property CarbonImmutable $client_time
 * @property bool $is_offline
 * @property string $latitude
 * @property string $longitude
 * @property string|null $accuracy_m
 * @property bool $is_mock
 * @property int|null $matched_location_id
 * @property int|null $distance_m
 * @property bool $within_radius
 * @property string|null $selfie_path
 * @property CarbonImmutable|null $selfie_purged_at
 * @property string|null $app_version
 * @property array<string, mixed>|null $flags
 * @property CarbonImmutable|null $created_at
 * @property-read Location|null $matchedLocation
 * @property-read Employee|null $employee
 */
class AttendanceLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => AttendanceType::class,
            'recorded_at' => 'datetime',
            'server_received_at' => 'datetime',
            'client_time' => 'datetime',
            'is_offline' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_m' => 'decimal:2',
            'is_mock' => 'boolean',
            'within_radius' => 'boolean',
            'selfie_purged_at' => 'datetime',
            'flags' => 'array',
            'employee_id' => 'integer',
            'user_id' => 'integer',
            'device_id' => 'integer',
            'matched_location_id' => 'integer',
            'distance_m' => 'integer',
        ];
    }

    /** Ada penanda anomali (BR-068 `has_anomaly`): flags terisi atau di luar radius (mode anywhere). */
    public function isAnomalous(): bool
    {
        return ($this->flags !== null && $this->flags !== []) || ! $this->within_radius;
    }

    /** @return BelongsTo<Location, $this> */
    public function matchedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'matched_location_id')->withTrashed();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
