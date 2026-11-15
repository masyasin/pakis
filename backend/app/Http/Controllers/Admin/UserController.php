<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Actions\AdminResetPassword;
use App\Domain\Access\Actions\AssignUserRoles;
use App\Domain\Access\Actions\CreateUser;
use App\Domain\Access\Actions\ListSessions;
use App\Domain\Access\Actions\ResetMfa;
use App\Domain\Access\Actions\RevokeUserSessions;
use App\Domain\Access\Actions\ToggleUser;
use App\Domain\Access\Actions\UpdateUser;
use App\Domain\Access\Listings\UserListing;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Policies\UserPolicy;
use App\Domain\Access\Support\MfaRequirement;
use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Audit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\AdminResetPasswordRequest;
use App\Http\Requests\Access\UserRequest;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Manajemen pengguna (05 §4.4, FR-007, FR-016). Permission dasar dicek middleware route
 * (`can:users.*`); wewenang atas akun TERTENTU (bukan diri sendiri, Owner hanya oleh Owner,
 * hak target ⊆ pelaku) lewat ability UserPolicy; akun di luar scope data → 404 (route binding).
 *
 * Password sementara (buat akun & reset) dikembalikan sebagai JSON `no-store`, BUKAN flash/sesi:
 * nilainya hanya ada di satu respons itu (BR-014, SEC-006).
 */
class UserController extends Controller
{
    /** Event audit yang ditampilkan di tab Riwayat (09 §7.1 `user.*`). */
    private const HISTORY_EVENTS = [
        'user.created' => 'Dibuat',
        'user.updated' => 'Diubah',
        'user.deactivated' => 'Dinonaktifkan',
        'user.activated' => 'Diaktifkan',
        'user.password_reset' => 'Password direset',
        'user.mfa_reset' => 'MFA direset',
    ];

    private const TABS = ['profil', 'role', 'sesi', 'perangkat', 'riwayat'];

    #[UsesListing(UserListing::class)]
    public function index(Request $request): Response
    {
        $actor = self::actor($request);
        $listing = new UserListing;

        $result = ListingQuery::apply($listing->exportQuery($actor), $listing, $request)
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->roles->map(fn (Model $r) => ['name' => $r->getAttribute('name'), 'label' => $r->getAttribute('label')])->values(),
                'employee_id' => $user->employee_id,
                'is_active' => $user->is_active,
                'mfa_enabled' => $user->two_factor_confirmed_at !== null,
                'last_login_at' => self::iso($user->last_login_at),
            ]);

        return Inertia::render('users/index', [
            ...$result->toInertia(),
            'canCreate' => $actor->can('users.create'),
            'canExport' => $actor->can('users.export'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('users/form', [
            'user' => null,
            'roleOptions' => self::roleOptions(self::actor($request)),
            'canAssignRoles' => true,
        ]);
    }

    public function store(UserRequest $request, CreateUser $action): JsonResponse
    {
        $result = $action->handle(self::actor($request), [
            ...$request->accountData(),
            'is_active' => $request->boolean('is_active'),
            'password_mode' => $request->validated('password_mode') === 'email_link' ? 'email_link' : 'generate',
        ]);

        return self::noStore(response()->json([
            'id' => $result['user']->id,
            'username' => $result['user']->username,
            'temporary_password' => $result['temporary_password'],
            'show_url' => route('admin.users.show', $result['user']),
        ], 201));
    }

    public function show(Request $request, User $user, ListSessions $sessions): Response
    {
        $actor = self::actor($request);
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'profil';

        return Inertia::render('users/show', [
            'user' => self::detail($user),
            'tab' => $tab,
            'can' => self::abilities($actor, $user),
            'protectedReason' => self::protectedReason($actor, $user),
            // Sesi milik akun ini; tidak ada yang "sesi saat ini" bagi admin yang melihat.
            'sessions' => $tab === 'sesi' ? $sessions->handle($user, '') : [],
            // TODO(T-031): perangkat Pakis Pro milik akun (tabel `devices`).
            'devices' => [],
            'history' => $tab === 'riwayat' ? self::history($request, $user) : null,
            'historyEvents' => collect(self::HISTORY_EVENTS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function edit(Request $request, User $user): Response
    {
        $actor = self::actor($request);
        Gate::authorize('users.manage-account', $user);

        return Inertia::render('users/form', [
            'user' => self::detail($user),
            'roleOptions' => self::roleOptions($actor),
            'canAssignRoles' => Gate::allows('users.assign-roles', $user),
        ]);
    }

    public function update(UserRequest $request, User $user, UpdateUser $action): RedirectResponse
    {
        Gate::authorize('users.manage-account', $user);
        Gate::authorize('users.assign-roles', $user);

        $action->handle(self::actor($request), $user, $request->accountData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Akun {$user->username} disimpan."]);

        return to_route('admin.users.show', $user);
    }

    public function toggle(Request $request, User $user, ToggleUser $action): RedirectResponse
    {
        Gate::authorize('users.manage-account', $user);
        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];

        $action->handle(self::actor($request), $user, $active);

        Inertia::flash('toast', ['type' => 'success', 'message' => $active
            ? "Akun {$user->username} diaktifkan."
            : "Akun {$user->username} dinonaktifkan dan semua sesinya dicabut."]);

        return back();
    }

    public function resetPassword(AdminResetPasswordRequest $request, User $user, AdminResetPassword $action): JsonResponse
    {
        Gate::authorize('users.reset-password-of', $user);

        return self::noStore(response()->json([
            'temporary_password' => $action->handle(self::actor($request), $user),
        ]));
    }

    public function resetMfa(Request $request, User $user, ResetMfa $action): RedirectResponse
    {
        // ResetMfa juga memeriksa ability yang sama; di sini agar pola controller seragam.
        Gate::authorize('users.reset-mfa-of', $user);
        $action->handle(self::actor($request), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => "MFA {$user->username} direset. Ia akan diminta mengaktifkan ulang saat login bila role-nya mewajibkan."]);

        return back();
    }

    public function revokeSessions(Request $request, User $user, RevokeUserSessions $action): RedirectResponse
    {
        Gate::authorize('users.manage-account', $user);
        $count = $action->handle($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => $count > 0
            ? "{$count} sesi {$user->username} dicabut."
            : "Tidak ada sesi aktif milik {$user->username}."]);

        return back();
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'users', self::actor($request));
    }

    private static function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }

    /** Password sementara tidak boleh tersimpan di cache browser/proxy mana pun. */
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

    /** @return array<string, mixed> */
    private static function detail(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'phone' => $user->phone,
            'employee_id' => $user->employee_id,
            'is_active' => $user->is_active,
            'is_locked' => $user->isLocked(),
            'must_change_password' => $user->must_change_password,
            'mfa_enabled' => $user->two_factor_confirmed_at !== null,
            'mfa_required' => MfaRequirement::requires($user),
            'last_login_at' => self::iso($user->last_login_at),
            'last_login_ip' => $user->last_login_ip,
            'created_at' => self::iso($user->created_at),
            'roles' => $user->roles()->where('guard_name', 'web')->get()->map(fn (Model $r) => [
                'name' => $r->getAttribute('name'),
                'label' => $r->getAttribute('label'),
                'data_scope_label' => $r instanceof Role ? $r->data_scope->label() : null,
                'mfa_required' => $r instanceof Role && $r->mfa_required,
            ])->values(),
        ];
    }

    /** @return array<string, bool> */
    private static function abilities(User $actor, User $user): array
    {
        $manage = Gate::forUser($actor)->allows('users.manage-account', $user);

        return [
            'update' => $manage && $actor->can('users.update'),
            'toggle' => $manage && $actor->can('users.deactivate'),
            'revokeSessions' => $manage && $actor->can('users.update'),
            'resetPassword' => Gate::forUser($actor)->allows('users.reset-password-of', $user),
            'resetMfa' => Gate::forUser($actor)->allows('users.reset-mfa-of', $user),
        ];
    }

    /**
     * Mengapa akun ini tidak bisa diubah pelaku (untuk keterangan di halaman), atau null.
     *
     * @return 'self'|'owner'|'beyond'|null
     */
    private static function protectedReason(User $actor, User $user): ?string
    {
        return match (true) {
            $actor->is($user) => 'self',
            $user->hasRole(RoleCatalog::OWNER) && ! $actor->hasRole(RoleCatalog::OWNER) => 'owner',
            ! UserPolicy::mayTouch($actor, $user) => 'beyond',
            default => null,
        };
    }

    /**
     * Pilihan role di form. `assignable` = boleh ditetapkan/dilepas pelaku (batas delegasi BR-031).
     *
     * @return list<array{name: string, label: string, description: string|null, data_scope_label: string, assignable: bool}>
     */
    private static function roleOptions(User $actor): array
    {
        $assignable = AssignUserRoles::assignableNames($actor);

        return array_values(Role::query()->where('guard_name', 'web')->orderBy('sort_order')->get()
            ->map(fn (Role $role) => [
                'name' => $role->name,
                'label' => $role->label,
                'description' => $role->description,
                'data_scope_label' => $role->data_scope->label(),
                'assignable' => in_array($role->name, $assignable, true),
            ])->all());
    }

    /**
     * Tab Riwayat (07 §3.4 catatan: paging 10, filter event). Event dari allowlist, bukan input mentah.
     *
     * @return array<string, mixed>
     */
    private static function history(Request $request, User $user): array
    {
        $event = $request->query('event');
        $event = is_string($event) && array_key_exists($event, self::HISTORY_EVENTS) ? $event : null;

        $page = AuditLog::query()
            ->with('user:id,name')
            ->where('auditable_type', $user->getMorphClass())
            ->where('auditable_id', $user->id)
            ->when($event !== null, fn ($q) => $q->where('event', $event))
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
}
