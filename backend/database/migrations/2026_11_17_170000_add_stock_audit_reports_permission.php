<?php

use App\Domain\Access\Support\RoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Izin `stock.audit_reports` (T-087, keputusan Mas 17 Nov 2026, PA-72): laporan pelanggaran FEFO &
 * selisih opname per penghitung. Untuk instalasi yang sudah berjalan: buat izin dan berikan HANYA ke
 * role terkunci (owner, super_admin); role lain tetap ❌ (izin baru tidak pernah otomatis jatuh ke role
 * lain, 06 §3). Instalasi baru (tabel roles kosong) diisi seeder. Idempoten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Role::query()->where('guard_name', 'web')->exists()) {
            return;
        }

        $permission = Permission::findOrCreate('stock.audit_reports', 'web');
        foreach (RoleCatalog::LOCKED as $name) {
            Role::query()->where('name', $name)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
