<?php

namespace Database\Seeders;

use App\Domain\Access\Models\Role;
use App\Domain\Access\Support\RoleCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role bawaan + matriks default (06 §2, §4). Idempoten untuk ROLE BARU saja: role yang sudah ada
 * tidak ditimpa permission-nya, karena matriks bisa sudah diubah Administrator lewat layar.
 * Pengecualian `owner`/`super_admin`: terkunci penuh (BR-031), jadi selalu disamakan ulang.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleCatalog::roles() as $order => $definition) {
            $role = Role::query()->where('name', $definition['name'])->where('guard_name', 'web')->first();
            $isNew = $role === null;

            $role ??= new Role(['name' => $definition['name'], 'guard_name' => 'web']);
            $role->forceFill([
                'label' => $isNew ? $definition['label'] : $role->label,
                'description' => $isNew ? $definition['description'] : $role->description,
                'data_scope' => $isNew || $role->isLocked() ? $definition['data_scope'] : $role->data_scope,
                'mfa_required' => $isNew || $role->isLocked() ? $definition['mfa_required'] : $role->mfa_required,
                'is_system' => $definition['is_system'],
                'sort_order' => ($order + 1) * 10,
            ])->save();

            if ($isNew || $role->isLocked()) {
                $role->syncPermissions(RoleCatalog::permissionsFor($definition['name']));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
