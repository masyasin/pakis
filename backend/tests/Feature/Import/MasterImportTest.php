<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Inventory\Models\ProductStockLevel;
use App\Domain\Pricing\Models\PriceList;
use App\Domain\Pricing\Models\PriceListItem;
use App\Domain\Pricing\Models\PriceListVersion;
use App\Domain\Purchasing\Models\Principal;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportRegistry;
use App\Support\Import\ImportRow;
use App\Support\Import\ImportRowStatus;
use App\Support\Import\ImportStatus;
use App\Support\Import\MappingSuggester;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CustomerWorld;
use Tests\Support\EmployeeWorld;
use Tests\Support\StockWorld;

/*
 * T-085 — impor master produk, kios, harga, stok minimum (FR-A30, BR-170, PA-70). Berkas Excel dummy
 * dibuat di test: judul di atas tabel, header acak, angka "1.250.000", nilai berpola formula. Data fiktif.
 */

require_once __DIR__.'/../../Support/import_helpers.php';

beforeEach(function () {
    Storage::fake('local');
    $this->org = EmployeeWorld::make($this);
    $this->useRealPermissions();
    $this->owner = $this->org->actor('owner');

    $this->category = ProductCategory::query()->create([
        'code' => 'INS', 'name' => 'Insektisida', 'kind' => 'insecticide', 'requires_batch' => true, 'requires_expiry' => true,
        'requires_serial' => false, 'requires_registration' => true, 'default_bin_zone' => 'pesticide', 'sort_order' => 1, 'is_active' => true,
    ]);
    $this->principal = tap((new Principal)->forceFill(['code' => 'AGRO', 'name' => 'PT Agro Uji', 'is_active' => true]))->save();
});

/** Unggah ke `/impor/{slug}`, petakan sesuai saran, kembalikan batch tervalidasi. */
function miUpload(User $actor, string $slug, UploadedFile $file): ImportBatch
{
    test()->actingAs($actor)->post("/impor/{$slug}", ['file' => $file])->assertSessionHasNoErrors()->assertRedirect();
    $batch = ImportBatch::query()->latest('id')->firstOrFail();
    $columns = MappingSuggester::suggest(ImportRegistry::forBatch($batch), $batch->headers())['columns'];
    test()->actingAs($actor)->put("/impor/{$batch->id}/pemetaan", ['columns' => $columns])->assertSessionHasNoErrors();

    return $batch->refresh();
}

function miProcess(User $actor, ImportBatch $batch, array $payload = []): ImportBatch
{
    test()->actingAs($actor)->post("/impor/{$batch->id}/proses", ['update_existing' => false, ...$payload])->assertSessionHasNoErrors();

    return $batch->refresh();
}

/** @return array{0: int, 1: int, 2: int, 3: int} dibuat, diperbarui, dilewati, galat */
function miCounts(ImportBatch $batch): array
{
    return [$batch->created_rows, $batch->updated_rows, $batch->skipped_rows, $batch->error_rows];
}

/** @return list<string> "baris N kolom: pesan" */
function miErrors(ImportBatch $batch): array
{
    $out = [];
    foreach (ImportRow::query()->where('import_batch_id', $batch->id)->where('status', ImportRowStatus::Invalid)->orderBy('row_number')->get() as $row) {
        foreach ((array) $row->errors as $field => $messages) {
            $out[] = "{$row->row_number} {$field}";
        }
    }

    return $out;
}

function miProducts(): UploadedFile
{
    return impXlsx([
        ['DAFTAR BARANG CV CONTOH (DATA DUMMY)'],
        [''],
        ['Nama Barang', 'Kode Barang', 'Kategori', 'Satuan', 'Merk', 'Prinsipal', 'Satuan Kemasan', 'Isi', 'Keterangan'],
        ['Insektisida Uji 500 ml', 'ins-001', 'ins', 'Botol', 'Agro', 'AGRO', 'dus', '1.024', 'baru'],
        ['Insektisida Uji 1 L', 'INS-002', 'INS', 'bottle', '@merek', '', '', '', ''],
        ['Produk Salah', 'X', 'ZZZ', 'Botol', '', '', '', '', ''],
    ], 'barang.xlsx');
}

test('BR-170 impor produk: kode → id, penanda ikut kategori, satuan kemasan, galat per sel; impor ulang = 0 perubahan', function () {
    $batch = miUpload($this->owner, 'produk', miProducts());
    expect($batch->headerRow())->toBe(3)
        ->and($batch->status)->toBe(ImportStatus::Validated)
        ->and(miErrors($batch))->toBe(['6 sku', '6 category']);

    $batch = miProcess($this->owner, $batch);
    expect(miCounts($batch))->toBe([2, 0, 0, 1]);

    $p = Product::query()->where('sku', 'INS-001')->firstOrFail();
    $box = ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('code', 'box')->value('id');
    expect($p->product_category_id)->toBe($this->category->id)
        ->and($p->principal_id)->toBe($this->principal->id)
        ->and([$p->tracks_batch, $p->tracks_expiry, $p->requires_registration, $p->tracks_serial])->toBe([true, true, true, false])
        ->and(Product::query()->where('sku', 'INS-002')->value('brand'))->toBe('@merek')
        ->and(ProductUnit::query()->where('product_id', $p->id)->where('unit_id', $box)->value('factor'))->toBe('1024.000');

    $again = miProcess($this->owner, miUpload($this->owner, 'produk', miProducts()));
    expect(miCounts($again))->toBe([0, 0, 2, 1])
        ->and(Product::query()->count())->toBe(2);
});

test('BR-170 nilai diawali = + - @ disimpan sebagai teks literal (tidak dievaluasi)', function () {
    // CSV: sel selalu teks. (Sel formula sungguhan di .xlsx dibaca nilai tersimpannya, tidak dievaluasi — ImportTest.)
    $csv = 'SKU;Nama Produk;Kode Kategori;Satuan Dasar;Merek;Bahan Aktif
INS-101;=SUM(A1:A9);INS;Botol;+6281234;-cmd|calc
INS-102;@HYPERLINK(x);INS;Botol;@merek;=1+1
';
    $batch = miProcess($this->owner, miUpload($this->owner, 'produk', impCsv($csv, 'formula.csv')));

    expect(miCounts($batch))->toBe([2, 0, 0, 0])
        ->and(Product::query()->where('sku', 'INS-101')->first()?->only(['name', 'brand', 'active_ingredient']))
        ->toBe(['name' => '=SUM(A1:A9)', 'brand' => '+6281234', 'active_ingredient' => '-cmd|calc'])
        ->and(Product::query()->where('sku', 'INS-102')->first()?->only(['name', 'brand', 'active_ingredient']))
        ->toBe(['name' => '@HYPERLINK(x)', 'brand' => '@merek', 'active_ingredient' => '=1+1']);
});

test('BR-170 impor produk: data berbeda dilewati tanpa opsi perbarui; dengan opsi diperbarui', function () {
    miProcess($this->owner, miUpload($this->owner, 'produk', miProducts()));
    $changed = impXlsx([['SKU', 'Nama Produk', 'Kode Kategori', 'Satuan Dasar'], ['INS-002', 'Insektisida Uji 1 Liter', 'INS', 'Botol']], 'ubah.xlsx');

    expect(miCounts(miProcess($this->owner, miUpload($this->owner, 'produk', $changed))))->toBe([0, 0, 1, 0])
        ->and(Product::query()->where('sku', 'INS-002')->value('name'))->toBe('Insektisida Uji 1 L');

    $changed = impXlsx([['SKU', 'Nama Produk', 'Kode Kategori', 'Satuan Dasar'], ['INS-002', 'Insektisida Uji 1 Liter', 'INS', 'Botol']], 'ubah2.xlsx');
    expect(miCounts(miProcess($this->owner, miUpload($this->owner, 'produk', $changed), ['update_existing' => true])))->toBe([0, 1, 0, 0])
        ->and(Product::query()->where('sku', 'INS-002')->value('name'))->toBe('Insektisida Uji 1 Liter');
});

function miCustomers(): UploadedFile
{
    return impXlsx([
        ['Toko', 'Pemilik', 'No HP', 'Area', 'Jenis', 'Terverifikasi', 'Tempo'],
        ['Kios Tani Makmur', 'Pak Darto', '0812-1111-2222', 'UDN', 'Kios', 'ya', '30'],
        ['Kios Subur', 'Bu Sri', '+62 813 3333 4444', 'UDN', 'kiosk', 'tidak', ''],
        ['Kios Dobel', 'Pak Dobel', '6281211112222', 'UDN', 'Kios', 'tidak', ''],
        ['Kios Pegawai', 'Pak Pegawai', '0899-0000-0001', 'UDN', 'Kios', '', ''],
        ['Kios Area Salah', 'Pak X', '0815-5555-6666', 'XXX', 'Kios', '', ''],
    ], 'kios.xlsx');
}

test('BR-170 BR-166 impor kios: HP dinormalisasi, duplikat dalam berkas & HP pegawai dilaporkan; terverifikasi + password → aktif & teraudit', function () {
    CustomerWorld::area('UDN');
    $this->org->employee(attributes: ['phone' => '6289900000001']);

    $batch = miUpload($this->owner, 'kios', miCustomers());
    // Baris 4 = HP sama dengan baris 2 (duplikat dalam berkas), baris 5 = HP pegawai, baris 6 = area tak dikenal.
    expect(miErrors($batch))->toContain('4 _row', '5 owner_phone', '6 sales_area');

    // Ada baris "ya" + pengimpor memegang customers.verify → proses wajib konfirmasi password.
    $this->actingAs($this->owner)->get("/impor/{$batch->id}/baris")->assertInertia(fn (Assert $page) => $page->where('can.requires_password', true));
    $this->actingAs($this->owner)->post("/impor/{$batch->id}/proses", ['update_existing' => false])->assertSessionHasErrors('current_password');
    $this->actingAs($this->owner)->post("/impor/{$batch->id}/proses", ['current_password' => 'salah'])->assertSessionHasErrors('current_password');
    expect($batch->refresh()->status)->toBe(ImportStatus::Validated);

    $batch = miProcess($this->owner, $batch, ['current_password' => 'password']);
    expect(miCounts($batch))->toBe([2, 0, 0, 3]);
    $makmur = Customer::query()->where('owner_phone', '6281211112222')->firstOrFail();
    expect($makmur->status)->toBe(CustomerStatus::Active)
        ->and($makmur->verified_by)->toBe($this->owner->id)
        ->and($makmur->credit_limit_active)->toBeFalse()
        ->and(Customer::query()->where('owner_phone', '6281333334444')->value('status'))->toBe(CustomerStatus::Prospect)
        ->and(AuditLog::query()->where('event', 'customer.imported_verified')->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'customer.imported_verified')->value('new_values')['import_batch_id'] ?? null)->toBe($batch->id)
        ->and(AuditLog::query()->where('event', 'import.processed')->latest('id')->value('new_values')['activated'] ?? null)->toBe(1);

    // Impor ulang: kios dengan HP yang sudah ada dilewati, tidak ditimpa.
    $again = miUpload($this->owner, 'kios', miCustomers());
    $again = miProcess($this->owner, $again, ['current_password' => 'password']);
    expect(miCounts($again))->toBe([0, 0, 2, 3])
        ->and(Customer::query()->count())->toBe(2);
});

test('PA-70 impor kios tanpa izin verifikasi: "ya" tetap prospek + peringatan, tanpa password', function () {
    CustomerWorld::area('UDN');
    $buyer = $this->org->actor('purchasing');
    $buyer->givePermissionTo('customers.manage');

    $file = impXlsx([['Toko', 'Pemilik', 'No HP', 'Area', 'Jenis', 'Terverifikasi'], ['Kios A', 'Pak A', '0812-7777-8888', 'UDN', 'Kios', 'ya']], 'kios.xlsx');
    $batch = miUpload($buyer, 'kios', $file);
    $this->actingAs($buyer)->get("/impor/{$batch->id}/baris")->assertInertia(fn (Assert $page) => $page->where('can.requires_password', false));
    $batch = miProcess($buyer, $batch);

    expect(miCounts($batch))->toBe([1, 0, 0, 0])
        ->and(Customer::query()->sole()->status)->toBe(CustomerStatus::Prospect)
        ->and(ImportRow::query()->where('import_batch_id', $batch->id)->sole()->data['warnings'][0] ?? '')->toContain('prospek');
});

test('BR-170 impor harga ke versi draf (dibuat bila belum ada), angka "1.250.000"; versi terbit tidak tersentuh; impor ulang = 0 perubahan', function () {
    $product = tap((new Product)->forceFill([
        'sku' => 'INS-009', 'name' => 'Produk Harga', 'product_category_id' => $this->category->id,
        'base_unit_id' => ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('code', 'bottle')->value('id'),
        'tracks_batch' => true, 'tracks_expiry' => true, 'tracks_serial' => false, 'requires_registration' => true,
        'is_restricted' => false, 'is_sellable' => true, 'is_purchasable' => true, 'status' => 'active',
    ]))->save();
    (new ProductUnit)->forceFill(['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'factor' => '1', 'is_base' => true, 'is_default_sales' => true, 'is_default_purchase' => true, 'is_active' => true])->save();
    $list = tap((new PriceList)->forceFill(['code' => 'GROSIR', 'name' => 'Grosir', 'kind' => 'tier', 'is_active' => true]))->save();
    $published = tap((new PriceListVersion)->forceFill(['price_list_id' => $list->id, 'version_no' => 1, 'effective_from' => now()->subDay(), 'status' => 'published']))->save();

    $file = fn () => impXlsx([
        ['Daftar Harga', 'Kode Produk', 'Satuan', 'Min Qty', 'Harga'],
        ['grosir', 'ins-009', 'Botol', '', '1.250.000'],
        ['GROSIR', 'INS-009', 'Botol', '12', '1.200.000'],
        ['GROSIR', 'INS-009', 'Dus', '', '1.000'],
    ], 'harga.xlsx');

    $batch = miProcess($this->owner, miUpload($this->owner, 'harga', $file()));
    $draft = PriceListVersion::query()->where('price_list_id', $list->id)->where('status', 'draft')->sole();
    expect(miCounts($batch))->toBe([2, 0, 0, 1])
        ->and(miErrors($batch))->toBe(['4 unit'])
        ->and(PriceListItem::query()->where('price_list_version_id', $draft->id)->orderBy('min_qty')->pluck('price')->all())->toBe(['1250000.00', '1200000.00'])
        ->and(PriceListItem::query()->where('price_list_version_id', $published->id)->count())->toBe(0);

    $again = miProcess($this->owner, miUpload($this->owner, 'harga', $file()));
    expect(miCounts($again))->toBe([0, 0, 2, 1])
        ->and(PriceListVersion::query()->where('price_list_id', $list->id)->count())->toBe(2);
});

test('BR-170 impor stok minimum: maks < min & lokasi di luar scope ditolak; impor ulang = 0 perubahan', function () {
    $mine = StockWorld::stockLocation($this->org->gudang, 'GD-UJI');
    StockWorld::stockLocation($this->org->toko, 'TK-UJI', 'store');
    miProcess($this->owner, miUpload($this->owner, 'produk', miProducts()));
    $head = $this->org->actor('kepala_gudang', $this->org->employee($this->org->gudang));
    $head->givePermissionTo(['master_imports.manage', 'stock_locations.manage']);

    $file = fn () => impXlsx([
        ['Gudang', 'SKU', 'Min', 'Maks'],
        ['gd-uji', 'INS-001', '24', '240'],
        ['GD-UJI', 'INS-002', '1.000', ''],
        ['GD-UJI', 'INS-002', '5', ''],
        ['TK-UJI', 'INS-001', '1', '2'],
        ['GD-UJI', 'NOPE', '1', ''],
    ], 'stok.xlsx');
    $bad = impXlsx([['Gudang', 'SKU', 'Min', 'Maks'], ['GD-UJI', 'INS-001', '10', '5']], 'stok2.xlsx');

    $batch = miProcess($head, miUpload($head, 'stok-minimum', $file()));
    expect(miCounts($batch))->toBe([2, 0, 0, 3])
        ->and(miErrors($batch))->toContain('5 stock_location', '6 sku')
        ->and(ProductStockLevel::query()->where('stock_location_id', $mine->id)->count())->toBe(2)
        ->and(miErrors(miUpload($head, 'stok-minimum', $bad)))->toBe(['2 max']);

    expect(miCounts(miProcess($head, miUpload($head, 'stok-minimum', $file()))))->toBe([0, 0, 2, 3]);
});

test('06 PA-70 izin: tanpa master_imports.manage atau izin modul → 403; riwayat hanya tipe yang boleh; menu Impor Master', function () {
    $clerk = $this->org->actor('staf_gudang');
    $this->actingAs($clerk)->get('/impor/produk/baru')->assertForbidden();
    $this->actingAs($clerk)->get('/impor')->assertForbidden();

    $buyer = $this->org->actor('purchasing'); // master_imports.manage + products.manage, tanpa customers.manage
    $this->actingAs($buyer)->get('/impor/produk/baru')->assertOk();
    $this->actingAs($buyer)->get('/impor/kios/baru')->assertForbidden();
    $buyer->givePermissionTo('customers.manage');
    $this->actingAs($buyer)->get('/impor/kios/baru')->assertOk();
    // Izin modul tanpa izin payung master_imports.manage → 403.
    $sales = $this->org->actor('supervisor');
    $sales->givePermissionTo('customers.manage');
    $this->actingAs($sales)->get('/impor/kios/baru')->assertForbidden();
    $batch = miUpload($this->owner, 'produk', miProducts());

    $hrd = $this->org->actor('hrd'); // employees.import saja
    $this->actingAs($hrd)->get('/impor')->assertInertia(fn (Assert $page) => $page->where('importTypes', fn ($types) => collect($types)->pluck('key')->all() === ['employees']));
    $this->actingAs($hrd)->get("/impor/{$batch->id}/baris")->assertForbidden();

    $this->actingAs($this->owner)->get('/impor?filter[type][]=products')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.type_label', 'Produk'));
});
