<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Attendance\Enums\FieldTripRule;
use App\Domain\Attendance\Listings\AttendanceDayListing;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Support\AttendanceDayScope;
use App\Domain\Attendance\Support\SelfieStore;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Absensi harian web (05 §4.10, FR-039, T-038). Permission dasar lewat middleware route; baris di
 * luar scope data → 404 (binding `{day}` lewat AttendanceDayScope, SEC-033).
 *
 * Koordinat presisi, diagram lokasi, dan selfie hanya untuk `attendance.view_photo` (keputusan Mas
 * 15 Nov 2026): pemegang `attendance.view` saja melihat jarak, lokasi cocok, dan akurasi.
 */
class AttendanceController extends Controller
{
    /** Umur URL bertanda tangan untuk selfie (SEC-013). */
    private const SELFIE_URL_MINUTES = 5;

    #[UsesListing(AttendanceDayListing::class)]
    public function index(Request $request): Response
    {
        $actor = self::actor($request);
        $listing = new AttendanceDayListing;
        $result = ListingQuery::apply($listing->exportQuery($actor), $listing, $request)
            ->through(fn (AttendanceDay $d) => [
                'id' => $d->id,
                'work_date' => $d->work_date->toDateString(),
                'employee' => ['id' => $d->employee_id, 'name' => $d->employee?->full_name, 'number' => $d->employee?->employee_number],
                'location' => $d->location?->name,
                'shift' => $d->shift === null ? null : ['code' => $d->shift->code, 'name' => $d->shift->name],
                'check_in' => AttendanceDayListing::clock($d->check_in_at, $d->work_date->toDateString()),
                'check_out' => AttendanceDayListing::clock($d->check_out_at, $d->work_date->toDateString()),
                'late_minutes' => $d->late_minutes,
                'status' => $d->status->value,
                'status_label' => $d->status->label(),
                'is_offline' => $d->is_offline,
                'is_corrected' => $d->is_corrected,
                'has_anomaly' => $d->has_anomaly,
            ]);

        return Inertia::render('attendance/index', [
            ...$result->toInertia(),
            'can' => ['export' => $actor->can('attendance.export')],
        ]);
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'attendance_days', self::actor($request));
    }

    public function show(Request $request, AttendanceDay $day): Response
    {
        $actor = self::actor($request);
        $canPhoto = $actor->can('attendance.view_photo');
        $day->load([
            'employee:id,full_name,employee_number',
            'location:id,name',
            'position:id,name',
            'department:id,name',
            'shift',
            'checkInLog.matchedLocation',
            'checkOutLog.matchedLocation',
        ]);
        $date = $day->work_date->toDateString();
        $shift = $day->shift;

        return Inertia::render('attendance/show', [
            'day' => [
                'id' => $day->id,
                'work_date' => $date,
                'employee' => ['id' => $day->employee_id, 'name' => $day->employee?->full_name, 'number' => $day->employee?->employee_number],
                'location' => $day->location?->name,
                'position' => $day->position?->name,
                'department' => $day->department?->name,
                'shift' => $shift === null ? null : [
                    'code' => $shift->code,
                    'name' => $shift->name,
                    'start' => AttendanceDayListing::clock($day->scheduled_start, $date),
                    'end' => AttendanceDayListing::clock($day->scheduled_end, $date),
                    'late_tolerance_min' => $shift->late_tolerance_min,
                    'checkout_close' => AttendanceDayListing::clock($day->scheduled_end?->addMinutes($shift->checkout_close_min), $date),
                ],
                'status' => $day->status->value,
                'status_label' => $day->status->label(),
                'late_minutes' => $day->late_minutes,
                'early_leave_minutes' => $day->early_leave_minutes,
                'work_minutes' => $day->work_minutes,
                'is_offline' => $day->is_offline,
                'is_corrected' => $day->is_corrected,
                'has_anomaly' => $day->has_anomaly,
                'resolved_at' => self::localTime($day->resolved_at),
            ],
            'logs' => [
                'in' => self::log($day->checkInLog, $canPhoto),
                'out' => self::log($day->checkOutLog, $canPhoto),
            ],
            // Koreksi (riwayat) datang di T-040/T-041.
            'corrections' => [],
            'can' => [
                'view_photo' => $canPhoto,
                'view_employee' => $actor->can('employees.view'),
            ],
        ]);
    }

    /**
     * Selfie lewat URL bertanda tangan 5 menit (SEC-013). Log harus dirujuk baris absensi yang
     * TERLIHAT oleh pengguna (scope snapshot, SEC-033); selain itu, atau foto sudah dihapus retensi → 404.
     */
    public function selfie(Request $request, AttendanceLog $log, AttendanceDayScope $scope): StreamedResponse
    {
        $actor = self::actor($request);

        $visible = $scope->apply(AttendanceDay::query(), $actor)
            ->where(fn (Builder $q) => $q->where('check_in_log_id', $log->id)->orWhere('check_out_log_id', $log->id))
            ->exists();
        $path = $log->selfie_path;
        abort_unless($visible && $path !== null && Storage::disk(SelfieStore::DISK)->exists($path), 404);

        return Storage::disk(SelfieStore::DISK)->response($path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed>|null */
    private static function log(?AttendanceLog $log, bool $canPhoto): ?array
    {
        if ($log === null) {
            return null;
        }

        $location = $log->matchedLocation;
        $data = [
            'id' => $log->id,
            'type' => $log->type->value,
            'recorded_at' => self::localTime($log->recorded_at),
            'server_received_at' => self::localTime($log->server_received_at),
            'client_time' => self::localTime($log->client_time),
            'is_offline' => $log->is_offline,
            'accuracy_m' => $log->accuracy_m === null ? null : (int) round((float) $log->accuracy_m),
            'distance_m' => $log->distance_m,
            'within_radius' => $log->within_radius,
            'location' => $location?->name,
            'notes' => self::notes($log->flags ?? []),
            'app_version' => $log->app_version,
            'has_selfie' => $log->selfie_path !== null,
            'selfie_url' => null,
            'map' => null,
        ];

        if (! $canPhoto) {
            return $data;
        }

        if ($log->selfie_path !== null) {
            $data['selfie_url'] = URL::temporarySignedRoute('admin.attendance.selfie', now()->addMinutes(self::SELFIE_URL_MINUTES), ['log' => $log->id]);
        }

        $data['map'] = [
            'point' => ['lat' => (float) $log->latitude, 'lng' => (float) $log->longitude],
            'area' => $location === null || $location->latitude === null || $location->longitude === null ? null : [
                'lat' => (float) $location->latitude,
                'lng' => (float) $location->longitude,
                'radius_m' => (int) ($location->attendance_radius_m ?? setting('attendance.default_radius_m')),
                'name' => $location->name,
            ],
        ];

        return $data;
    }

    /**
     * Penanda log dalam bahasa manusia. Penanda tugas luar kota (`AttendanceLog::INFO_FLAGS`, BR-071)
     * hanya keterangan, bukan anomali; sisanya peringatan.
     *
     * @param  array<string, mixed>  $flags
     * @return list<array{text: string, tone: 'info'|'warning'}>
     */
    private static function notes(array $flags): array
    {
        $notes = [];

        if (isset($flags['field_trip_id'])) {
            $rule = is_string($flags['field_trip_rule'] ?? null) ? FieldTripRule::tryFrom($flags['field_trip_rule']) : null;
            $place = is_string($flags['field_trip_place'] ?? null) ? $flags['field_trip_place'] : null;
            $notes[] = [
                'text' => 'Tugas luar kota'.($rule !== null ? ': '.mb_strtolower($rule->label()) : '').($place !== null ? " ({$place})" : ''),
                'tone' => 'info',
            ];
        }

        foreach (array_diff_key($flags, array_flip(AttendanceLog::INFO_FLAGS)) as $key => $value) {
            $notes[] = [
                'text' => match ($key) {
                    'clock_skew_s' => 'Jam HP berselisih '.max(1, (int) round(abs((int) $value) / 60)).' menit dari server',
                    default => 'Penanda: '.$key,
                },
                'tone' => 'warning',
            ];
        }

        return $notes;
    }

    private static function localTime(?CarbonInterface $at): ?string
    {
        return $at === null ? null : DisplayTime::toLocal($at)->format('Y-m-d H:i:s');
    }

    private static function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
