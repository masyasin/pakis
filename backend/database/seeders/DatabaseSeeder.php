<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Data sistem untuk semua lingkungan (04 §4.1). SuperAdminSeeder gagal bila SUPERADMIN_* di
        // .env kosong / password < 16 karakter (SEC-008): tidak ada akun default.
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            SuperAdminSeeder::class,
            SequenceSeeder::class,
            ReferenceSeeder::class,
        ]);
    }
}
