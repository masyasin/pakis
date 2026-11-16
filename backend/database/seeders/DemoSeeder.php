<?php

namespace Database\Seeders;

use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftPattern;
use App\Domain\Catalog\Enums\CategoryKind;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBarcode;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Enums\Gender;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\NikCipher;
use App\Domain\Employee\Support\UsernameSuggester;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Data DEMO (04 §4.2): hanya `local`/`staging`, DITOLAK di produksi (CLAUDE.md §6: tidak ada data
 * asli Pakis di repo). Idempoten: dijalankan ulang tidak menggandakan data, dan tidak menimpa data
 * yang sudah diubah dari layar.
 *
 *     php artisan db:seed --class=DemoSeeder
 *
 * Modul berikutnya menambah bagiannya di sini (lokasi, jabatan, pegawai, shift, …) sesuai 04 §4.2.
 */
class DemoSeeder extends Seeder
{
    /** Departemen demo 04 §4.2 (struktur datar). Kode singkat dipakai di laporan & impor. */
    public const DEPARTMENTS = [
        'PJL' => 'Penjualan',
        'GDG-LOG' => 'Gudang & Logistik',
        'TOKO' => 'Toko',
        'KEU-ADM' => 'Keuangan & Administrasi',
        'SDM' => 'SDM',
    ];

    /**
     * Jabatan demo 04 §4.2: kode => [nama, kode departemen, kode atasan, level, mode absen].
     * Urutan penting: atasan dibuat lebih dulu.
     */
    public const POSITIONS = [
        'OWNER' => ['Owner', 'KEU-ADM', null, 1, 'location'],
        'SPV-SLS' => ['Supervisor Sales', 'PJL', 'OWNER', 3, 'location'],
        'SLS' => ['Sales', 'PJL', 'SPV-SLS', 5, 'anywhere'],
        'KA-GDG' => ['Kepala Gudang', 'GDG-LOG', 'OWNER', 3, 'location'],
        'STF-GDG' => ['Staf Gudang', 'GDG-LOG', 'KA-GDG', 5, 'location'],
        'DRV' => ['Driver', 'GDG-LOG', 'KA-GDG', 5, 'any_location'],
        'KA-TKO' => ['Kepala Toko', 'TOKO', 'OWNER', 3, 'location'],
        'KSR' => ['Kasir', 'TOKO', 'KA-TKO', 5, 'location'],
        'ADM' => ['Admin', 'KEU-ADM', 'OWNER', 5, 'location'],
        'STF-HRD' => ['Staf HRD', 'SDM', 'OWNER', 5, 'location'],
    ];

    /**
     * Lokasi demo 04 §4.2: kode => [nama, kode tipe lokasi, latitude, longitude]. Koordinat contoh di
     * sekitar Undaan, Kudus, sengaja dibulatkan 3 desimal (±100 m): bukan titik presisi.
     */
    public const LOCATIONS = [
        'KTR-01' => ['Kantor Pusat Kalirejo', 'office', '-6.880', '110.830'],
        'GDG-01' => ['Gudang Undaan', 'warehouse', '-6.890', '110.845'],
        'TKO-01' => ['Toko Kalirejo', 'store', '-6.878', '110.827'],
    ];

    /**
     * 15 pegawai FIKTIF 04 §4.2 (nama Jawa umum, bukan pegawai Pakis): nama => [L/P, kode jabatan,
     * kode lokasi, nama atasan, tanggal lahir, tanggal masuk, role akun]. Atasan didaftarkan lebih dulu.
     */
    public const EMPLOYEES = [
        'Hadi Santoso' => ['L', 'OWNER', 'KTR-01', null, '1972-03-14', '2005-01-03', RoleCatalog::OWNER],
        'Sri Wahyuni' => ['P', 'STF-HRD', 'KTR-01', 'Hadi Santoso', '1990-07-21', '2016-02-01', 'hrd'],
        'Dwi Lestari' => ['P', 'ADM', 'KTR-01', 'Hadi Santoso', '1994-11-02', '2018-06-11', 'administrator'],
        'Agus Prasetyo' => ['L', 'SPV-SLS', 'KTR-01', 'Hadi Santoso', '1985-05-09', '2010-09-01', 'supervisor'],
        'Bayu Saputra' => ['L', 'SLS', 'KTR-01', 'Agus Prasetyo', '1996-01-17', '2020-03-02', RoleCatalog::PEGAWAI],
        'Eko Wibowo' => ['L', 'SLS', 'KTR-01', 'Agus Prasetyo', '1993-08-25', '2019-01-07', RoleCatalog::PEGAWAI],
        'Rina Kurniasih' => ['P', 'SLS', 'KTR-01', 'Agus Prasetyo', '1998-04-30', '2022-08-01', RoleCatalog::PEGAWAI],
        'Joko Susilo' => ['L', 'KA-GDG', 'GDG-01', 'Hadi Santoso', '1980-12-05', '2008-04-14', 'kepala_lokasi'],
        'Slamet Riyadi' => ['L', 'STF-GDG', 'GDG-01', 'Joko Susilo', '1988-02-11', '2012-10-01', RoleCatalog::PEGAWAI],
        'Wahyu Hidayat' => ['L', 'STF-GDG', 'GDG-01', 'Joko Susilo', '1997-09-19', '2021-05-03', RoleCatalog::PEGAWAI],
        'Teguh Purnomo' => ['L', 'DRV', 'GDG-01', 'Joko Susilo', '1987-06-08', '2014-07-07', RoleCatalog::PEGAWAI],
        'Bambang Sutrisno' => ['L', 'DRV', 'GDG-01', 'Joko Susilo', '1982-10-27', '2011-03-01', RoleCatalog::PEGAWAI],
        'Endang Rahayu' => ['P', 'KA-TKO', 'TKO-01', 'Hadi Santoso', '1984-01-23', '2009-11-02', 'kepala_lokasi'],
        'Siti Aminah' => ['P', 'KSR', 'TKO-01', 'Endang Rahayu', '1995-03-08', '2019-09-02', RoleCatalog::PEGAWAI],
        'Lestari Handayani' => ['P', 'KSR', 'TKO-01', 'Endang Rahayu', '1999-12-12', '2023-01-09', RoleCatalog::PEGAWAI],
    ];

    /** Shift pola demo per jabatan; jabatan lain memakai PAGI. */
    public const PATTERN_SHIFT_BY_POSITION = ['SPV-SLS' => 'SLS', 'SLS' => 'SLS', 'KA-GDG' => 'GDG', 'STF-GDG' => 'GDG', 'DRV' => 'GDG'];

    /** Kepala lokasi demo (menentukan scope `location`). */
    public const LOCATION_HEADS = ['GDG-01' => 'Joko Susilo', 'TKO-01' => 'Endang Rahayu'];

    /** Password akun demo, ditetapkan 04 §4.2 (bukan rahasia; seeder ini ditolak di produksi). */
    public const DEMO_PASSWORD = 'password-demo-123';

    /** Shift demo 04 §4.2: [nama, masuk, pulang, toleransi telat (menit)]. */
    public const SHIFTS = [
        'PAGI' => ['Shift Pagi', '08:00:00', '16:00:00', 10],
        'GDG' => ['Shift Gudang', '07:00:00', '15:00:00', 5],
        'SLS' => ['Shift Sales', '07:30:00', '16:30:00', 15],
    ];

    /**
     * Hari libur contoh 04 §4.2 (OQ-008: daftar resmi diisi HRD tiap tahun):
     * [mulai bulan-hari, selesai bulan-hari, nama, keterangan, nasional, kode lokasi ([] = semua)].
     */
    public const HOLIDAYS = [
        ['08-17', '08-17', 'Hari Kemerdekaan RI (contoh)', null, true, []],
        ['12-30', '12-31', 'Stok opname akhir tahun (contoh)', 'Gudang tutup untuk tutup buku & stok opname.', false, ['GDG-01']],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder ditolak di produksi: data demo hanya untuk local/staging (04 §4.2).');
        }

        foreach (self::DEPARTMENTS as $code => $name) {
            // withTrashed: kode unik juga untuk departemen yang terhapus; jangan membuat duplikat.
            Department::withTrashed()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }

        foreach (self::POSITIONS as $code => [$name, $department, $parent, $level, $mode]) {
            Position::withTrashed()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'department_id' => Department::withTrashed()->where('code', $department)->value('id'),
                'parent_position_id' => $parent === null ? null : Position::withTrashed()->where('code', $parent)->value('id'),
                'level' => $level,
                'attendance_mode' => $mode,
            ]);
        }

        foreach (self::SHIFTS as $code => [$name, $start, $end, $tolerance]) {
            Shift::withTrashed()->firstOrCreate(['code' => $code], [
                'name' => $name, 'start_time' => $start, 'end_time' => $end, 'late_tolerance_min' => $tolerance,
            ]);
        }

        $this->seedEmployees();
        $this->seedPatterns();
        $this->seedProductCategories();
        $this->seedProducts();

        // Tahun berjalan menurut zona kantor. Lokasi kosong = berlaku semua lokasi; selain itu contoh
        // libur khusus lokasi (BR-062). Pivot hanya diisi saat libur baru dibuat (tidak menimpa ubahan HRD).
        $year = CarbonImmutable::now(DisplayTime::timezone())->year;
        foreach (self::HOLIDAYS as [$from, $to, $name, $description, $national, $locations]) {
            $holiday = Holiday::query()->firstOrCreate(['start_date' => "{$year}-{$from}", 'name' => $name], [
                'end_date' => "{$year}-{$to}", 'description' => $description, 'is_national' => $national,
                'applies_to_all' => $locations === [],
            ]);
            if ($holiday->wasRecentlyCreated && $locations !== []) {
                $holiday->locations()->sync(Location::query()->whereIn('code', $locations)->pluck('id')->all());
            }
        }
    }

    /**
     * Kategori produk demo = kategori katalog Pakis (keputusan Mas 16 Nov 2026, T-069): satu tingkat.
     * Pestisida & pupuk wajib batch, ED, dan izin edar; alat tanpa batch/ED. Kategori yang sudah ada
     * (kode sama) dilewati agar ubahan dari layar tidak ditimpa.
     */
    private function seedProductCategories(): void
    {
        $pesticide = ['requires_batch' => true, 'requires_expiry' => true, 'requires_serial' => false, 'requires_registration' => true, 'default_bin_zone' => 'pesticide'];
        $categories = [
            'ALAT' => ['Alat-alat', CategoryKind::Tool, ['requires_batch' => false, 'requires_expiry' => false, 'requires_serial' => false, 'requires_registration' => false, 'default_bin_zone' => 'general']],
            'BIBIT' => ['Bibit', CategoryKind::Seed, [...$pesticide, 'default_bin_zone' => 'general']],
            'FUNGISIDA' => ['Fungisida', CategoryKind::Fungicide, $pesticide],
            'HERBISIDA' => ['Herbisida', CategoryKind::Herbicide, $pesticide],
            'INSEKTISIDA' => ['Insektisida', CategoryKind::Insecticide, $pesticide],
            'MOLUKSIDA' => ['Moluksida', CategoryKind::Molluscicide, $pesticide],
            'PEREKAT' => ['Perekat', CategoryKind::Adjuvant, $pesticide],
            'PUPUK' => ['Pupuk', CategoryKind::Fertilizer, [...$pesticide, 'default_bin_zone' => 'general']],
            'RODENTISIDA' => ['Rodentisida', CategoryKind::Rodenticide, $pesticide],
        ];

        $order = 0;
        foreach ($categories as $code => [$name, $kind, $flags]) {
            $order += 10;
            ProductCategory::withTrashed()->firstOrCreate(['code' => $code], [
                'name' => $name, 'kind' => $kind, 'sort_order' => $order, 'is_active' => true, ...$flags,
            ]);
        }
    }

    /**
     * Produk demo T-070: nama generik fiktif per kategori (bukan produk/merek asli Pakis; data asli
     * masuk lewat layar atau impor T-085). Barcode berawalan 200 (rentang GS1 untuk pemakaian internal
     * toko) agar tidak pernah bentrok dengan barcode pabrik sungguhan.
     * SKU => [nama, kategori, bahan aktif, formulasi, kemasan, satuan dasar, kemasan besar [satuan, faktor] | null, terbatas?].
     */
    private const PRODUCTS = [
        'DM-INS-001' => ['Insektisida Demo Abamektin 500 ml', 'INSEKTISIDA', 'Abamektin 18 g/l', 'ec', '500 ml', 'bottle', ['box', '24'], false],
        'DM-INS-002' => ['Insektisida Demo Klorpirifos 250 ml', 'INSEKTISIDA', 'Klorpirifos 200 g/l', 'ec', '250 ml', 'bottle', ['box', '40'], true],
        'DM-FUN-001' => ['Fungisida Demo Mankozeb 1 kg', 'FUNGISIDA', 'Mankozeb 80%', 'wp', '1 kg', 'sachet', ['box', '20'], false],
        'DM-FUN-002' => ['Fungisida Demo Difenokonazol 100 ml', 'FUNGISIDA', 'Difenokonazol 250 g/l', 'ec', '100 ml', 'bottle', ['box', '48'], false],
        'DM-HER-001' => ['Herbisida Demo Glifosat 1 L', 'HERBISIDA', 'Glifosat 480 g/l', 'sl', '1 L', 'bottle', ['box', '12'], false],
        'DM-HER-002' => ['Herbisida Demo Parakuat 5 L', 'HERBISIDA', 'Parakuat diklorida 276 g/l', 'sl', '5 L', 'jerrycan', null, true],
        'DM-MOL-001' => ['Moluksida Demo Niklosamida 250 g', 'MOLUKSIDA', 'Niklosamida 70%', 'wp', '250 g', 'sachet', ['box', '40'], false],
        'DM-ROD-001' => ['Rodentisida Demo Brodifakum 100 g', 'RODENTISIDA', 'Brodifakum 0,005%', 'other', '100 g', 'pack', ['box', '50'], false],
        'DM-PER-001' => ['Perekat Demo Perata 500 ml', 'PEREKAT', 'Alkilaril poliglikol eter 400 g/l', 'sl', '500 ml', 'bottle', ['box', '24'], false],
        'DM-PUP-001' => ['Pupuk Daun Demo NPK 500 g', 'PUPUK', 'NPK 20-20-20', 'other', '500 g', 'pack', ['sack', '50'], false],
        'DM-BIB-001' => ['Benih Jagung Demo Hibrida 1 kg', 'BIBIT', null, null, '1 kg', 'pack', ['sack', '20'], false],
        'DM-BIB-002' => ['Benih Cabai Demo 10 g', 'BIBIT', null, null, '10 g', 'sachet', ['box', '100'], false],
        'DM-ALT-001' => ['Alat Semprot Demo Elektrik 16 L', 'ALAT', null, null, '16 L', 'unit', null, false],
        'DM-ALT-002' => ['Nozel Semprot Demo Kuningan', 'ALAT', null, null, null, 'pcs', ['pack', '10'], false],
    ];

    /**
     * Produk demo + satuan dasar (faktor 1, default jual) + kemasan besar (default beli) + satu barcode
     * dummy per satuan. Produk yang sudah ada (SKU sama) dilewati agar ubahan dari layar tidak ditimpa.
     * Ditulis langsung, bukan lewat CreateProduct, karena seeder tanpa pengguna; penanda tetap disalin
     * dari kategori (BR-151).
     */
    private function seedProducts(): void
    {
        $units = ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->pluck('id', 'code');
        $forms = ReferenceItem::query()->forGroup(ReferenceGroup::Formulation)->pluck('id', 'code');
        $categories = ProductCategory::withTrashed()->get()->keyBy('code');
        $seq = 0;

        foreach (self::PRODUCTS as $sku => [$name, $categoryCode, $ingredient, $form, $package, $baseUnit, $bulk, $restricted]) {
            $seq++;
            $category = $categories[$categoryCode] ?? null;
            if ($category === null || Product::withTrashed()->where('sku', $sku)->exists()) {
                continue;
            }

            DB::transaction(function () use ($sku, $name, $category, $ingredient, $form, $package, $baseUnit, $bulk, $restricted, $units, $forms, $seq): void {
                $product = (new Product)->forceFill([
                    'sku' => $sku, 'name' => $name, 'product_category_id' => $category->id,
                    'active_ingredient' => $ingredient, 'formulation_id' => $form === null ? null : ($forms[$form] ?? null),
                    'package_size' => $package, 'base_unit_id' => $units[$baseUnit],
                    'tracks_batch' => $category->requires_batch, 'tracks_expiry' => $category->requires_expiry,
                    'tracks_serial' => $category->requires_serial, 'requires_registration' => $category->requires_registration,
                    'is_restricted' => $restricted, 'restricted_note' => $restricted ? 'Hanya untuk pembeli memenuhi syarat (demo).' : null,
                    'warranty_months' => $baseUnit === 'unit' ? 12 : null,
                    'is_sellable' => true, 'is_purchasable' => true, 'status' => 'active',
                ]);
                $product->save();

                // [satuan, faktor, satuan dasar?, default jual?, default beli?]
                $rows = [[$baseUnit, '1', true, true, $bulk === null]];
                if ($bulk !== null) {
                    $rows[] = [$bulk[0], $bulk[1], false, false, true];
                }
                foreach ($rows as $k => [$unitCode, $factor, $isBase, $sales, $purchase]) {
                    $unit = (new ProductUnit)->forceFill([
                        'product_id' => $product->id, 'unit_id' => $units[$unitCode], 'factor' => $factor,
                        'is_base' => $isBase, 'is_default_sales' => $sales, 'is_default_purchase' => $purchase, 'is_active' => true,
                    ]);
                    $unit->save();
                    (new ProductBarcode)->forceFill([
                        'product_id' => $product->id, 'product_unit_id' => $unit->id,
                        'barcode' => sprintf('200%04d%05d', $seq, $k + 1),
                    ])->save();
                }
            });
        }
    }

    /**
     * Lokasi, 15 pegawai fiktif + penugasan utama, kepala lokasi, dan akun login (04 §4.2). Pegawai yang
     * sudah ada (nama sama) dilewati, jadi perubahan dari layar tidak ditimpa. NIK dibuat acak saat
     * seeding dengan kode provinsi 99 (tidak ada di Dukcapil) dan tidak pernah tertulis di kode.
     */
    /**
     * Pola demo 04 §4.2: Senin–Sabtu, Minggu libur, berlaku sejak 1 Januari tahun berjalan (atau
     * tanggal masuk bila lebih baru). Shift mengikuti jabatan. Pegawai yang sudah punya pola
     * dilewati agar ubahan HRD di DB dev tidak tertimpa (idempoten).
     */
    private function seedPatterns(): void
    {
        $shifts = Shift::withTrashed()->whereIn('code', array_keys(self::SHIFTS))->pluck('id', 'code');
        $yearStart = CarbonImmutable::now(DisplayTime::timezone())->startOfYear()->toDateString();

        foreach (self::EMPLOYEES as $name => [, $position, , , , $join]) {
            $employee = Employee::query()->where('full_name', $name)->first();
            if ($employee === null || ShiftPattern::query()->where('employee_id', $employee->id)->exists()) {
                continue;
            }

            $shiftId = $shifts[self::PATTERN_SHIFT_BY_POSITION[$position] ?? 'PAGI'] ?? null;
            $row = ['employee_id' => $employee->id, 'effective_from' => max($yearStart, $join)];
            foreach (ShiftPattern::DAYS as $day) {
                $row[ShiftPattern::column($day)] = $day === 'sun' ? null : $shiftId;
            }
            ShiftPattern::query()->create($row);
        }
    }

    private function seedEmployees(): void
    {
        // Seeder wajib idempoten (04 §4.1): agar `db:seed --class=DemoSeeder` tetap bisa berdiri sendiri.
        $this->call([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, SequenceSeeder::class]);

        $types = ReferenceItem::query()->forGroup(ReferenceGroup::LocationType)->pluck('id', 'code');
        foreach (self::LOCATIONS as $code => [$name, $type, $lat, $lng]) {
            Location::withTrashed()->firstOrCreate(['code' => $code], [
                'name' => $name, 'location_type_id' => $types[$type], 'latitude' => $lat, 'longitude' => $lng,
            ]);
        }

        $status = (int) ReferenceItem::query()->forGroup(ReferenceGroup::EmploymentStatus)->where('code', 'permanent')->value('id');
        $numbers = app(NumberGenerator::class);
        $cipher = app(NikCipher::class);

        foreach (self::EMPLOYEES as $name => [$gender, $position, $location, $supervisor, $birth, $join, $role]) {
            $employee = Employee::withTrashed()->where('full_name', $name)->first();

            if ($employee === null) {
                $employee = DB::transaction(function () use ($name, $gender, $position, $location, $supervisor, $birth, $join, $status, $numbers, $cipher) {
                    $nik = $this->randomNik($cipher);
                    $employee = new Employee;
                    $employee->fill([
                        'full_name' => $name,
                        'gender' => Gender::from($gender),
                        'birth_date' => $birth,
                        'employment_status_id' => $status,
                        'join_date' => $join,
                    ]);
                    $employee->forceFill([
                        'employee_number' => $numbers->next('employee', isTaken: fn (string $n) => Employee::withTrashed()->where('employee_number', $n)->exists()),
                        'status' => EmployeeStatus::Active,
                        'nik_enc' => $cipher->encrypt($nik),
                        'nik_hash' => $cipher->hash($nik),
                    ])->save();

                    (new Assignment)->forceFill([
                        'employee_id' => $employee->id,
                        'position_id' => Position::withTrashed()->where('code', $position)->value('id'),
                        'location_id' => Location::withTrashed()->where('code', $location)->value('id'),
                        'supervisor_employee_id' => $supervisor === null ? null : Employee::query()->where('full_name', $supervisor)->value('id'),
                        'is_primary' => true,
                        'start_date' => $join,
                    ])->save();

                    return $employee;
                });
            }

            if (! User::withTrashed()->where('employee_id', $employee->id)->exists()) {
                $username = UsernameSuggester::suggest($name);
                $user = new User;
                $user->forceFill([
                    'employee_id' => $employee->id,
                    'name' => $name,
                    'username' => $username,
                    'email' => "{$username}@pakis-demo.test",
                    'email_verified_at' => now(),
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'must_change_password' => false,
                    'is_active' => true,
                ])->save();
                $user->assignRole($role);
            }
        }

        foreach (self::LOCATION_HEADS as $code => $name) {
            Location::query()->where('code', $code)->whereNull('head_employee_id')
                ->update(['head_employee_id' => Employee::query()->where('full_name', $name)->value('id')]);
        }
    }

    private function randomNik(NikCipher $cipher): string
    {
        do {
            $nik = '99'.str_pad((string) random_int(0, 99_999_999_999_999), 14, '0', STR_PAD_LEFT);
        } while (Employee::withTrashed()->where('nik_hash', $cipher->hash($nik))->exists());

        return $nik;
    }
}
