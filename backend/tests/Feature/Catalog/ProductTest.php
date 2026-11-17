<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Actions\SaveProductUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBarcode;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\SequenceSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\StockWorld;

/*
 * T-070 — produk, satuan produk, barcode (FR-A07–A09, BR-150/151, 15 §4.4/§5). Izin
 * `products.view`/`products.manage` lewat Gate; matriks role diuji PaketAMatrixTest. Data dummy.
 */

beforeEach(function () {
    $this->seed([ReferenceSeeder::class, SequenceSeeder::class]);
    $this->user = User::factory()->create();
    Gate::define('products.view', fn () => true);
    Gate::define('products.manage', fn () => true);
    $this->category = ProductCategory::query()->create([
        'code' => 'INS', 'name' => 'Insektisida', 'kind' => 'insecticide',
        'requires_batch' => true, 'requires_expiry' => true, 'requires_serial' => false,
        'requires_registration' => true, 'default_bin_zone' => 'pesticide', 'sort_order' => 10, 'is_active' => true,
    ]);
});

function unitId(string $code): int
{
    return ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('code', $code)->value('id');
}

/** @return array<string, mixed> */
function productPayload(array $overrides = []): array
{
    return [
        'sku' => 'INS-001', 'name' => 'Insektisida Demo 500 ml', 'product_category_id' => test()->category->id,
        'brand' => null, 'active_ingredient' => 'Abamektin 18 g/l', 'formulation_id' => null, 'package_size' => '500 ml',
        'base_unit_id' => unitId('bottle'), 'description' => null, 'usage_note' => null,
        'tracks_batch' => true, 'tracks_expiry' => true, 'tracks_serial' => false, 'requires_registration' => true,
        'is_restricted' => false, 'restricted_note' => null, 'warranty_months' => null, 'min_shelf_life_months' => null,
        'is_sellable' => true, 'is_purchasable' => true, 'status' => 'active',
        ...$overrides,
    ];
}

function makeProduct(array $overrides = []): Product
{
    test()->actingAs(test()->user)->post('/produk', productPayload($overrides))->assertSessionHasNoErrors();

    return Product::query()->latest('id')->firstOrFail();
}

test('BR-150 produk baru: satuan dasar dibuat otomatis (faktor 1, default jual & beli) + audit product.created', function () {
    $product = makeProduct();

    $base = ProductUnit::query()->where('product_id', $product->id)->sole();
    expect($base->unit_id)->toBe(unitId('bottle'))
        ->and($base->factor)->toBe('1.000')
        ->and($base->is_base && $base->is_default_sales && $base->is_default_purchase)->toBeTrue()
        ->and(AuditLog::query()->where('event', 'product.created')->where('auditable_id', $product->id)->exists())->toBeTrue();
});

test('BR-151 SKU manual: huruf besar unik; mode auto memakai penomoran PRD-00001 dan menolak SKU kiriman', function () {
    makeProduct();
    $this->actingAs($this->user)->post('/produk', productPayload())->assertSessionHasErrors('sku');
    $this->actingAs($this->user)->post('/produk', productPayload(['sku' => 'ab']))->assertSessionHasErrors('sku');

    setSetting('product.sku_mode', 'auto');
    $this->actingAs($this->user)->post('/produk', productPayload(['sku' => 'X-1']))->assertSessionHasErrors('sku');
    $payload = productPayload();
    unset($payload['sku']);
    $this->actingAs($this->user)->post('/produk', $payload)->assertSessionHasNoErrors();
    expect(Product::query()->latest('id')->value('sku'))->toBe('PRD-00001');
});

test('BR-151 SKU tidak bisa diubah setelah dibuat', function () {
    $product = makeProduct();
    $this->actingAs($this->user)->put("/produk/{$product->id}", productPayload(['sku' => 'LAIN-01']))->assertSessionHasErrors('sku');
    $payload = productPayload(['name' => 'Nama baru']);
    unset($payload['sku']);
    $this->actingAs($this->user)->put("/produk/{$product->id}", $payload)->assertSessionHasNoErrors();
    expect($product->fresh()->sku)->toBe('INS-001')->and($product->fresh()->name)->toBe('Nama baru');
});

test('BR-151 melonggarkan penanda wajib kategori butuh alasan; alasan tercatat di audit product.flags_relaxed', function () {
    $this->actingAs($this->user)->post('/produk', productPayload(['tracks_batch' => false, 'tracks_expiry' => false]))
        ->assertSessionHasErrors('flag_reason');
    expect(Product::query()->count())->toBe(0);

    makeProduct(['tracks_batch' => false, 'tracks_expiry' => false, 'flag_reason' => 'Alat tanpa batch dari pabrik']);
    $log = AuditLog::query()->where('event', 'product.flags_relaxed')->sole();
    expect($log->new_values['reason'])->toBe('Alat tanpa batch dari pabrik');
});

test('BR-151 memperketat penanda tidak butuh alasan; lacak kedaluwarsa wajib lacak batch', function () {
    makeProduct(['tracks_serial' => true]);
    expect(Product::query()->sole()->tracks_serial)->toBeTrue();

    $this->actingAs($this->user)->post('/produk', productPayload(['sku' => 'INS-002', 'tracks_batch' => false, 'tracks_expiry' => true, 'flag_reason' => 'uji']))
        ->assertSessionHasErrors('tracks_expiry');
});

test('BR-150 satuan tambahan: faktor desimal koma diterima, satuan ganda ditolak, faktor nol ditolak', function () {
    $product = makeProduct();
    $url = "/produk/{$product->id}/satuan";
    $box = ['unit_id' => unitId('box'), 'factor' => '24', 'is_default_sales' => false, 'is_default_purchase' => false, 'is_active' => true];

    $this->actingAs($this->user)->post($url, $box)->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post($url, $box)->assertSessionHasErrors('unit_id');
    $this->actingAs($this->user)->post($url, [...$box, 'unit_id' => unitId('liter'), 'factor' => '0'])->assertSessionHasErrors('factor');
    $this->actingAs($this->user)->post($url, [...$box, 'unit_id' => unitId('liter'), 'factor' => '0,5'])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post($url, [...$box, 'unit_id' => unitId('ml'), 'factor' => '1.2345'])->assertSessionHasErrors('factor');

    expect(ProductUnit::query()->where('product_id', $product->id)->where('unit_id', unitId('liter'))->value('factor'))->toBe('0.500');
});

test('BR-150 maksimal satu default jual & satu default beli per produk; satuan dasar faktor 1 dan tidak bisa dinonaktifkan', function () {
    $product = makeProduct();
    $this->actingAs($this->user)->post("/produk/{$product->id}/satuan", [
        'unit_id' => unitId('box'), 'factor' => '24', 'is_default_sales' => true, 'is_default_purchase' => false, 'is_active' => true,
    ])->assertSessionHasNoErrors();

    $units = ProductUnit::query()->where('product_id', $product->id)->get()->keyBy('unit_id');
    expect($units[unitId('box')]->is_default_sales)->toBeTrue()
        ->and($units[unitId('bottle')]->is_default_sales)->toBeFalse()
        ->and($units[unitId('bottle')]->is_default_purchase)->toBeTrue();

    $base = $units[unitId('bottle')];
    $update = fn (array $data) => $this->actingAs($this->user)->put("/produk/{$product->id}/satuan/{$base->id}", [
        'factor' => '1', 'is_default_sales' => false, 'is_default_purchase' => true, 'is_active' => true, ...$data,
    ]);
    $update(['factor' => '2'])->assertSessionHasErrors('factor');
    $update(['is_active' => false])->assertSessionHasErrors('is_active');
});

test('BR-150 faktor satuan yang sudah dipakai dokumen terkunci (UNIT_FACTOR_LOCKED)', function () {
    $product = makeProduct();
    $url = "/produk/{$product->id}/satuan";
    $box = ['unit_id' => unitId('box'), 'factor' => '24', 'is_default_sales' => false, 'is_default_purchase' => false, 'is_active' => true];
    $this->actingAs($this->user)->post($url, $box)->assertSessionHasNoErrors();
    $unit = ProductUnit::query()->where('product_id', $product->id)->where('unit_id', unitId('box'))->sole();

    // Belum dipakai dokumen: faktor boleh diubah.
    $flags = ['is_default_sales' => false, 'is_default_purchase' => false, 'is_active' => true];
    $this->actingAs($this->user)->put("{$url}/{$unit->id}", [...$flags, 'factor' => '12'])->assertSessionHasNoErrors();

    // Dipakai satu baris PO (T-079) → faktor terkunci, flag lain tetap boleh.
    $supplier = tap((new Supplier)->forceFill(['code' => 'SUP-1', 'name' => 'CV Uji', 'payment_term_days' => 30, 'is_active' => true]))->save();
    $stock = StockWorld::stockLocation(StockWorld::location('GD-UJI'), 'GD-UJI');
    $po = tap((new PurchaseOrder)->forceFill([
        'number' => 'PO-UJI-1', 'supplier_id' => $supplier->id, 'stock_location_id' => $stock->id, 'order_date' => '2026-11-16',
        'payment_term_days' => 30, 'subtotal' => '0', 'discount_total' => '0', 'tax_total' => '0', 'grand_total' => '0', 'status' => 'draft',
    ]))->save();
    (new PurchaseOrderLine)->forceFill([
        'purchase_order_id' => $po->id, 'line_no' => 1, 'product_id' => $product->id, 'product_unit_id' => $unit->id,
        'qty' => '1', 'qty_base' => '12', 'unit_price' => '1000', 'discount_pct' => '0', 'line_total' => '1000',
    ])->save();

    expect(fn () => app(SaveProductUnit::class)->handle($product, $unit, [...$flags, 'factor' => '24'], $this->user->id))
        ->toThrow(fn (ApiError $e) => expect($e->errorCode)->toBe(ErrorCode::UNIT_FACTOR_LOCKED));
    expect($unit->fresh()->factor)->toBe('12.000');
});

test('FR-A09 barcode unik global dengan pesan pemilik; pencarian q=<barcode> menemukan produk persis', function () {
    $first = makeProduct();
    $second = makeProduct(['sku' => 'INS-002', 'name' => 'Produk Kedua']);
    $firstUnit = ProductUnit::query()->where('product_id', $first->id)->value('id');
    $secondUnit = ProductUnit::query()->where('product_id', $second->id)->value('id');

    $this->actingAs($this->user)->post("/produk/{$first->id}/barcode", ['product_unit_id' => $firstUnit, 'barcode' => '8991234567890'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post("/produk/{$second->id}/barcode", ['product_unit_id' => $secondUnit, 'barcode' => '8991234567890'])
        ->assertSessionHasErrors(['barcode' => 'Barcode ini sudah dipakai produk INS-001.']);
    // Satuan milik produk lain tidak boleh dipakai lewat URL produk ini.
    $this->actingAs($this->user)->post("/produk/{$first->id}/barcode", ['product_unit_id' => $secondUnit, 'barcode' => '8990000000001'])
        ->assertSessionHasErrors('product_unit_id');

    $this->actingAs($this->user)->get('/produk?q=8991234567890')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('products/index')->has('rows', 1)->where('rows.0.sku', 'INS-001'));
    // Sebagian barcode tidak cocok (persis), agar scan tidak memunculkan produk lain.
    $this->actingAs($this->user)->get('/produk?q=899123')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 0));

    expect(AuditLog::query()->where('event', 'product_barcode.added')->count())->toBe(1);
});

test('FR-A09 hapus barcode tercatat audit; barcode produk lain → 404', function () {
    $first = makeProduct();
    $second = makeProduct(['sku' => 'INS-002']);
    $unit = ProductUnit::query()->where('product_id', $first->id)->value('id');
    $this->actingAs($this->user)->post("/produk/{$first->id}/barcode", ['product_unit_id' => $unit, 'barcode' => '8991111111111']);
    $barcode = ProductBarcode::query()->sole();

    $this->actingAs($this->user)->delete("/produk/{$second->id}/barcode/{$barcode->id}")->assertNotFound();
    $this->actingAs($this->user)->delete("/produk/{$first->id}/barcode/{$barcode->id}")->assertRedirect();
    expect(ProductBarcode::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'product_barcode.removed')->count())->toBe(1);
});

test('15 §7.3 daftar produk: cari SKU/bahan aktif, filter status & terbatas, sort nama', function () {
    makeProduct(['name' => 'Bravo', 'active_ingredient' => 'Mankozeb 80%']);
    makeProduct(['sku' => 'FUN-001', 'name' => 'Alfa', 'is_restricted' => true, 'status' => 'inactive', 'active_ingredient' => 'Klorantraniliprol']);

    $rows = fn (string $query) => $this->actingAs($this->user)->get("/produk?{$query}")->assertOk();
    $rows('q=FUN')->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.sku', 'FUN-001'));
    $rows('q=mankozeb')->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.name', 'Bravo'));
    $rows('filter[status]=inactive')->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.status', 'inactive'));
    $rows('filter[restricted]=1')->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.is_restricted', true));
    $rows('sort=name')->assertInertia(fn (Assert $p) => $p->where('rows.0.name', 'Alfa')->where('rows.1.name', 'Bravo'));
    $rows('sort=-name')->assertInertia(fn (Assert $p) => $p->where('rows.0.name', 'Bravo'));
});

test('FR-A08 izin: tanpa products.view ditolak; tanpa products.manage hanya bisa melihat', function () {
    $product = makeProduct();

    Gate::define('products.manage', fn () => false);
    $this->actingAs($this->user)->get("/produk/{$product->id}")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('products/show')->where('can.manage', false)->has('units', 1));
    $this->actingAs($this->user)->get('/produk/baru')->assertForbidden();
    $this->actingAs($this->user)->post('/produk', productPayload(['sku' => 'INS-009']))->assertForbidden();
    $this->actingAs($this->user)->post("/produk/{$product->id}/satuan", [])->assertForbidden();

    Gate::define('products.view', fn () => false);
    $this->actingAs($this->user)->get('/produk')->assertForbidden();
    $this->actingAs($this->user)->get("/produk/{$product->id}")->assertForbidden();
    $this->actingAs($this->user)->get('/produk/ekspor')->assertForbidden();
});

test('SEC-109 foto produk disimpan di disk publik setelah encode ulang; berkas palsu ditolak', function () {
    Storage::fake('public');
    $product = makeProduct(['photo' => UploadedFile::fake()->image('produk.jpg', 1200, 900)]);
    expect($product->photo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($product->photo_path);
    [$w] = getimagesizefromstring(Storage::disk('public')->get($product->photo_path));
    expect($w)->toBeLessThanOrEqual(400);

    $fake = UploadedFile::fake()->createWithContent('palsu.jpg', '<html><script>alert(1)</script></html>');
    $this->actingAs($this->user)->post('/produk', productPayload(['sku' => 'INS-002', 'photo' => $fake]))->assertSessionHasErrors('photo');
    expect(Product::query()->count())->toBe(1);
});

test('T-006 ekspor produk memakai ExportableListing (data kecil langsung diunduh)', function () {
    makeProduct();
    $this->actingAs($this->user)->get('/produk/ekspor')->assertOk()->assertDownload();
});
