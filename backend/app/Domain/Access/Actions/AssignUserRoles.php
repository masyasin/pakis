<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Models\Role;
use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Access\Support\ScopeResolver;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Penetapan role ke akun (FR-016, BR-031). Batas delegasi (keputusan Mas 15 Nov 2026): pelaku
 * hanya bisa MENETAPKAN atau MELEPAS role yang semua permission-nya ia miliki dan scope-nya tidak
 * lebih luas dari scope terluasnya; role `owner` & `super_admin` hanya oleh Owner. Tanpa batas ini
 * HRD (`users.update`) bisa memberi role Administrator/Owner ke akun mana pun.
 *
 * Dipanggil DI DALAM transaksi CreateUser/UpdateUser; wewenang atas akun target (bukan diri
 * sendiri, Owner hanya oleh Owner, hak target ⊆ pelaku) sudah dicek pemanggil lewat UserPolicy.
 */
final class AssignUserRoles
{
    public function __construct(private readonly SecurityLogger $security) {}

    /**
     * @param  list<string>  $roleNames
     * @return array{old: list<string>, new: list<string>}
     */
    public function handle(User $actor, User $target, array $roleNames): array
    {
        $old = self::names($target->roles()->where('guard_name', 'web')->get());
        $new = array_values(array_unique($roleNames));
        sort($new);

        $changed = array_merge(array_diff($new, $old), array_diff($old, $new));
        $assignable = self::assignableNames($actor);
        $blocked = array_values(array_diff($changed, $assignable));

        if ($blocked !== []) {
            $labels = Role::query()->whereIn('name', $blocked)->pluck('label')->implode(', ');

            throw ValidationException::withMessages([
                'roles' => "Anda tidak berwenang menetapkan atau melepas role: {$labels}.",
            ]);
        }

        if ($changed !== []) {
            $target->syncRoles($new);
            $this->security->record(SecurityEventType::RoleChanged, SecurityChannel::Web, $target, $target->username, [
                'action' => 'assigned',
                'added' => array_values(array_diff($new, $old)),
                'removed' => array_values(array_diff($old, $new)),
            ]);
        }

        return ['old' => $old, 'new' => $new];
    }

    /**
     * Kode role yang boleh ditetapkan/dilepas `$actor`. Dipakai juga form untuk menonaktifkan
     * pilihan yang tidak berwenang.
     *
     * @return list<string>
     */
    public static function assignableNames(User $actor): array
    {
        $grantable = SaveRole::grantablePermissions($actor);
        $actorRank = ScopeResolver::rank(ScopeResolver::for($actor));
        $isOwner = $actor->hasRole(RoleCatalog::OWNER);

        return array_values(Role::query()
            ->where('guard_name', 'web')
            ->with('permissions:id,name')
            ->get()
            ->filter(fn (Role $role): bool => match (true) {
                in_array($role->name, [RoleCatalog::OWNER, RoleCatalog::SUPER_ADMIN], true) => $isOwner,
                ScopeResolver::rank($role->data_scope) > $actorRank => false,
                default => array_diff($role->permissions->pluck('name')->all(), $grantable) === [],
            })
            ->map(fn (Role $role): string => $role->name)
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, Model>  $roles
     * @return list<string>
     */
    private static function names(Collection $roles): array
    {
        $names = array_values($roles->map(fn (Model $r): string => (string) $r->getAttribute('name'))->all());
        sort($names);

        return $names;
    }
}
