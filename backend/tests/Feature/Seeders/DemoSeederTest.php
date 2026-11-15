<?php

use App\Domain\Organization\Models\Department;
use Database\Seeders\DemoSeeder;

test('04 §4.2 data demo: 5 departemen, idempoten, tidak menimpa perubahan dari layar', function () {
    $this->seed(DemoSeeder::class);
    Department::query()->where('code', 'SDM')->update(['name' => 'SDM & Umum']);
    $this->seed(DemoSeeder::class);

    expect(Department::query()->orderBy('code')->pluck('name', 'code')->all())->toBe([
        'GDG-LOG' => 'Gudang & Logistik',
        'KEU-ADM' => 'Keuangan & Administrasi',
        'PJL' => 'Penjualan',
        'SDM' => 'SDM & Umum',
        'TOKO' => 'Toko',
    ]);
});

test('CLAUDE.md §6 seeder demo ditolak di produksi', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new DemoSeeder)->run())->toThrow(RuntimeException::class);
    expect(Department::query()->count())->toBe(0);

    app()->detectEnvironment(fn () => 'testing');
});
