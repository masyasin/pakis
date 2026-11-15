<?php

use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Enums\AttendanceMode;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

test('04 §4.2 data demo: 3 lokasi, 15 pegawai fiktif dengan penugasan, kepala lokasi, dan akun', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(Location::query()->orderBy('code')->pluck('code')->all())->toBe(['GDG-01', 'KTR-01', 'TKO-01'])
        ->and(Employee::query()->count())->toBe(15)
        ->and(Assignment::query()->where('is_primary', true)->whereNull('end_date')->count())->toBe(15)
        ->and(User::query()->whereNotNull('employee_id')->count())->toBe(15);

    $joko = Employee::query()->where('full_name', 'Joko Susilo')->sole();
    $wahyu = Employee::query()->with('primaryAssignment.position')->where('full_name', 'Wahyu Hidayat')->sole();
    expect(Location::query()->where('code', 'GDG-01')->value('head_employee_id'))->toBe($joko->id)
        ->and($wahyu->primaryAssignment?->supervisor_employee_id)->toBe($joko->id)
        ->and($wahyu->primaryAssignment?->position?->code)->toBe('STF-GDG');

    // Akun: password 04 §4.2, tanpa wajib ganti, role dari jabatan.
    $account = User::query()->where('employee_id', $joko->id)->sole();
    expect(Hash::check(DemoSeeder::DEMO_PASSWORD, $account->password))->toBeTrue()
        ->and($account->must_change_password)->toBeFalse()
        ->and($account->hasRole('kepala_lokasi'))->toBeTrue()
        ->and(User::query()->where('employee_id', Employee::query()->where('full_name', 'Hadi Santoso')->value('id'))->sole()->hasRole('owner'))->toBeTrue();

    // NIK fiktif: terenkripsi + hash, tidak pernah plaintext di tabel.
    $dump = json_encode(DB::table('employees')->get()->all(), JSON_THROW_ON_ERROR);
    expect(preg_match('/(?<!\d)\d{16}(?!\d)/', $dump))->toBe(0)
        ->and(Employee::query()->whereNull('nik_hash')->count())->toBe(0);
});

test('CLAUDE.md §6 seeder demo ditolak di produksi', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new DemoSeeder)->run())->toThrow(RuntimeException::class);
    expect(Department::query()->count())->toBe(0);

    app()->detectEnvironment(fn () => 'testing');
});
