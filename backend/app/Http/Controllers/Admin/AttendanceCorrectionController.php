<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Attendance\Actions\DecideCorrection;
use App\Domain\Attendance\Actions\RecordManualAttendance;
use App\Domain\Attendance\Listings\AttendanceCorrectionListing;
use App\Domain\Attendance\Listings\AttendanceDayListing;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Support\AttendanceDayScope;
use App\Domain\Attendance\Support\CorrectionAuthority;
use App\Domain\Attendance\Support\CorrectionPayload;
use App\Domain\Attendance\Support\SelfieStore;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ManualAttendanceRequest;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Koreksi presensi web (05 §4.10, FR-038, T-041) + presensi manual oleh admin/atasan (keputusan
 * Mas 16 Nov 2026, BR-069). Koreksi di luar yang terlihat → 404 (binding `{correction}`,
 * CorrectionAuthority::constrainVisible). Tombol Setujui/Tolak = Policy `decide` (06 §6);
 * keputusan tetap lewat DecideCorrection (kunci baris, SEC-031) yang dipakai API juga.
 */
class AttendanceCorrectionController extends Controller
{
    #[UsesListing(AttendanceCorrectionListing::class)]
    public function index(Request $request): Response
    {
        $actor = self::actor($request);
        $listing = new AttendanceCorrectionListing;
        $result = ListingQuery::apply($listing->exportQuery($actor), $listing, $request)
            ->through(fn (AttendanceCorrection $c) => [
                ...CorrectionPayload::toArray($c),
                'type_label' => $c->type->label(),
                'status_label' => $c->status->label(),
                'can_decide' => Gate::forUser($actor)->allows('decide', $c),
            ]);

        return Inertia::render('attendance-corrections/index', [
            ...$result->toInertia(),
            ...self::manualProps($actor),
            'can' => [
                'manual' => $actor->can('attendance_corrections.approve'),
                'export' => $actor->can('attendance_corrections.view'),
            ],
        ]);
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'attendance_corrections', self::actor($request));
    }

    public function show(Request $request, AttendanceCorrection $correction, AttendanceDayScope $days): Response
    {
        $actor = self::actor($request);
        $correction->load(CorrectionPayload::RELATIONS);
        $date = $correction->work_date->toDateString();
        $day = AttendanceDay::query()->with('shift:id,code,name')
            ->where('employee_id', $correction->employee_id)->where('work_date', $date)->first();

        return Inertia::render('attendance-corrections/show', [
            'correction' => [
                ...CorrectionPayload::toArray($correction),
                'type_label' => $correction->type->label(),
                'status_label' => $correction->status->label(),
            ],
            // Presensi tercatat sekarang. Setelah disetujui, jam hari = jam koreksi (log asli tetap
            // utuh di detail presensi).
            'day' => $day === null ? null : [
                'id' => $day->id,
                'shift' => $day->shift?->name,
                'check_in' => AttendanceDayListing::clock($day->check_in_at, $date),
                'check_out' => AttendanceDayListing::clock($day->check_out_at, $date),
                'status' => $day->status->value,
                'status_label' => $day->status->label(),
                'is_corrected' => $day->is_corrected,
                'visible' => $actor->can('attendance.view') && $days->allows($actor, $day),
            ],
            'can' => [
                'decide' => Gate::forUser($actor)->allows('decide', $correction),
                'own' => CorrectionAuthority::isOwn($actor, $correction->employee_id),
            ],
        ]);
    }

    public function approve(Request $request, AttendanceCorrection $correction, DecideCorrection $action): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $note = is_string($data['note'] ?? null) && trim($data['note']) !== '' ? trim($data['note']) : null;

        $this->decide($request, $correction, fn () => $action->approve(self::actor($request), $correction->id, $note), 'note');
        Inertia::flash('toast', ['type' => 'success', 'message' => "Koreksi {$correction->number} disetujui. Presensi pegawai sudah diperbarui."]);

        return back(fallback: route('admin.attendance-corrections.index'));
    }

    public function reject(Request $request, AttendanceCorrection $correction, DecideCorrection $action): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']], [
            'note.required' => 'Alasan penolakan wajib diisi.',
            'note.min' => 'Alasan penolakan minimal 5 karakter.',
        ]);

        $this->decide($request, $correction, fn () => $action->reject(self::actor($request), $correction->id, trim((string) $data['note'])), 'note');
        Inertia::flash('toast', ['type' => 'success', 'message' => "Koreksi {$correction->number} ditolak."]);

        return back(fallback: route('admin.attendance-corrections.index'));
    }

    /**
     * Lampiran bukti (05 §4.10). Selalu diunduh (`attachment`) dan tanpa cache: isinya bisa PDF/
     * gambar dari HP pegawai, jangan dirender di origin aplikasi (SEC-013).
     */
    public function attachment(AttendanceCorrection $correction): StreamedResponse
    {
        $path = $correction->attachment_path;
        abort_unless($path !== null && Storage::disk(SelfieStore::DISK)->exists($path), 404);

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return Storage::disk(SelfieStore::DISK)->download($path, "lampiran-{$correction->number}.{$extension}", [
            'Content-Type' => $extension === 'pdf' ? 'application/pdf' : ($extension === 'png' ? 'image/png' : 'image/jpeg'),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Isi presensi manual atas nama pegawai (BR-069): langsung disetujui, tercatat sebagai koreksi. */
    public function manual(ManualAttendanceRequest $request, RecordManualAttendance $action): RedirectResponse
    {
        try {
            $correction = $action->handle(self::actor($request), $request->employeeId(), $request->correctionData());
        } catch (AuthorizationException) {
            // Pegawai dalam scope tetapi bukan wewenang pengguna (mis. bawahan tidak langsung, 06 §6).
            throw ValidationException::withMessages(['employee_id' => 'Anda tidak berwenang mengisi presensi pegawai ini.']);
        } catch (ApiError $e) {
            throw self::asValidation($e, 'employee_id');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Presensi manual tersimpan ({$correction->number})."]);

        return back(fallback: route('admin.attendance-corrections.index'));
    }

    /**
     * Data form presensi manual: pilihan alasan aktif + batas tanggal (BR-069).
     *
     * @return array<string, mixed>
     */
    public static function manualProps(User $actor): array
    {
        if (! $actor->can('attendance_corrections.approve')) {
            return ['manual' => null];
        }

        $today = DisplayTime::toLocal(now());

        return ['manual' => [
            'reasons' => ReferenceItem::query()->forGroup(ReferenceGroup::CorrectionReason)->where('is_active', true)
                ->orderBy('sort_order')->get(['code', 'name'])
                ->map(fn (ReferenceItem $r) => ['value' => $r->code, 'label' => $r->name])->all(),
            'min_date' => $today->subDays((int) setting('attendance.correction_max_days'))->toDateString(),
            'max_date' => $today->toDateString(),
        ]];
    }

    /**
     * Jalankan keputusan; galat domain → 404/403 atau galat form (bukan JSON API). Koreksi yang
     * terlihat tetapi bukan wewenang pengguna (06 §6) → 403, bukan 404 dari Action.
     */
    private function decide(Request $request, AttendanceCorrection $correction, \Closure $call, string $field): void
    {
        $actor = self::actor($request);
        abort_unless(
            CorrectionAuthority::isOwn($actor, $correction->employee_id)
                || app(CorrectionAuthority::class)->mayDecideFor($actor, $correction->employee_id, $correction->approver_employee_id),
            403,
        );

        try {
            $call();
        } catch (ApiError $e) {
            throw self::asValidation($e, $field);
        }
    }

    /**
     * Terjemahan ApiError (kontrak API) ke web: NOT_FOUND → 404, milik sendiri → 403 (SEC-031),
     * sisanya galat form; `details` berbentuk `field => pesan` dipakai sebagai galat field.
     */
    private static function asValidation(ApiError $e, string $field): \Throwable
    {
        if ($e->errorCode === ErrorCode::NOT_FOUND) {
            abort(404);
        }
        if ($e->errorCode === ErrorCode::CORRECTION_SELF_APPROVAL) {
            abort(403, $e->publicMessage());
        }

        $messages = array_filter($e->details, fn ($v, $k) => is_string($v) && $k !== 'number' && $k !== 'status', ARRAY_FILTER_USE_BOTH);
        if ($e->errorCode === ErrorCode::CORRECTION_DUPLICATE_PENDING) {
            $number = is_string($e->details['number'] ?? null) ? " ({$e->details['number']})" : '';
            $messages = ['work_date' => $e->publicMessage().$number.' Putuskan pengajuan itu dulu.'];
        }

        return ValidationException::withMessages($messages === [] ? [$field => $e->publicMessage()] : $messages);
    }

    private static function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
