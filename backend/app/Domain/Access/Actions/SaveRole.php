<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Buat / ubah role (FR-017, BR-030, BR-031). Data sudah divalidasi RoleRequest (format kode,
 * label, permission ⊂ katalog). Di sini aturan yang bergantung pada keadaan:
 * - `owner`/`super_admin` terkunci penuh;
 * - pengguna tidak bisa mengubah role yang sedang ia pegang (cegah menaikkan hak sendiri);
 * - kode role sistem tidak bisa diganti.
 * Setiap perubahan dicatat audit + security event, lalu cache permission Spatie dibersihkan
 * agar berlaku pada request berikutnya.
 */
final class SaveRole
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecurityLogger $security,
    ) {}

    /**
     * @param  array{name: string, label: string, description: string|null, data_scope: string, mfa_required: bool, permissions: list<string>}  $data
     */
    public function handle(User $actor, ?Role $role, array $data): Role
    {
        if ($role !== null) {
            self::assertEditable($actor, $role);

            if ($role->is_system && $data['name'] !== $role->name) {
                throw ValidationException::withMessages(['name' => 'Kode role sistem tidak bisa diganti.']);
            }
        }

        $saved = DB::transaction(function () use ($role, $data) {
            $isNew = $role === null;
            $role ??= new Role(['guard_name' => 'web']);

            $before = $isNew ? null : self::snapshot($role);
            $oldPermissions = $isNew ? [] : $role->permissions->pluck('name')->all();

            $role->forceFill([
                'name' => $data['name'],
                'label' => $data['label'],
                'description' => $data['description'],
                'data_scope' => DataScope::from($data['data_scope']),
                'mfa_required' => $data['mfa_required'],
                'sort_order' => $isNew ? ((int) Role::query()->max('sort_order')) + 10 : $role->sort_order,
            ])->save();

            $role->syncPermissions($data['permissions']);

            $after = self::snapshot($role);
            $this->audit->record($isNew ? 'role.created' : 'role.updated', $role, $before, $after);
            $this->security->record(SecurityEventType::RoleChanged, SecurityChannel::Web, null, null, [
                'role' => $role->name,
                'action' => $isNew ? 'created' : 'updated',
            ]);

            $added = array_values(array_diff($data['permissions'], $oldPermissions));
            $removed = array_values(array_diff($oldPermissions, $data['permissions']));
            sort($added);
            sort($removed);
            if ($added !== [] || $removed !== []) {
                $this->security->record(SecurityEventType::PermissionChanged, SecurityChannel::Web, null, null, [
                    'role' => $role->name,
                    'added' => $added,
                    'removed' => $removed,
                ]);
            }

            return $role;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $saved;
    }

    /**
     * Alasan role tidak bisa diubah oleh `$actor`, atau null bila boleh. Dipakai juga form (baca-saja).
     *
     * @return 'locked'|'own'|null
     */
    public static function readOnlyReason(User $actor, Role $role): ?string
    {
        return match (true) {
            $role->isLocked() => 'locked',
            $actor->hasRole($role->name) => 'own',
            default => null,
        };
    }

    public static function assertEditable(User $actor, Role $role): void
    {
        $reason = self::readOnlyReason($actor, $role);

        if ($reason !== null) {
            throw new AuthorizationException($reason === 'locked'
                ? 'Role ini terkunci dan tidak bisa diubah.'
                : 'Anda tidak bisa mengubah role yang sedang Anda pegang.');
        }
    }

    /** @return array<string, mixed> */
    private static function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'label' => $role->label,
            'description' => $role->description,
            'data_scope' => $role->data_scope->value,
            'mfa_required' => $role->mfa_required,
            'permissions' => $role->permissions()->pluck('name')->sort()->values()->all(),
        ];
    }
}
