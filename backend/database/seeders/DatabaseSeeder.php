<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Data sistem: dibutuhkan di semua lingkungan.
        $this->call(SequenceSeeder::class);

        // Data contoh hanya untuk mesin developer (CLAUDE.md §6: tidak ada data demo di produksi).
        if (app()->isLocal()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}
