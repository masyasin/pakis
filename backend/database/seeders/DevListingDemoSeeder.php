<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 60 pengguna DUMMY untuk mencoba /dev/listing-demo. Hanya untuk environment local.
 *
 *     php artisan db:seed --class=DevListingDemoSeeder
 *
 * Semua akun berpassword `password` (factory). Jangan pernah dijalankan di staging/produksi.
 */
class DevListingDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isLocal()) {
            throw new RuntimeException('DevListingDemoSeeder hanya untuk APP_ENV=local.');
        }

        $faker = fake('id_ID');
        $domains = ['pakis-demo.test', 'gudang-demo.test', 'toko-demo.test'];

        User::factory()->create([
            'name' => 'Demo Admin',
            'email' => 'demo@pakis-demo.test',
        ]);

        foreach (range(1, 59) as $i) {
            User::factory()
                ->state([
                    'name' => $faker->name(),
                    'email' => sprintf('demo%02d@%s', $i, $domains[$i % 3]),
                    'email_verified_at' => $i % 5 === 0 ? null : now(),
                    'two_factor_confirmed_at' => $i % 4 === 0 ? now() : null,
                    'created_at' => now()->subDays($i * 3),
                ])
                ->create();
        }
    }
}
