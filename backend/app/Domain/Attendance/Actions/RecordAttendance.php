<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\AttendanceType;
use App\Domain\Attendance\Enums\FieldTripRule;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Notifications\AttendanceMockDetected;
use App\Domain\Attendance\Support\AttendanceInput;
use App\Domain\Attendance\Support\ClockGuard;
use App\Domain\Attendance\Support\DaySnapshot;
use App\Domain\Attendance\Support\DayStatusResolver;
use App\Domain\Attendance\Support\FieldTripAreas;
use App\Domain\Attendance\Support\GeoFence;
use App\Domain\Attendance\Support\GeoResult;
use App\Domain\Attendance\Support\LocationOverseers;
use App\Domain\Attendance\Support\SelfieStore;
use App\Domain\Attendance\Support\WorkDateCandidate;
use App\Domain\Attendance\Support\WorkDateResolver;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Device\Models\Device;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Enums\AttendanceMode;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Catat satu absen dari Pakis Pro (FR-035, FR-036 server; BR-041, BR-042, BR-060, BR-063–BR-067;
 * SEC-022–SEC-024). Urutan pemeriksaan:
 *
 * 1. `client_uuid` sudah ada → kembalikan hari lama (`replayed`, BR-067); milik akun lain → 409.
 * 2. Lokasi palsu → catat Log Keamanan (koordinat 3 desimal, keputusan Mas 15 Nov 2026) +
 *    notifikasi HRD & kepala lokasi, lalu tolak. TIDAK ada log absen (BR-065).
 * 3. Waktu efektif (ClockGuard) → `work_date` & jendela shift (WorkDateResolver).
 * 4. Lokasi sesuai mode absen penugasan utama pada `work_date` (GeoFence).
 * 5. Selfie disimpan, lalu satu transaksi: log + `attendance_days` (baris dikunci, aturan masuk/
 *    pulang diperiksa ulang di bawah kunci) + status (DayStatusResolver) + audit ringkas.
 *
 * @phpstan-type Recorded array{day: AttendanceDay, log: AttendanceLog, replayed: bool}
 */
final class RecordAttendance
{
    public function __construct(
        private readonly ClockGuard $clock,
        private readonly WorkDateResolver $workDates,
        private readonly GeoFence $geo,
        private readonly SelfieStore $selfies,
        private readonly DayStatusResolver $status,
        private readonly AuditLogger $audit,
        private readonly SecurityLogger $security,
        private readonly FieldTripAreas $trips,
    ) {}

    /** @return Recorded */
    public function handle(User $user, Device $device, AttendanceInput $input): array
    {
        $replay = $this->replay($user, $input->clientUuid);
        if ($replay !== null) {
            return $replay;
        }

        $employee = $user->employee_id === null ? null : Employee::query()->find($user->employee_id);
        if ($employee === null) {
            throw new ApiError(ErrorCode::FORBIDDEN);
        }

        if ($input->isMock) {
            $this->reportMock($user, $device, $employee, $input);

            throw new ApiError(ErrorCode::ATTENDANCE_MOCK_LOCATION);
        }

        $receivedAt = CarbonImmutable::now();
        [$recordedAt, $flags] = $this->clock->resolve($input->isOffline, $input->clientTime, $input->deviceClockOffsetS, $receivedAt);
        $candidate = $this->workDates->forRecord($employee->id, $input->type, $recordedAt);

        $assignment = DaySnapshot::primaryAssignmentOn($employee->id, $candidate->workDate);
        $mode = $assignment?->effectiveAttendanceMode() ?? AttendanceMode::Location;
        // BR-071: tugas luar kota yang berlaku pada work_date menambah lokasi sah.
        $trip = $this->trips->for($employee->id, $candidate->workDate);
        $geo = $this->geo->check($input->latitude, $input->longitude, $input->accuracyM, $mode, $assignment?->location, $trip['areas'], $trip['anywhereTripId']);
        if ($geo->fieldTripId !== null) {
            // Penanda informasi (bukan anomali): absen ini sah karena tugas itu.
            $flags['field_trip_id'] = $geo->fieldTripId;
            $flags['field_trip_rule'] = match (true) {
                ! $geo->withinRadius => FieldTripRule::Anywhere->value,
                $geo->area?->locationId === null => FieldTripRule::Destination->value,
                default => FieldTripRule::AnyLocation->value,
            };
            // Titik tujuan yang dipakai (nama tempat, bukan koordinat): atasan tahu presensi di mana.
            if ($flags['field_trip_rule'] === FieldTripRule::Destination->value && $geo->area !== null) {
                $flags['field_trip_place'] = $geo->area->name;
            }
        }

        $selfiePath = $input->selfie === null
            ? null
            : $this->selfies->store($input->selfie, $recordedAt->setTimezone(DisplayTime::timezone()));

        try {
            return DB::transaction(fn () => $this->persist($user, $device, $employee, $input, $candidate, $assignment, $geo, $recordedAt, $receivedAt, $flags, $selfiePath));
        } catch (UniqueConstraintViolationException $e) {
            // Dua kiriman `client_uuid` sama bersamaan: yang kalah = kiriman ulang.
            $this->selfies->delete($selfiePath);

            return $this->replay($user, $input->clientUuid) ?? throw $e;
        } catch (Throwable $e) {
            $this->selfies->delete($selfiePath);

            throw $e;
        }
    }

    /**
     * @param  array<string, int|string>  $flags
     * @return Recorded
     */
    private function persist(
        User $user,
        Device $device,
        Employee $employee,
        AttendanceInput $input,
        WorkDateCandidate $candidate,
        ?Assignment $assignment,
        GeoResult $geo,
        CarbonImmutable $recordedAt,
        CarbonImmutable $receivedAt,
        array $flags,
        ?string $selfiePath,
    ): array {
        $day = AttendanceDay::query()
            ->where('employee_id', $employee->id)
            ->where('work_date', $candidate->workDate)
            ->lockForUpdate()
            ->first();

        // Diperiksa ulang di bawah kunci: dua absen masuk bersamaan tidak boleh sama-sama lolos.
        if ($input->type === AttendanceType::In && $day?->check_in_log_id !== null) {
            throw new ApiError(ErrorCode::ATTENDANCE_ALREADY_CHECKED_IN);
        }
        if ($input->type === AttendanceType::Out && $day?->check_in_log_id === null && ! (bool) setting('attendance.allow_checkout_without_checkin')) {
            throw new ApiError(ErrorCode::ATTENDANCE_NOT_CHECKED_IN);
        }

        $log = AttendanceLog::query()->create([
            'client_uuid' => $input->clientUuid,
            'employee_id' => $employee->id,
            'user_id' => $user->id,
            'device_id' => $device->id,
            'type' => $input->type,
            'recorded_at' => $recordedAt,
            'server_received_at' => $receivedAt,
            'client_time' => $input->clientTime->utc(),
            'is_offline' => $input->isOffline,
            'latitude' => $input->latitude,
            'longitude' => $input->longitude,
            'accuracy_m' => $input->accuracyM,
            'is_mock' => false,
            'matched_location_id' => $geo->area?->locationId,
            'distance_m' => $geo->distanceM,
            'within_radius' => $geo->withinRadius,
            'selfie_path' => $selfiePath,
            'app_version' => $input->appVersion,
            'flags' => $flags === [] ? null : $flags,
        ]);

        $day ??= new AttendanceDay([
            'employee_id' => $employee->id,
            'work_date' => $candidate->workDate,
            'status' => AttendanceStatus::Present,
        ]);

        // Snapshot jadwal & organisasi pada work_date (BR-068); tidak menimpa koreksi T-041.
        $day->shift_id = $candidate->shift?->id;
        $day->scheduled_start = $candidate->scheduledStart;
        $day->scheduled_end = $candidate->scheduledEnd;
        $day->location_id = $assignment?->location_id;
        $day->position_id = $assignment?->position_id;
        $day->department_id = $assignment?->position?->department_id;

        if ($input->type === AttendanceType::In) {
            $day->check_in_log_id = $log->id;
            $day->check_in_at = $recordedAt;
        } else {
            // BR-066: pulang berulang boleh; yang TERAKHIR (menurut waktu efektif) dipakai.
            if ($day->check_out_at === null || $recordedAt->greaterThanOrEqualTo($day->check_out_at)) {
                $day->check_out_log_id = $log->id;
                $day->check_out_at = $recordedAt;
            }
        }

        $day->is_offline = $day->is_offline || $log->is_offline;
        $day->has_anomaly = $day->has_anomaly || $log->isAnomalous();
        $day->setRelation('shift', $candidate->shift);
        $this->status->resolve($day);
        $day->save();

        // 09 §7.1: ringkas, tanpa koordinat presisi maupun foto.
        $this->audit->record('attendance.recorded', $log, null, [
            'type' => $input->type->value,
            'work_date' => $candidate->workDate,
            'is_offline' => $input->isOffline,
            'within_radius' => $geo->withinRadius,
            'matched_location_id' => $geo->area?->locationId,
            'distance_m' => $geo->distanceM,
            'flags' => $flags === [] ? null : $flags,
        ]);

        return ['day' => $day, 'log' => $log, 'replayed' => false];
    }

    /** @return Recorded|null */
    private function replay(User $user, string $clientUuid): ?array
    {
        $log = AttendanceLog::query()->where('client_uuid', $clientUuid)->first();
        if ($log === null) {
            return null;
        }

        // UUID milik akun lain: jangan bocorkan data siapa pun.
        if ($log->user_id !== $user->id) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, [], 'client_uuid sudah dipakai kiriman lain.');
        }

        $day = AttendanceDay::query()
            ->where(fn (Builder $q) => $q->where('check_in_log_id', $log->id)->orWhere('check_out_log_id', $log->id))
            ->first()
            // Pulang yang sudah digantikan pulang berikutnya: hari terakhir sampai tanggal log itu.
            ?? AttendanceDay::query()
                ->where('employee_id', $log->employee_id)
                ->where('work_date', '<=', $log->recorded_at->copy()->setTimezone(DisplayTime::timezone())->toDateString())
                ->orderByDesc('work_date')
                ->firstOrFail();

        return ['day' => $day, 'log' => $log, 'replayed' => true];
    }

    /**
     * BR-065: catat (koordinat dibulatkan 3 desimal ≈ 110 m, tanpa device_uid) & beri tahu HRD +
     * kepala lokasi penugasan utama pegawai.
     */
    private function reportMock(User $user, Device $device, Employee $employee, AttendanceInput $input): void
    {
        $this->security->record(SecurityEventType::AttendanceMockDetected, SecurityChannel::Api, $user, $user->username, [
            'employee_id' => $employee->id,
            'latitude' => round($input->latitude, 3),
            'longitude' => round($input->longitude, 3),
            'accuracy_m' => (int) round($input->accuracyM),
            'is_offline' => $input->isOffline,
            'device_id' => $device->id,
            'device_model' => $device->model,
            'type' => $input->type->value,
        ]);

        $today = DisplayTime::toLocal(now())->toDateString();
        $location = DaySnapshot::primaryAssignmentOn($employee->id, $today)?->location;
        $recipients = LocationOverseers::for($location, $user->id);

        $when = $input->clientTime->setTimezone(DisplayTime::timezone())->format('d-m-Y H:i');
        Notification::send($recipients, new AttendanceMockDetected($employee->full_name, $employee->employee_number, $when));
    }
}
