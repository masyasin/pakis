<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Models\Role;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hapus role (BR-031): tidak untuk role sistem, role terkunci, role yang dipegang sendiri, atau
 * role yang masih dipegang pengguna mana pun (pengguna akan kehilangan akses diam-diam).
 */
final class DeleteRole
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecurityLogger $security,
    ) {}

    public function handle(User $actor, Role $role): void
    {
        SaveRole::assertEditable($actor, $role);

        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => "Role sistem \"{$role->label}\" tidak bisa dihapus."]);
        }

        DB::transaction(function () use ($role) {
            // Kunci baris role agar penetapan role ke pengguna yang berjalan bersamaan tidak lolos.
            Role::query()->whereKey($role->id)->lockForUpdate()->first();
            $holders = DB::table(config('permission.table_names.model_has_roles'))->where('role_id', $role->id)->count();

            if ($holders > 0) {
                throw ValidationException::withMessages(['role' => "Role \"{$role->label}\" masih dipegang {$holders} pengguna. Lepaskan dulu dari pengguna tersebut."]);
            }

            $before = ['name' => $role->name, 'label' => $role->label, 'permissions' => $role->permissions->pluck('name')->sort()->values()->all()];
            $role->delete();

            $this->audit->record('role.deleted', $role, $before, null);
            $this->security->record(SecurityEventType::RoleChanged, SecurityChannel::Web, null, null, [
                'role' => $before['name'],
                'action' => 'deleted',
            ]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
