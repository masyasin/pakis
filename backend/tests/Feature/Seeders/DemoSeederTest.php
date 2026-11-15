<?php

use App\Domain\Organization\Enums\AttendanceMode;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Position;
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

test('04 §4.2 data demo: 10 jabatan dengan departemen, atasan, level, dan mode absen', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $positions = Position::query()->with(['department', 'parent'])->get()->keyBy('code');

    expect($positions)->toHaveCount(10)
        ->and($positions['OWNER']->level)->toBe(1)
        ->and($positions['OWNER']->parent_position_id)->toBeNull()
        ->and($positions['SLS']->attendance_mode)->toBe(AttendanceMode::Anywhere)
        ->and($positions['SLS']->parent?->code)->toBe('SPV-SLS')
        ->and($positions['DRV']->attendance_mode)->toBe(AttendanceMode::AnyLocation)
        ->and($positions['DRV']->department?->code)->toBe('GDG-LOG')
        ->and($positions['KSR']->parent?->code)->toBe('KA-TKO');
});

test('CLAUDE.md §6 seeder demo ditolak di produksi', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new DemoSeeder)->run())->toThrow(RuntimeException::class);
    expect(Department::query()->count())->toBe(0);

    app()->detectEnvironment(fn () => 'testing');
});
