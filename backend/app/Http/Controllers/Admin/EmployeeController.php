<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Support\AvatarStore;
use App\Domain\Access\Support\ScopeResolver;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Employee\Actions\CreateAccountForEmployee;
use App\Domain\Employee\Actions\CreateEmployee;
use App\Domain\Employee\Actions\DeleteEmployee;
use App\Domain\Employee\Actions\MutateEmployee;
use App\Domain\Employee\Actions\RevealNik;
use App\Domain\Employee\Actions\UpdateEmployee;
use App\Domain\Employee\Enums\Gender;
use App\Domain\Employee\Listings\EmployeeListing;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\EmployeeUsage;
use App\Domain\Employee\Support\UsernameSuggester;
use App\Domain\Organization\Enums\AttendanceMode;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\EmployeeAccountRequest;
use App\Http\Requests\Employee\EmployeeRequest;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Pegawai (05 §4.6, FR-025, FR-016). Permission dasar lewat middleware route; pegawai di luar scope
 * data sudah 404 lewat route binding ber-scope, dan EmployeePolicy memeriksa ulang scope per aksi.
 *
 * Tambah pegawai & buat akun mengembalikan JSON `no-store` karena bisa memuat password sementara
 * akun baru — nilainya hanya ada di satu respons itu, tidak lewat flash/sesi (BR-014, SEC-006).
 * NIK lengkap hanya keluar dari `revealNik` (SEC-010).
 */
class EmployeeController extends Controller
{
    /** Event audit di tab Riwayat (09 §7.1). */
    private const HISTORY_EVENTS = [
        'employee.created' => 'Dibuat',
        'employee.updated' => 'Diubah',
        'employee.nik_viewed' => 'NIK dibuka',
        'employee.offboarded' => 'Dinonaktifkan',
        'employee.rehired' => 'Diaktifkan kembali',
        'assignment.created' => 'Penugasan dibuat',
        'assignment.updated' => 'Penugasan diubah',
        'assignment.ended' => 'Penugasan diakhiri',
    ];

    /**
     * Sub-tab detail pegawai (kunci URL `?tab=`). Pengelompokannya ditentukan halaman (grup Data Diri,
     * Kepegawaian, Kehadiran, …); modul riwayat kepegawaian berikutnya menambah kunci di sini.
     */
    private const TABS = ['profil', 'alamat', 'penugasan', 'jadwal', 'presensi', 'akun', 'riwayat'];

    /** Kunci lama tetap berlaku agar tautan yang sudah dibagikan tidak rusak. */
    private const TAB_ALIASES = ['data' => 'profil', 'absensi' => 'presensi'];

    #[UsesListing(EmployeeListing::class)]
    public function index(Request $request): Response
    {
        $actor = self::actor($request);
        $listing = new EmployeeListing;

        $result = ListingQuery::apply($listing->exportQuery($actor), $listing, $request)
            ->through(fn (Employee $e) => [
                'id' => $e->id,
                'employee_number' => $e->employee_number,
                'full_name' => $e->full_name,
                'position' => $e->primaryAssignment?->position?->name,
                'location' => $e->primaryAssignment?->location?->name,
                'employment_status' => $e->employmentStatus?->name,
                'phone' => $e->phone,
                'join_date' => $e->join_date->toDateString(),
                'has_account' => $e->user !== null,
                'status' => $e->status->value,
            ]);

        return Inertia::render('employees/index', [
            ...$result->toInertia(),
            'can' => [
                'create' => $actor->can('employees.create'),
                'export' => $actor->can('employees.export'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = self::actor($request);

        return Inertia::render('employees/form', [
            ...self::formOptions(null),
            'employee' => null,
            'labels' => [],
            'canCreateAccount' => $actor->can('users.create') && ScopeResolver::for($actor) === Scope::All,
            'roleOptions' => $actor->can('users.create') && ScopeResolver::for($actor) === Scope::All ? UserController::roleOptions($actor) : [],
        ]);
    }

    public function store(EmployeeRequest $request, CreateEmployee $action): JsonResponse
    {
        $photo = $request->file('photo');
        $result = $action->handle(self::actor($request), $request->employeeData(), $photo instanceof UploadedFile ? $photo : null);

        return self::noStore(response()->json([
            'id' => $result['employee']->id,
            'employee_number' => $result['employee']->employee_number,
            'full_name' => $result['employee']->full_name,
            'username' => $result['account']?->username,
            'temporary_password' => $result['temporary_password'],
            'show_url' => route('admin.employees.show', $result['employee']),
        ], 201));
    }

    public function show(Request $request, Employee $employee, EmployeeUsage $usage): Response
    {
        Gate::authorize('view', $employee);
        $actor = self::actor($request);
        $requested = $request->query('tab');
        $requested = is_string($requested) ? (self::TAB_ALIASES[$requested] ?? $requested) : null;
        $tab = in_array($requested, self::TABS, true) ? $requested : 'profil';

        $employee->load([
            'employmentStatus:id,name',
            'village.district.regency',
            'user.roles',
            'assignments' => fn ($q) => $q->with(['position.department:id,name', 'location:id,name', 'supervisor:id,full_name,employee_number'])
                ->orderByDesc('start_date')->orderByDesc('id'),
        ]);
        $primary = $employee->primaryAssignment()->with(['position.department:id,name', 'location:id,name', 'supervisor:id,full_name'])->first();
        $canDelete = Gate::forUser($actor)->allows('delete', $employee);
        // Akun terhapus tetap memegang `users.employee_id` (unik), jadi juga dihitung "sudah punya akun".
        $noAccount = ! User::withTrashed()->where('employee_id', $employee->id)->exists();

        return Inertia::render('employees/show', [
            'tab' => $tab,
            'employee' => self::detail($employee),
            'primary' => $primary !== null ? self::assignment($primary) : null,
            // `can_manage` per baris: penugasan rangkap di lokasi di luar scope pelaku tidak bisa diubah.
            'assignments' => $employee->assignments->map(fn (Assignment $a) => [
                ...self::assignment($a),
                'can_manage' => Gate::forUser($actor)->allows('update', $a),
            ])->values(),
            'account' => self::accountInfo($actor, $employee),
            'suggestedUsername' => $noAccount ? UsernameSuggester::suggest($employee->full_name) : null,
            'roleOptions' => $noAccount && $actor->can('users.create') ? UserController::roleOptions($actor) : [],
            'can' => [
                'update' => Gate::forUser($actor)->allows('update', $employee),
                'delete' => $canDelete && $usage->canDelete($employee),
                'revealNik' => Gate::forUser($actor)->allows('revealNik', $employee),
                'createAccount' => $noAccount && $employee->isActive() && Gate::forUser($actor)->allows('createAccount', $employee),
                // Mutasi / penugasan tambahan / ubah / akhiri (T-025); pegawai nonaktif tidak dimutasi.
                'manageAssignments' => $employee->isActive() && Gate::forUser($actor)->allows('manageAssignments', $employee),
            ],
            // Alasan tombol Hapus disembunyikan (BR-055), hanya untuk yang berhak menghapus.
            'deleteBlockers' => $canDelete ? $usage->blockers($employee) : [],
            'history' => $tab === 'riwayat' ? self::history($request, $employee) : null,
            'historyEvents' => collect(self::HISTORY_EVENTS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            // Pilihan dialog Mutasi/Ubah penugasan (T-025).
            'modes' => AttendanceMode::options(),
            'mutationBackdateDays' => MutateEmployee::MAX_BACKDATE_DAYS,
        ]);
    }

    public function edit(Employee $employee): Response
    {
        Gate::authorize('update', $employee);
        $employee->load(['employmentStatus:id,name', 'village.district.regency.province']);
        $village = $employee->village;

        return Inertia::render('employees/form', [
            ...self::formOptions($employee),
            'employee' => [
                ...self::detail($employee),
                'district_code' => $village?->district_code,
                'regency_code' => $village?->district?->regency_code,
                'province_code' => $village?->district?->regency?->province_code,
            ],
            // Label wilayah terpilih untuk select pencarian (tanpa ini tampil sebagai kode).
            'labels' => array_values(array_filter([
                $village?->district?->regency?->province ? ['value' => $village->district->regency->province->code, 'label' => $village->district->regency->province->name] : null,
                $village?->district?->regency ? ['value' => $village->district->regency->code, 'label' => $village->district->regency->name] : null,
                $village?->district ? ['value' => $village->district->code, 'label' => $village->district->name] : null,
                $village ? ['value' => $village->code, 'label' => $village->name] : null,
            ])),
            'canCreateAccount' => false,
            'roleOptions' => [],
        ]);
    }

    public function update(EmployeeRequest $request, Employee $employee, UpdateEmployee $action): RedirectResponse
    {
        Gate::authorize('update', $employee);
        $photo = $request->file('photo');
        $employee = $action->handle(self::actor($request), $employee, $request->employeeData(), $photo instanceof UploadedFile ? $photo : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Data {$employee->full_name} disimpan."]);

        return to_route('admin.employees.show', $employee);
    }

    public function destroy(Request $request, Employee $employee, DeleteEmployee $action): RedirectResponse
    {
        Gate::authorize('delete', $employee);
        $action->handle(self::actor($request), $employee);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pegawai {$employee->full_name} dihapus."]);

        return to_route('admin.employees.index');
    }

    /** NIK lengkap (SEC-010): JSON `no-store`, tercatat `sensitive_data_viewed`. */
    public function revealNik(Request $request, Employee $employee, RevealNik $action): JsonResponse
    {
        Gate::authorize('revealNik', $employee);

        return self::noStore(response()->json(['nik' => $action->handle(self::actor($request), $employee)]));
    }

    public function account(EmployeeAccountRequest $request, Employee $employee, CreateAccountForEmployee $action): JsonResponse
    {
        Gate::authorize('createAccount', $employee);
        $result = $action->handle(self::actor($request), $employee, $request->accountData(), field: '');

        return self::noStore(response()->json([
            'id' => $result['user']->id,
            'username' => $result['user']->username,
            'temporary_password' => $result['temporary_password'],
            'show_url' => route('admin.employees.show', ['employee' => $employee, 'tab' => 'akun']),
        ], 201));
    }

    /** Foto pegawai dari disk privat (SEC-050): hanya lewat route ber-auth & ber-scope. */
    public function photo(Employee $employee): HttpResponse
    {
        Gate::authorize('view', $employee);
        $path = $employee->photo_path;
        abort_if($path === null || ! Storage::disk(AvatarStore::DISK)->exists($path), 404);

        return Storage::disk(AvatarStore::DISK)->response($path, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'employees', self::actor($request));
    }

    /** @return array<string, mixed> */
    private static function formOptions(?Employee $employee): array
    {
        $current = $employee?->employment_status_id;

        return [
            'genders' => Gender::options(),
            'modes' => AttendanceMode::options(),
            // Status kerja aktif + yang sudah terpasang (walau kini nonaktif) agar tetap bisa dipertahankan.
            'employmentStatuses' => ReferenceItem::query()->forGroup(ReferenceGroup::EmploymentStatus)
                ->where(fn (Builder $q) => $q->where('is_active', true)->when($current !== null, fn (Builder $o) => $o->orWhere('id', $current)))
                ->orderBy('sort_order')->get(['id', 'name'])
                ->map(fn (ReferenceItem $r) => ['value' => $r->id, 'label' => $r->name])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private static function detail(Employee $employee): array
    {
        $village = $employee->village;

        return [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
            'gender' => $employee->gender->value,
            'gender_label' => $employee->gender->label(),
            // SEC-010: hanya termasking; NIK lengkap lewat revealNik.
            'nik_masked' => $employee->maskedNik(),
            'birth_date' => $employee->birth_date?->toDateString(),
            'phone' => $employee->phone,
            'email' => $employee->email,
            'address' => $employee->address,
            'village_code' => $employee->village_code,
            'village_label' => $village !== null ? implode(', ', array_filter([$village->name, $village->district?->name, $village->district?->regency?->name])) : null,
            'photo_url' => $employee->photo_path !== null
                ? route('admin.employees.photo', ['employee' => $employee->id, 'v' => substr(hash('sha256', $employee->photo_path), 0, 12)], absolute: false)
                : null,
            'employment_status_id' => $employee->employment_status_id,
            'employment_status' => $employee->employmentStatus?->name,
            'join_date' => $employee->join_date->toDateString(),
            'exit_date' => $employee->exit_date?->toDateString(),
            'status' => $employee->status->value,
            'status_label' => $employee->status->label(),
        ];
    }

    /** @return array<string, mixed> */
    private static function assignment(Assignment $a): array
    {
        return [
            'id' => $a->id,
            'position' => $a->position?->name,
            'department' => $a->position?->department?->name,
            'location' => $a->location?->name,
            'supervisor' => $a->supervisor !== null ? ['id' => $a->supervisor->id, 'name' => $a->supervisor->full_name] : null,
            'attendance_mode' => $a->attendance_mode?->value,
            'effective_mode_label' => $a->effectiveAttendanceMode()?->label(),
            'mode_from_position' => $a->attendance_mode === null,
            'note' => $a->note,
            'position_id' => $a->position_id,
            'location_id' => $a->location_id,
            'is_primary' => $a->is_primary,
            'start_date' => $a->start_date->toDateString(),
            'end_date' => $a->end_date?->toDateString(),
            'running' => $a->isRunning(DisplayTime::toLocal(now())->toDateString()),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function accountInfo(User $actor, Employee $employee): ?array
    {
        $user = $employee->user;
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'username' => $user->username,
            'is_active' => $user->is_active,
            'last_login_at' => self::iso($user->last_login_at),
            'roles' => $user->roles->map(fn ($r) => $r->getAttribute('label'))->values(),
            'show_url' => $actor->can('users.view') ? route('admin.users.show', $user, absolute: false) : null,
            // Perangkat Pakis Pro (T-031) hanya untuk pemegang `devices.view` (06 §4).
            'devices' => $actor->can('devices.view') ? DeviceController::summaries($user) : null,
        ];
    }

    /**
     * Tab Riwayat (paging 10, filter event dari allowlist): event pegawai + penugasannya.
     *
     * @return array<string, mixed>
     */
    private static function history(Request $request, Employee $employee): array
    {
        $event = $request->query('event');
        $event = is_string($event) && array_key_exists($event, self::HISTORY_EVENTS) ? $event : null;
        $assignmentIds = $employee->assignments->pluck('id')->all();

        $page = AuditLog::query()
            ->with('user:id,name')
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $e) => $e->where('auditable_type', $employee->getMorphClass())->where('auditable_id', $employee->id))
                ->when($assignmentIds !== [], fn (Builder $o) => $o->orWhere(fn (Builder $a) => $a
                    ->where('auditable_type', (new Assignment)->getMorphClass())->whereIn('auditable_id', $assignmentIds))))
            ->when($event !== null, fn (Builder $q) => $q->where('event', $event))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return [
            'event' => $event,
            'rows' => collect($page->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'occurred_at' => self::iso($log->occurred_at),
                'actor' => $log->user->name ?? $log->actor_label ?? 'sistem',
                'event' => $log->event,
                'event_label' => self::HISTORY_EVENTS[$log->event] ?? $log->event,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
            ])->values(),
            'pagination' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
            ],
        ];
    }

    private static function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /** Password sementara / NIK tidak boleh tersimpan di cache browser/proxy mana pun. */
    private static function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    private static function iso(?CarbonInterface $at): ?string
    {
        return $at === null ? null : DisplayTime::toLocal($at)->toIso8601String();
    }
}
