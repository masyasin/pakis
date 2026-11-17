<?php

namespace Database\Seeders;

use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Approval\Actions\DecideApprovalStep;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Models\ApprovalRule;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftPattern;
use App\Domain\Catalog\Enums\CategoryKind;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBarcode;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductRegistration;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Queries\ActiveRegistration;
use App\Domain\Customer\Models\SalesArea;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Enums\Gender;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\NikCipher;
use App\Domain\Employee\Support\UsernameSuggester;
use App\Domain\Fleet\Actions\SaveVehicle;
use App\Domain\Fleet\Models\Vehicle;
use App\Domain\Inventory\Actions\SaveStockBin;
use App\Domain\Inventory\Actions\SaveStockLocation;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use App\Domain\Pricing\Actions\CreatePriceVersion;
use App\Domain\Pricing\Actions\PublishPriceVersion;
use App\Domain\Pricing\Actions\SavePriceList;
use App\Domain\Pricing\Actions\SavePromotion;
use App\Domain\Pricing\Actions\UpsertPriceItems;
use App\Domain\Pricing\Enums\PriceListKind;
use App\Domain\Pricing\Enums\PromotionStatus;
use App\Domain\Pricing\Models\PriceList;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Support\PriceRounding;
use App\Domain\Purchasing\Actions\CheckGoodsReceipt;
use App\Domain\Purchasing\Actions\PostGoodsReceipt;
use App\Domain\Purchasing\Actions\SaveGoodsReceipt;
use App\Domain\Purchasing\Actions\SavePrincipal;
use App\Domain\Purchasing\Actions\SavePurchaseOrder;
use App\Domain\Purchasing\Actions\SavePurchaseReturn;
use App\Domain\Purchasing\Actions\SaveSupplier;
use App\Domain\Purchasing\Actions\ShipPurchaseReturn;
use App\Domain\Purchasing\Actions\SubmitPurchaseOrder;
use App\Domain\Purchasing\Actions\SubmitPurchaseReturn;
use App\Domain\Purchasing\Approvables\PurchaseReturnApproval;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Enums\PurchaseReturnStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\Principal;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Money\Money;
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
        $this->seedRegistrations();
        $this->seedStock();
        $this->call(CustomerDemoSeeder::class);
        $this->seedPurchasing();
        $this->seedPricing();
        $this->seedPromotions();
        $this->seedPurchaseOrders();
        $this->seedGoodsReceipts();
        $this->seedPurchaseReturns();
        // Stok, mutasi, opname, karantina & BA demo untuk laporan stok (T-087).
        $this->call(InventoryDemoSeeder::class);

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

        // Urutan mulai 1 (keputusan Mas 17 Nov 2026): lebih mudah dibaca admin daripada kelipatan 10.
        $order = 0;
        foreach ($categories as $code => [$name, $kind, $flags]) {
            $order++;
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
     *
     * @var array<string, array{0: string, 1: string, 2: string|null, 3: string|null, 4: string|null, 5: string, 6: array{0: string, 1: string}|null, 7: bool}>
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

        foreach (self::PRODUCTS as $sku => $spec) {
            [$name, $categoryCode, $ingredient, $form, $package, $baseUnit, $bulk, $restricted] = $spec;
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
     * Izin edar demo T-071 (nomor fiktif `RI-DEMO-…`, bukan izin Kementan sungguhan). Masa berlaku relatif
     * terhadap hari ini agar layar selalu punya contoh: berlaku lama, akan habis (25 & 50 hari),
     * sudah habis (perpanjangan belum ada), dan produk wajib izin tanpa izin sama sekali (DM-MOL-001).
     * Produk yang sudah punya izin dilewati (idempoten, ubahan dari layar tidak ditimpa).
     *
     * @var array<string, array{0: int, 1: string}> SKU => [selisih hari dari hari ini, status]
     */
    private const REGISTRATIONS = [
        'DM-INS-001' => [900, 'active'],
        'DM-INS-002' => [25, 'active'],
        'DM-FUN-001' => [700, 'active'],
        'DM-FUN-002' => [50, 'active'],
        'DM-HER-001' => [500, 'active'],
        'DM-HER-002' => [-20, 'expired'],
        'DM-ROD-001' => [400, 'active'],
        'DM-PER-001' => [800, 'active'],
        'DM-PUP-001' => [600, 'active'],
        'DM-BIB-001' => [300, 'active'],
        'DM-BIB-002' => [300, 'active'],
    ];

    private function seedRegistrations(): void
    {
        $today = CarbonImmutable::now(DisplayTime::timezone())->startOfDay();
        $seq = 0;
        foreach (self::REGISTRATIONS as $sku => $spec) {
            [$offset, $status] = $spec;
            $seq++;
            $product = Product::query()->where('sku', $sku)->first();
            if ($product === null || ProductRegistration::query()->where('product_id', $product->id)->exists()) {
                continue;
            }

            $validUntil = $today->addDays($offset);
            (new ProductRegistration)->forceFill([
                'product_id' => $product->id,
                'registration_no' => sprintf('RI-DEMO-%04d', $seq),
                'holder_name' => 'PT Prinsipal Demo',
                'issued_at' => $validUntil->subYears(5)->toDateString(),
                'valid_until' => $validUntil->toDateString(),
                'status' => $status,
            ])->save();
        }
    }

    /**
     * Lokasi stok, rak, dan kendaraan demo (T-068, permintaan Mas 16 Nov 2026). Lewat Action resmi agar
     * rak karantina `Q-01` dan lokasi stok kendaraan canvas (`KDR-…`) terbentuk sama seperti dari layar.
     * Plat nomor fiktif berakhiran DM. Yang sudah ada (kode/plat sama) dilewati.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: list<array{0: string, 1: string, 2: string}>}>
     *                                                                                                             kode => [nama, tipe, kode lokasi, rak [kode, nama, zona]]
     */
    private const STOCK_LOCATIONS = [
        'GD-UNDAAN' => ['Gudang Utama Undaan', 'warehouse', 'GDG-01', [
            ['R1-01', 'Rak pestisida cair', 'pesticide'], ['R1-02', 'Rak pestisida tepung', 'pesticide'],
            ['R2-01', 'Rak pupuk & benih', 'general'], ['R2-02', 'Rak alat semprot', 'general'],
            ['R3-01', 'Lemari pestisida terbatas', 'restricted'],
        ]],
        'TK-KALIREJO' => ['Toko Kalirejo', 'store', 'TKO-01', [
            ['ETALASE-1', 'Etalase depan', 'general'], ['R1-01', 'Rak pestisida toko', 'pesticide'],
        ]],
    ];

    /** @var array<string, array{0: string, 1: string, 2: bool, 3: string, 4: bool}> plat => [nama, jenis, canvas?, kode lokasi asal, aktif?] */
    private const VEHICLES = [
        'K9101DM' => ['Truk Engkel Canvas 1', 'truck', true, 'GDG-01', true],
        'K9102DM' => ['Pickup Canvas Kalirejo', 'pickup', true, 'TKO-01', true],
        'K9103DM' => ['Pickup Antar Barang', 'pickup', false, 'GDG-01', true],
        'K5104DM' => ['Motor Sales Lapangan', 'motorcycle', false, 'KTR-01', true],
        'K9105DM' => ['Truk Lama (cadangan)', 'truck', false, 'GDG-01', false],
    ];

    private function seedStock(): void
    {
        $locations = Location::query()->pluck('id', 'code');

        foreach (self::STOCK_LOCATIONS as $code => $spec) {
            [$name, $type, $locationCode, $bins] = $spec;
            $stock = StockLocation::withTrashed()->where('code', $code)->first()
                ?? app(SaveStockLocation::class)->handle(null, [
                    'code' => $code, 'name' => $name, 'type' => $type, 'location_id' => $locations[$locationCode] ?? null,
                    'is_sellable' => true, 'is_active' => true,
                ], null);
            foreach ($bins as [$binCode, $binName, $zone]) {
                if (StockBin::query()->where('stock_location_id', $stock->id)->where('code', $binCode)->exists()) {
                    continue;
                }
                app(SaveStockBin::class)->handle(null, [
                    'stock_location_id' => $stock->id, 'code' => $binCode, 'name' => $binName, 'zone' => $zone, 'is_active' => true,
                ], null);
            }
        }

        foreach (self::VEHICLES as $plate => $spec) {
            [$name, $type, $canvas, $home, $active] = $spec;
            if (Vehicle::withTrashed()->where('plate_no', $plate)->exists()) {
                continue;
            }
            app(SaveVehicle::class)->handle(null, [
                'plate_no' => $plate, 'name' => $name, 'vehicle_type' => $type, 'is_canvas' => $canvas,
                'home_location_id' => $locations[$home] ?? null, 'is_active' => $active,
            ], null);
        }
    }

    /**
     * Prinsipal & supplier demo T-072: nama FIKTIF berakhiran "Demo" (bukan mitra asli Pakis; data asli
     * diisi lewat layar atau impor). kode => [nama, kontak, telepon, situs].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string|null}>
     */
    private const PRINCIPALS = [
        'AGROKIMIA' => ['PT Agro Kimia Demo', 'Bagian Penjualan', '024-7600001', 'www.agrokimia-demo.test'],
        'TANIMAKMUR' => ['PT Tani Makmur Demo', 'Rina (sales area)', '0291-430002', null],
        'BENIHUNGGUL' => ['PT Benih Unggul Demo', 'Layanan Mitra', '031-5300003', 'www.benihunggul-demo.test'],
        'PUPUKSUBUR' => ['CV Pupuk Subur Demo', 'Admin Gudang', '0291-430004', null],
        'ALATTANI' => ['CV Alat Tani Demo', 'Sales Kudus', '0812-0000-0005', null],
        'SINARCHEM' => ['PT Sinar Chem Demo', 'Bagian Distribusi', '021-5800006', 'www.sinarchem-demo.test'],
    ];

    /** SKU produk demo => kode prinsipal. */
    private const PRODUCT_PRINCIPALS = [
        'DM-INS-001' => 'AGROKIMIA', 'DM-INS-002' => 'SINARCHEM', 'DM-FUN-001' => 'TANIMAKMUR', 'DM-FUN-002' => 'AGROKIMIA',
        'DM-HER-001' => 'SINARCHEM', 'DM-HER-002' => 'TANIMAKMUR', 'DM-MOL-001' => 'AGROKIMIA', 'DM-ROD-001' => 'SINARCHEM',
        'DM-PER-001' => 'TANIMAKMUR', 'DM-PUP-001' => 'PUPUKSUBUR', 'DM-BIB-001' => 'BENIHUNGGUL', 'DM-BIB-002' => 'BENIHUNGGUL',
        'DM-ALT-001' => 'ALATTANI', 'DM-ALT-002' => 'ALATTANI',
    ];

    /**
     * Supplier demo: nama => [prinsipal (beli langsung pabrik) | null, tempo, bank, rekening dummy, aktif?,
     * produk dipasok [SKU => lead time hari]]. Rekening dummy berawalan 999 (bukan rekening sungguhan).
     *
     * @var array<string, array{0: string|null, 1: int, 2: string, 3: string, 4: bool, 5: array<string, int>}>
     */
    private const SUPPLIERS = [
        'PT Agro Kimia Demo (pabrik)' => ['AGROKIMIA', 45, 'BRI', '9990012345678', true, ['DM-INS-001' => 7, 'DM-FUN-002' => 7, 'DM-MOL-001' => 10]],
        'CV Distributor Tani Demo' => [null, 30, 'BCA', '9990098765', true, ['DM-INS-002' => 3, 'DM-HER-001' => 3, 'DM-FUN-001' => 4, 'DM-PER-001' => 4, 'DM-ROD-001' => 5]],
        'CV Sarana Benih Demo' => [null, 14, 'Mandiri', '9990011122233', true, ['DM-BIB-001' => 5, 'DM-BIB-002' => 5, 'DM-PUP-001' => 6]],
        'UD Alat Pertanian Demo' => [null, 0, 'BRI', '9990044455566', false, ['DM-ALT-001' => 14, 'DM-ALT-002' => 14]],
    ];

    private function seedPurchasing(): void
    {
        foreach (self::PRINCIPALS as $code => $spec) {
            [$name, $contact, $phone, $website] = $spec;
            if (Principal::withTrashed()->where('code', $code)->exists()) {
                continue;
            }
            app(SavePrincipal::class)->handle(null, [
                'code' => $code, 'name' => $name, 'contact_name' => $contact, 'phone' => $phone,
                'email' => null, 'address' => null, 'website' => $website, 'is_active' => true,
            ], null);
        }

        $principals = Principal::query()->pluck('id', 'code');
        foreach (self::PRODUCT_PRINCIPALS as $sku => $code) {
            // Hanya produk yang belum punya prinsipal (ubahan dari layar tidak ditimpa).
            Product::query()->where('sku', $sku)->whereNull('principal_id')->update(['principal_id' => $principals[$code] ?? null]);
        }

        $products = Product::query()->pluck('id', 'sku');
        foreach (self::SUPPLIERS as $name => $spec) {
            [$principal, $term, $bank, $account, $active, $supplied] = $spec;
            if (Supplier::withTrashed()->where('name', $name)->exists()) {
                continue;
            }
            $rows = [];
            foreach ($supplied as $sku => $lead) {
                if (isset($products[$sku])) {
                    $rows[] = ['product_id' => (int) $products[$sku], 'supplier_sku' => null, 'lead_time_days' => $lead, 'is_preferred' => $principal !== null];
                }
            }
            app(SaveSupplier::class)->handle(null, [
                'name' => $name, 'principal_id' => $principal === null ? null : ($principals[$principal] ?? null),
                'tax_id' => null, 'contact_name' => 'Bagian Penjualan', 'phone' => '0291-440000', 'email' => null, 'address' => null,
                'village_code' => null, 'payment_term_days' => $term, 'bank_name' => $bank, 'bank_account_no' => $account,
                'bank_account_name' => $name, 'is_active' => $active,
            ], $rows, null);
        }
    }

    /**
     * Harga demo T-075 (rupiah per satuan dasar, angka bulat fiktif). Kemasan besar = harga satuan
     * dasar × faktor dikurangi 5%; tier "mulai 10" satuan dasar = −3%.
     *
     * @var array<string, int>
     */
    private const BASE_PRICES = [
        'DM-INS-001' => 85000, 'DM-INS-002' => 62000, 'DM-FUN-001' => 135000, 'DM-FUN-002' => 98000,
        'DM-HER-001' => 78000, 'DM-HER-002' => 410000, 'DM-MOL-001' => 45000, 'DM-ROD-001' => 18000,
        'DM-PER-001' => 32000, 'DM-PUP-001' => 27000, 'DM-BIB-001' => 95000, 'DM-BIB-002' => 52000,
        'DM-ALT-001' => 650000, 'DM-ALT-002' => 15000,
    ];

    /**
     * Daftar harga demo: Bawaan + Grosir (tier, harga −4%) + area Kudus Utara (−2%). Tiap list punya
     * versi 1 terbit (berlaku sejak awal bulan lalu) dan list Bawaan punya versi 2 DRAF (+5%) untuk
     * dicoba ajukan/terbitkan. List yang sudah ada (kode sama) dilewati.
     */
    /**
     * kode => [nama, jenis, kode area | null, persen dari harga dasar].
     *
     * @var array<string, array{0: string, 1: string, 2: string|null, 3: string}>
     */
    private const PRICE_LISTS = [
        'DEFAULT' => ['Harga Bawaan', 'default', null, '0'],
        'GROSIR' => ['Harga Grosir', 'tier', null, '-4'],
        'AREA-KDS-UTR' => ['Harga Area Kudus Utara', 'area', 'KDS-UTR', '-2'],
    ];

    private function seedPricing(): void
    {
        $units = ProductUnit::query()->with('product:id,sku')->whereHas('product', fn ($q) => $q->whereIn('sku', array_keys(self::BASE_PRICES)))->get();
        $start = CarbonImmutable::now(DisplayTime::timezone())->subMonthNoOverflow()->startOfMonth()->format('Y-m-d\TH:i');

        foreach (self::PRICE_LISTS as $code => $spec) {
            [$name, $kindValue, $areaCode, $percent] = $spec;
            $kind = PriceListKind::from($kindValue);
            if (PriceList::query()->where('code', $code)->exists()) {
                continue;
            }
            $areaId = $areaCode === null ? null : SalesArea::query()->where('code', $areaCode)->value('id');
            if ($kind === PriceListKind::Area && $areaId === null) {
                continue; // area demo T-073 belum ada (seeder pelanggan dilewati)
            }
            $list = app(SavePriceList::class)->handle(null, [
                'code' => $code, 'name' => $name, 'kind' => $kind->value, 'sales_area_id' => $areaId,
                'stock_location_id' => null, 'description' => 'Data demo', 'is_active' => true,
            ], null);

            $version = app(CreatePriceVersion::class)->handle($list, ['mode' => 'empty', 'effective_from' => $start, 'note' => 'Harga awal (demo)'], null);
            $rows = [];
            foreach ($units as $unit) {
                $base = Money::of(self::BASE_PRICES[$unit->product->sku]);
                $price = PriceRounding::adjust($unit->is_base ? $base : $base->times($unit->factor)->times('0.95'), $percent);
                $rows[] = ['product_unit_id' => $unit->id, 'min_qty' => '1', 'price' => (string) $price];
                if ($unit->is_base) {
                    $rows[] = ['product_unit_id' => $unit->id, 'min_qty' => '10', 'price' => (string) PriceRounding::adjust($price, '-3')];
                }
            }
            app(UpsertPriceItems::class)->handle($version, $rows, [], null);
            app(PublishPriceVersion::class)->handle($version, null);

            if ($kind === PriceListKind::Default) {
                app(CreatePriceVersion::class)->handle($list, [
                    'mode' => 'copy_percent', 'source_version_id' => $version->id, 'percent' => '5',
                    'effective_from' => CarbonImmutable::now(DisplayTime::timezone())->addMonthNoOverflow()->startOfMonth()->format('Y-m-d\TH:i'),
                    'note' => 'Rencana kenaikan 5% (demo, draf)',
                ], null);
            }
        }
    }

    /**
     * Promo demo T-076 (fiktif): tiga aktif (diskon % kategori Insektisida, beli 10 gratis 1, diskon
     * bertingkat Fungisida) + satu draf (harga bundel) untuk dicoba diajukan. Periode: awal bulan ini
     * sampai akhir bulan depan. Promo yang sudah ada (kode sama) dilewati.
     */
    private function seedPromotions(): void
    {
        $tz = DisplayTime::timezone();
        $from = CarbonImmutable::now($tz)->startOfMonth()->format('Y-m-d\TH:i');
        $to = CarbonImmutable::now($tz)->addMonthNoOverflow()->endOfMonth()->startOfDay()->format('Y-m-d\TH:i');
        $category = fn (string $code) => ProductCategory::query()->where('code', $code)->value('id');
        $product = fn (string $sku) => Product::query()->where('sku', $sku)->value('id');
        if ($category('INSEKTISIDA') === null || $product('DM-INS-001') === null) {
            return;
        }

        $promos = [
            'DEMO-INS5' => ['Diskon 5% Insektisida', 'percent_discount', 'active', 100, false,
                [['type' => 'category', 'id' => $category('INSEKTISIDA')]], [['min_qty_base' => '1', 'discount_pct' => '5']]],
            'DEMO-B10G1' => ['Beli 10 Gratis 1 Abamektin', 'buy_x_get_y', 'active', 50, false,
                [['type' => 'product', 'id' => $product('DM-INS-001')]], [['min_qty_base' => '10', 'free_product_id' => $product('DM-INS-001'), 'free_qty_base' => '1']]],
            'DEMO-FUNTIER' => ['Diskon Bertingkat Fungisida', 'tiered_discount', 'active', 100, true,
                [['type' => 'category', 'id' => $category('FUNGISIDA')]], [['min_qty_base' => '5', 'discount_pct' => '2'], ['min_qty_base' => '20', 'discount_pct' => '4']]],
            'DEMO-BUNDEL' => ['Bundel 3 Perekat', 'bundle_price', 'draft', 100, false,
                [['type' => 'product', 'id' => $product('DM-PER-001')]], [['min_qty_base' => '3', 'bundle_price' => '90000']]],
        ];

        foreach ($promos as $code => [$name, $type, $status, $priority, $stackable, $targets, $tiers]) {
            if (Promotion::query()->where('code', $code)->exists()) {
                continue;
            }
            $promo = app(SavePromotion::class)->handle(null, [
                'code' => $code, 'name' => $name, 'type' => $type, 'starts_at' => $from, 'ends_at' => $to,
                'priority' => $priority, 'is_stackable' => $stackable, 'channels' => ['office', 'sales', 'mitra'],
                'budget_amount' => null, 'funded_by' => 'company', 'principal_id' => null, 'principal_share_pct' => null,
                'description' => 'Data demo', 'targets' => $targets, 'scopes' => [['type' => 'all', 'id' => null]], 'tiers' => $tiers,
            ], null);
            if ($status === 'active') {
                $promo->forceFill(['status' => PromotionStatus::Active])->save();
            }
        }
    }

    /**
     * PO demo T-079 (fiktif) ke Gudang Utama Undaan, dibuat oleh admin demo: satu draf, satu diajukan
     * (masuk antrean persetujuan sesuai nilainya), satu disetujui yang lewat perkiraan datang (contoh
     * "PO terlambat" di dasbor). Lewati bila sudah ada PO (seeder dijalankan ulang).
     */
    private function seedPurchaseOrders(): void
    {
        $creator = User::query()->where('name', 'Dwi Lestari')->first();
        $location = StockLocation::query()->where('code', 'GD-UNDAAN')->value('id');
        $supplier = Supplier::query()->where('name', 'CV Distributor Tani Demo')->value('id');
        if ($creator === null || $location === null || $supplier === null || PurchaseOrder::query()->exists()) {
            return;
        }

        $unit = fn (string $sku) => ProductUnit::query()->whereHas('product', fn ($q) => $q->where('sku', $sku))
            ->orderByDesc('is_base')->value('id');
        $today = CarbonImmutable::now(DisplayTime::timezone());
        $orders = [
            // [tanggal mundur, perkiraan datang mundur/maju, nasib, baris [sku, qty, harga, diskon]]
            [0, 4, 'draft', [['DM-INS-002', '24', '58000', '0'], ['DM-PER-001', '12', '29500', '0']]],
            [1, 3, 'submit', [['DM-HER-001', '60', '72000', '2.5'], ['DM-FUN-001', '20', '126000', '0']]],
            [9, -2, 'approved', [['DM-ROD-001', '100', '16500', '0'], ['DM-INS-002', '36', '57500', '1']]],
        ];

        foreach ($orders as [$back, $expected, $fate, $lines]) {
            $rows = [];
            foreach ($lines as [$sku, $qty, $price, $discount]) {
                $unitId = $unit($sku);
                if ($unitId !== null) {
                    $rows[] = ['product_unit_id' => (int) $unitId, 'qty' => $qty, 'unit_price' => $price, 'discount_pct' => $discount, 'note' => null];
                }
            }
            if ($rows === []) {
                continue;
            }
            $order = app(SavePurchaseOrder::class)->handle(null, [
                'supplier_id' => $supplier, 'stock_location_id' => $location,
                'order_date' => $today->subDays($back)->toDateString(),
                'expected_date' => $today->addDays($expected)->toDateString(),
                'payment_term_days' => null, 'note' => 'Data demo',
            ], $rows, $creator->id);

            // Diajukan hanya bila aturan persetujuan PO sudah di-seed (ApprovalRuleSeeder); tanpa itu tetap draf.
            if ($fate === 'submit' && ApprovalRule::query()->where('document_type', 'purchase_order')->where('is_active', true)->exists()) {
                app(SubmitPurchaseOrder::class)->handle($order, $creator);
            } elseif ($fate === 'approved') {
                // Jalan pintas khusus demo (pola promo demo): langsung disetujui tanpa antrean.
                $order->forceFill(['status' => PurchaseOrderStatus::Approved, 'submitted_at' => now(), 'approved_at' => now()])->save();
            }
        }
    }

    /**
     * Retur demo T-081: dari batch hasil penerimaan demo, satu retur sudah dikirim (diajukan kepala gudang,
     * disetujui owner, dikirim kepala gudang) dan satu draf. Lewat Action resmi agar reservasi, mutasi,
     * dan event akuntansi sama seperti dari layar. Dilewati bila sudah ada retur (idempoten).
     */
    private function seedPurchaseReturns(): void
    {
        $maker = User::query()->where('name', 'Joko Susilo')->first();
        $owner = User::query()->where('name', 'Hadi Santoso')->first();
        $receipt = GoodsReceipt::query()->where('status', 'posted')->orderBy('id')->first();
        $reason = ReferenceItem::query()->forGroup(ReferenceGroup::PurchaseReturnReason)->where('code', 'damaged')->value('id');
        if ($maker === null || $owner === null || $receipt === null || $reason === null || PurchaseReturn::query()->exists()) {
            return;
        }
        $batches = GoodsReceiptLine::query()->where('goods_receipt_id', $receipt->id)->whereNotNull('stock_batch_id')->orderBy('id')->pluck('stock_batch_id');
        if ($batches->isEmpty()) {
            return;
        }
        $header = ['supplier_id' => $receipt->supplier_id, 'stock_location_id' => $receipt->stock_location_id, 'reason_id' => (int) $reason,
            'goods_receipt_id' => $receipt->id, 'note' => 'Data demo'];

        $shipped = app(SavePurchaseReturn::class)->handle(null, $header, [['stock_batch_id' => (int) $batches[0], 'qty_base' => '5']], $maker);
        if (ApprovalRule::query()->where('document_type', 'purchase_return')->where('is_active', true)->exists()) {
            $submitted = app(SubmitPurchaseReturn::class)->handle($shipped, $maker);
            if ($submitted->status === PurchaseReturnStatus::PendingApproval && $submitted->approval_request_id !== null) {
                $request = ApprovalRequest::query()->findOrFail($submitted->approval_request_id);
                app(DecideApprovalStep::class)->approve($owner, $request->id, $request->current_step, 'Data demo', true);
            }
        } else {
            // Jalan pintas khusus demo (pola PO demo) bila aturan persetujuan belum di-seed: tetap lewat
            // onApproved agar reservasi stok dibuat sama seperti persetujuan sungguhan.
            $submitted = $shipped;
            StockLedger::transaction(function () use ($shipped) {
                $doc = PurchaseReturnApproval::query()->lockForUpdate()->findOrFail($shipped->id);
                $doc->forceFill(['status' => PurchaseReturnStatus::PendingApproval])->save();
                $doc->onApproved(null);
            });
        }
        if ($submitted->fresh()?->status === PurchaseReturnStatus::Approved) {
            app(ShipPurchaseReturn::class)->handle($submitted, [], $maker);
        }

        // Draf: barang lain yang masih dikumpulkan sebelum diajukan.
        $draftBatch = $batches[1] ?? $batches[0];
        app(SavePurchaseReturn::class)->handle(null, [...$header, 'goods_receipt_id' => null, 'reason_id' => (int) (ReferenceItem::query()->forGroup(ReferenceGroup::PurchaseReturnReason)->where('code', 'near_expiry')->value('id') ?? $reason)],
            [['stock_batch_id' => (int) $draftBatch, 'qty_base' => '2']], $maker);
    }

    /**
     * Penerimaan demo T-080 atas PO demo yang sudah disetujui: satu penerimaan sebagian yang sudah
     * diposting (diperiksa staf gudang, diposting kepala gudang — dua orang, SEC-104) dan satu draf
     * sisa barang. Lewat Action resmi agar batch, HPP, status PO, dan outbox sama seperti dari layar.
     * Dilewati bila sudah ada penerimaan (idempoten).
     */
    private function seedGoodsReceipts(): void
    {
        $order = PurchaseOrder::query()->where('status', PurchaseOrderStatus::Approved)->where('note', 'Data demo')->orderBy('id')->first();
        $checker = User::query()->where('name', 'Slamet Riyadi')->first();
        $poster = User::query()->where('name', 'Joko Susilo')->first();
        if ($order === null || $checker === null || $poster === null || GoodsReceipt::query()->exists()) {
            return;
        }

        $bin = StockBin::query()->where('stock_location_id', $order->stock_location_id)->where('code', 'R1-01')->value('id');
        $lines = $order->lines()->with('product')->get();
        $line = function (PurchaseOrderLine $l, string $qty, string $batch) use ($bin): array {
            $registration = $l->product->requires_registration ? ActiveRegistration::for($l->product)?->registration_no : null;

            return [
                'id' => null, 'purchase_order_line_id' => $l->id, 'product_unit_id' => null, 'qty_received' => $qty, 'qty_rejected' => '0',
                'reject_reason_id' => null, 'batch_no' => $l->product->tracks_batch ? $batch : null,
                'expiry_date' => $l->product->tracks_expiry ? CarbonImmutable::now(DisplayTime::timezone())->addMonths(20)->toDateString() : null,
                'registration_no_seen' => $registration, 'stock_bin_id' => $bin === null ? null : (int) $bin, 'unit_cost' => null,
            ];
        };
        $header = fn (string $sj, int $daysAgo) => [
            'purchase_order_id' => $order->id,
            'received_at' => CarbonImmutable::now(DisplayTime::timezone())->subDays($daysAgo)->setTime(9, 30)->format('Y-m-d\TH:i'),
            'supplier_delivery_no' => $sj, 'vehicle_plate' => 'K 9300 DM', 'note' => 'Data demo',
        ];

        $first = array_values($lines->values()->map(fn (PurchaseOrderLine $l, int $i) => $line($l, $i === 0 ? '60' : (string) $l->qty, 'DM-B'.($i + 1)))->all());
        $posted = app(SaveGoodsReceipt::class)->handle(null, $header('SJ-DEMO-001', 2), $first, $checker);
        app(CheckGoodsReceipt::class)->handle($posted, $checker);
        app(PostGoodsReceipt::class)->handle($posted, $poster);

        // Sisa baris pertama datang belakangan: masih draf.
        $rest = $lines->first();
        if ($rest !== null) {
            app(SaveGoodsReceipt::class)->handle(null, $header('SJ-DEMO-002', 0), [$line($rest, '40', 'DM-B9')], $checker);
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
