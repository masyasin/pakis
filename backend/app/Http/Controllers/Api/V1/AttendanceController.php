<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\Actions\RecordAttendance;
use App\Domain\Attendance\EffectiveSchedule;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Support\WorkDateResolver;
use App\Domain\Device\Models\Device;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreAttendanceRequest;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Api\RequestId;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Absen Pakis Pro (05 §5.4). Izin `attendance.self` + perangkat terikat dicek middleware route;
 * pegawai selalu diambil dari akun pemilik token.
 */
class AttendanceController extends Controller
{
    /** POST /api/v1/attendance → 201 baru, 200 + `meta.replayed` bila `client_uuid` sudah ada. */
    public function store(StoreAttendanceRequest $request, RecordAttendance $action): JsonResponse
    {
        $result = $action->handle(self::user($request), self::device($request), $request->toInput());

        $result['day']->loadMissing(['shift', 'checkInLog.matchedLocation', 'checkOutLog.matchedLocation']);

        return response()->json([
            'data' => self::day($result['day'], $result['log']),
            'meta' => ['request_id' => RequestId::current(), 'replayed' => $result['replayed']],
        ], $result['replayed'] ? 200 : 201);
    }

    /**
     * GET /api/v1/attendance/today: jadwal hari kerja sekarang, absennya (bila ada), dan aksi
     * berikutnya untuk tombol beranda (T-042). Shift malam yang belum pulang tetap "hari ini".
     */
    public function today(Request $request, WorkDateResolver $workDates): JsonResponse
    {
        $user = self::user($request);
        if ($user->employee_id === null) {
            throw new ApiError(ErrorCode::FORBIDDEN);
        }

        $current = $workDates->current($user->employee_id, CarbonImmutable::now());
        $day = $current['day'];
        $day?->loadMissing(['shift', 'checkInLog.matchedLocation', 'checkOutLog.matchedLocation']);

        return response()->json([
            'data' => [
                'work_date' => $current['work_date'],
                'schedule' => self::schedule($current['schedule']),
                'attendance' => $day === null ? null : self::day($day, $day->checkOutLog ?? $day->checkInLog),
                'next_action' => $current['next_action'],
            ],
            'meta' => ['request_id' => RequestId::current()],
        ]);
    }

    /**
     * Bentuk `data` 05 §5.4. Waktu dalam zona tampilan (`general.timezone`) dengan offset.
     *
     * @return array<string, mixed>
     */
    private static function day(AttendanceDay $day, ?AttendanceLog $log): array
    {
        $location = $log?->matchedLocation;
        $locationName = $location?->name;
        $tripId = $log?->flags['field_trip_id'] ?? null;
        if ($locationName === null && is_int($tripId)) {
            // Titik tujuan tugas luar kota bukan lokasi Pakis (BR-071): tampilkan tugasnya.
            $trip = FieldTrip::query()->find($tripId, ['id', 'number', 'destination']);
            $place = $log?->flags['field_trip_place'] ?? null;
            $locationName = $trip === null ? null : "Tugas {$trip->number}: ".(is_string($place) ? $place : $trip->destination);
        }

        return [
            'work_date' => $day->work_date->toDateString(),
            'status' => $day->status->value,
            'late_minutes' => $day->late_minutes,
            'shift' => self::shift($day->shift),
            'check_in_at' => self::time($day->check_in_at),
            'check_out_at' => self::time($day->check_out_at),
            'location' => $log === null ? null : [
                'name' => $locationName,
                'distance_m' => $log->distance_m,
            ],
            'is_offline' => $day->is_offline,
        ];
    }

    /** @return array<string, mixed> */
    private static function schedule(EffectiveSchedule $schedule): array
    {
        return [
            'kind' => $schedule->kind,
            'shift' => self::shift($schedule->shift),
            'holiday' => $schedule->holidayName,
        ];
    }

    /** @return array{code: string, name: string, start: string, end: string}|null */
    private static function shift(?Shift $shift): ?array
    {
        return $shift === null ? null : [
            'code' => $shift->code,
            'name' => $shift->name,
            'start' => Shift::hm($shift->start_time),
            'end' => Shift::hm($shift->end_time),
        ];
    }

    private static function time(?CarbonInterface $moment): ?string
    {
        return $moment === null ? null : CarbonImmutable::instance($moment)->setTimezone(DisplayTime::timezone())->toIso8601String();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ApiError(ErrorCode::UNAUTHENTICATED);
        }

        return $user;
    }

    private static function device(Request $request): Device
    {
        $device = $request->attributes->get('device');
        if (! $device instanceof Device) {
            // EnsureDeviceBound selalu mengisi ini untuk route ber-token; tanpa itu = tidak terikat.
            throw new ApiError(ErrorCode::DEVICE_MISMATCH);
        }

        return $device;
    }
}
