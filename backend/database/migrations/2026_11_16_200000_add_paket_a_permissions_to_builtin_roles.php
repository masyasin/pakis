<?php

use App\Domain\Access\Models\Role;
use App\Domain\Access\Support\PermissionCatalog;
use App\Domain\Access\Support\RoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * Jalur upgrade matriks Paket A untuk instalasi yang SUDAH berjalan (PA-11, PA-12, 15 §6.4, T-067).
 * RoleSeeder sengaja tidak menyentuh izin role yang sudah ada (bisa sudah diubah Administrator),
 * jadi tanpa migrasi ini sel Paket A tidak pernah sampai ke produksi.
 *
 * - Tabel roles kosong (instalasi baru, migrate:fresh) → tidak melakukan apa pun; seeder yang membuat
 *   role & izin lengkap dari RoleCatalog.
 * - Selain itu: buat 55 izin Paket A, buat 4 role baru bila belum ada (lengkap dengan izinnya), dan
 *   HANYA MENAMBAH sel Paket A ke role yang sudah ada. Tidak pernah mencabut izin, tidak mengubah
 *   label/deskripsi/scope buatan pengguna, tidak membuat ulang role Fase 0 yang sudah dihapus.
 * - `supervisor.mfa_required` dinyalakan (PA-12).
 * Idempoten: dijalankan berulang hasilnya sama. Daftar sel dibaca dari RoleCatalog (satu sumber).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Role::query()->where('guard_name', 'web')->exists()) {
            return;
        }

        foreach (PermissionCatalog::paketANames() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (RoleCatalog::roles() as $order => $definition) {
            $role = Role::query()->where('name', $definition['name'])->where('guard_name', 'web')->first();

            if ($role === null) {
                if (! in_array($definition['name'], RoleCatalog::PAKET_A_ROLES, true)) {
                    // Role Fase 0 yang dihapus pengguna tidak dihidupkan lagi.
                    continue;
                }

                $role = new Role(['name' => $definition['name'], 'guard_name' => 'web']);
                $role->forceFill([
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'data_scope' => $definition['data_scope'],
                    'mfa_required' => $definition['mfa_required'],
                    'is_system' => $definition['is_system'],
                    'sort_order' => ($order + 1) * 10,
                ])->save();
                $role->givePermissionTo(RoleCatalog::permissionsFor($definition['name']));

                continue;
            }

            $role->givePermissionTo(RoleCatalog::paketAPermissionsFor($definition['name']));

            if ($definition['name'] === 'supervisor' && ! $role->mfa_required) {
                $role->forceFill(['mfa_required' => true])->save();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Sengaja tidak membalik: izin yang sudah diberikan bisa sudah dipakai/diubah pengguna, dan
     * mencabutnya otomatis justru melanggar "tidak pernah mencabut ubahan pengguna".
     */
    public function down(): void {}
};
