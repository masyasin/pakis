<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBarcode;
use App\Domain\Catalog\Models\ProductRegistration;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Queries\ActiveRegistration;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\NikCipher;
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

    // NIK fiktif: terenkripsi + hash, tidak pernah plaintext di tabel. Pola 16 digit hanya diperiksa
    // di kolom plaintext: hash hex/ciphertext kadang kebetulan memuat 16 digit beruntun (test goyah).
    $plain = json_encode(DB::table('employees')->get()->map(fn ($row) => array_diff_key((array) $row, array_flip(['nik_enc', 'nik_hash'])))->all(), JSON_THROW_ON_ERROR);
    expect(preg_match('/(?<!\d)\d{16}(?!\d)/', $plain))->toBe(0)
        ->and(Employee::query()->whereNull('nik_hash')->count())->toBe(0);

    // Kolom kripto tidak memuat NIK aslinya.
    $cipher = app(NikCipher::class);
    $dump = json_encode(DB::table('employees')->get()->all(), JSON_THROW_ON_ERROR);
    foreach (Employee::query()->get() as $employee) {
        $nik = $cipher->decrypt((string) $employee->nik_enc);
        expect($nik)->toMatch('/^99\d{14}$/')
            ->and($dump)->not->toContain($nik);
    }
});

test('T-070 data demo: 14 produk fiktif dengan satuan dasar, kemasan besar, dan barcode unik; idempoten', function () {
    $this->seed(DemoSeeder::class);
    Product::query()->where('sku', 'DM-INS-001')->update(['name' => 'Diubah dari layar']);
    $this->seed(DemoSeeder::class);

    expect(Product::query()->count())->toBe(14)
        ->and(Product::query()->where('sku', 'DM-INS-001')->value('name'))->toBe('Diubah dari layar')
        ->and(ProductUnit::query()->where('is_base', true)->count())->toBe(14)
        ->and(ProductUnit::query()->where('is_default_sales', true)->count())->toBe(14)
        ->and(ProductUnit::query()->where('is_default_purchase', true)->count())->toBe(14)
        ->and(ProductBarcode::query()->count())->toBe(ProductUnit::query()->count())
        ->and(ProductBarcode::query()->pluck('barcode')->every(fn (string $b) => str_starts_with($b, '200')))->toBeTrue();

    // BR-151: penanda disalin dari kategori (alat tanpa batch/izin edar, pestisida wajib).
    $tool = Product::query()->where('sku', 'DM-ALT-001')->sole();
    $pesticide = Product::query()->where('sku', 'DM-HER-002')->sole();
    expect($tool->tracks_batch || $tool->requires_registration)->toBeFalse()
        ->and($pesticide->tracks_batch && $pesticide->tracks_expiry && $pesticide->requires_registration && $pesticide->is_restricted)->toBeTrue();
});

test('T-071 data demo izin edar: contoh berlaku, akan habis, habis, dan tanpa izin; idempoten', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $active = fn (string $sku) => ActiveRegistration::for(Product::query()->where('sku', $sku)->sole());
    expect(ProductRegistration::query()->count())->toBe(11)
        ->and($active('DM-INS-001'))->not->toBeNull()
        ->and($active('DM-INS-002'))->not->toBeNull()
        ->and($active('DM-HER-002'))->toBeNull()
        ->and($active('DM-MOL-001'))->toBeNull()
        ->and(ProductRegistration::query()->pluck('registration_no')->every(fn (string $no) => str_starts_with($no, 'RI-DEMO-')))->toBeTrue();
});

test('T-068 data demo: gudang & toko dengan rak (+ Q-01), 5 kendaraan fiktif, canvas punya lokasi stok; idempoten', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $locations = DB::table('stock_locations')->pluck('type', 'code')->all();
    expect($locations)->toMatchArray(['GD-UNDAAN' => 'warehouse', 'TK-KALIREJO' => 'store', 'KDR-K9101DM' => 'vehicle', 'KDR-K9102DM' => 'vehicle'])
        ->and(DB::table('vehicles')->count())->toBe(5)
        ->and(DB::table('vehicles')->where('is_canvas', true)->whereNull('home_location_id')->count())->toBe(0)
        ->and(DB::table('stock_bins')->where('code', 'Q-01')->count())->toBe(2)
        ->and(DB::table('stock_bins')->count())->toBe(9);
});

test('CLAUDE.md §6 seeder demo ditolak di produksi', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new DemoSeeder)->run())->toThrow(RuntimeException::class);
    expect(Department::query()->count())->toBe(0);

    app()->detectEnvironment(fn () => 'testing');
});
