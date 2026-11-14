<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Actions\DeleteRole;
use App\Domain\Access\Actions\SaveRole;
use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Listings\RoleListing;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Support\PermissionCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\RoleRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Role & Hak Akses (05 §4, FR-017). Izin dicek middleware route `can:roles.*`. */
class RoleController extends Controller
{
    #[UsesListing(RoleListing::class)]
    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $result = ListingQuery::apply(
            Role::query()->where('guard_name', 'web')->withCount(['permissions', 'users']),
            new RoleListing,
            $request,
        )->through(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->label,
            'description' => $role->description,
            'data_scope' => $role->data_scope->value,
            'data_scope_label' => $role->data_scope->label(),
            'mfa_required' => $role->mfa_required,
            'is_system' => $role->is_system,
            // Super Admin lolos semua permission lewat Gate::before; tampilkan apa adanya.
            'permissions_count' => (int) $role->getAttribute('permissions_count'),
            'users_count' => (int) $role->getAttribute('users_count'),
            'read_only' => SaveRole::readOnlyReason($actor, $role),
        ]);

        return Inertia::render('roles/index', [
            ...$result->toInertia(),
            'canManage' => $actor->can('roles.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('roles/form', [
            ...self::formOptions(),
            'role' => null,
            'readOnly' => null,
        ]);
    }

    public function store(RoleRequest $request, SaveRole $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $role = $action->handle($actor, null, $request->roleData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Role \"{$role->label}\" ditambahkan."]);

        return to_route('admin.roles.index');
    }

    public function edit(Request $request, Role $role): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        return Inertia::render('roles/form', [
            ...self::formOptions(),
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'description' => $role->description,
                'data_scope' => $role->data_scope->value,
                'mfa_required' => $role->mfa_required,
                'is_system' => $role->is_system,
                'permissions' => $role->permissions->pluck('name')->values(),
                'users_count' => $role->users()->count(),
            ],
            'readOnly' => SaveRole::readOnlyReason($actor, $role),
        ]);
    }

    public function update(RoleRequest $request, Role $role, SaveRole $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $role, $request->roleData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Role \"{$role->label}\" disimpan."]);

        return to_route('admin.roles.index');
    }

    public function destroy(Request $request, Role $role, DeleteRole $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $role);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Role \"{$role->label}\" dihapus."]);

        return to_route('admin.roles.index');
    }

    /** @return array<string, mixed> */
    private static function formOptions(): array
    {
        return [
            'modules' => PermissionCatalog::grouped(),
            'scopes' => collect(DataScope::cases())->map(fn (DataScope $s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'help' => $s->help(),
            ])->values(),
        ];
    }
}
