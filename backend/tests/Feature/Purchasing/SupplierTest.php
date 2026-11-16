<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductRegistration;
use App\Domain\Purchasing\Models\Principal;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\SequenceSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * T-072 — prinsipal & supplier (FR-A11, SEC-108, 15 §4.5/§5). Izin lewat Gate; matriks role diuji
 * PaketAMatrixTest. Nomor rekening dummy berawalan 999 (bukan rekening sungguhan).
 */

const DUMMY_ACCOUNT = '9990012345678';

beforeEach(function () {
    $this->seed([ReferenceSeeder::class, SequenceSeeder::class]);
    $this->user = User::factory()->create();
    Gate::define('suppliers.view', fn () => true);
    Gate::define('suppliers.manage', fn () => true);
    Gate::define('products.view', fn () => true);
    $this->principal = Principal::query()->create(['code' => 'AGRO-DEMO', 'name' => 'PT Agro Demo', 'is_active' => true]);
});

function supplierPayload(array $overrides = []): array
{
    return [
        'name' => 'CV Supplier Demo', 'principal_id' => null, 'tax_id' => null, 'contact_name' => 'Bagian Penjualan',
        'phone' => '0291-440000', 'email' => null, 'address' => null, 'village_code' => null, 'payment_term_days' => 30,
        'bank_name' => 'BRI', 'bank_account_no' => DUMMY_ACCOUNT, 'bank_account_name' => 'CV Supplier Demo', 'is_active' => true,
        ...$overrides,
    ];
}

function demoProduct(string $sku): Product
{
    $category = ProductCategory::query()->firstOrCreate(['code' => 'INS'], [
        'name' => 'Insektisida', 'kind' => 'insecticide', 'requires_batch' => true, 'requires_expiry' => true,
        'requires_serial' => false, 'requires_registration' => true, 'default_bin_zone' => 'pesticide', 'sort_order' => 1, 'is_active' => true,
    ]);
    $product = (new Product)->forceFill([
        'sku' => $sku, 'name' => "Produk {$sku}", 'product_category_id' => $category->id,
        'base_unit_id' => ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('code', 'bottle')->value('id'),
        'tracks_batch' => true, 'tracks_expiry' => true, 'tracks_serial' => false, 'requires_registration' => true,
        'is_restricted' => false, 'is_sellable' => true, 'is_purchasable' => true, 'status' => 'active',
    ]);
    $product->save();

    return $product;
}

function makeSupplier(array $overrides = []): Supplier
{
    test()->actingAs(test()->user)->post('/supplier', supplierPayload($overrides))->assertSessionHasNoErrors();

    return Supplier::query()->latest('id')->firstOrFail();
}

test('FR-A11 kode supplier otomatis SUP-0001 dari penomoran; kode kiriman ditolak', function () {
    $supplier = makeSupplier();
    expect($supplier->code)->toBe('SUP-0001')
        ->and(makeSupplier(['name' => 'CV Kedua'])->code)->toBe('SUP-0002');
    $this->actingAs($this->user)->post('/supplier', supplierPayload(['code' => 'X-1']))->assertSessionHasErrors('code');
});

test('SEC-108 rekening tersimpan terenkripsi; tidak pernah plaintext di props, audit, maupun DB', function () {
    $supplier = makeSupplier(['bank_account_no' => '999-001 2345.678']);

    $raw = DB::table('suppliers')->where('id', $supplier->id)->value('bank_account_no_enc');
    expect($raw)->not->toContain(DUMMY_ACCOUNT)
        ->and(Crypt::decryptString($raw))->toBe(DUMMY_ACCOUNT)
        ->and($supplier->maskedAccount())->toBe('••••5678');

    foreach (["/supplier/{$supplier->id}", "/supplier/{$supplier->id}/ubah", '/supplier'] as $url) {
        $json = json_encode($this->actingAs($this->user)->get($url)->assertOk()->inertiaProps());
        expect($json)->not->toContain(DUMMY_ACCOUNT)->not->toContain('bank_account_no_enc');
    }
    $this->actingAs($this->user)->get("/supplier/{$supplier->id}")
        ->assertInertia(fn (Assert $p) => $p->where('supplier.bank_account_masked', '••••5678'));

    // Ganti rekening: audit hanya penanda "diubah".
    $this->actingAs($this->user)->put("/supplier/{$supplier->id}", supplierPayload(['bank_account_no' => '9990099998888']))->assertSessionHasNoErrors();
    $audit = AuditLog::query()->whereIn('event', ['supplier.created', 'supplier.updated'])->get();
    expect(json_encode($audit->map->only(['old_values', 'new_values'])))->not->toContain(DUMMY_ACCOUNT)->not->toContain('9990099998888')
        ->and($audit->firstWhere('event', 'supplier.updated')->new_values['bank_account'])->toBe('diubah');
});

test('SEC-108 rekening kosong saat ubah = tetap; hapus hanya lewat clear_bank_account', function () {
    $supplier = makeSupplier();
    $this->actingAs($this->user)->put("/supplier/{$supplier->id}", supplierPayload(['bank_account_no' => '', 'name' => 'Nama Baru']))->assertSessionHasNoErrors();
    expect($supplier->fresh()->bank_account_no_enc)->toBe(DUMMY_ACCOUNT);

    $this->actingAs($this->user)->put("/supplier/{$supplier->id}", supplierPayload(['bank_account_no' => '', 'clear_bank_account' => true]))->assertSessionHasNoErrors();
    expect($supplier->fresh()->hasBankAccount())->toBeFalse();
});

test('SEC-108 reveal-account hanya suppliers.manage, no-store, tercatat sensitive_data_viewed + audit', function () {
    $supplier = makeSupplier();

    $this->actingAs($this->user)->postJson("/supplier/{$supplier->id}/rekening")
        ->assertOk()->assertJson(['account' => DUMMY_ACCOUNT])
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    expect(DB::table('security_events')->where('event', 'sensitive_data_viewed')->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'supplier.account_viewed')->count())->toBe(1);

    Gate::define('suppliers.manage', fn () => false);
    $this->actingAs($this->user)->postJson("/supplier/{$supplier->id}/rekening")->assertForbidden();
    $this->actingAs($this->user)->get("/supplier/{$supplier->id}")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('can.manage', false));
});

test('FR-A11 produk dipasok tersinkron; harga beli terakhir dari penerimaan tetap dipertahankan', function () {
    $a = demoProduct('P-A');
    $b = demoProduct('P-B');
    $supplier = makeSupplier(['products' => [
        ['product_id' => $a->id, 'supplier_sku' => 'SUP-A', 'lead_time_days' => 7, 'is_preferred' => true],
        ['product_id' => $b->id, 'supplier_sku' => null, 'lead_time_days' => null, 'is_preferred' => false],
    ]]);
    expect(DB::table('supplier_products')->where('supplier_id', $supplier->id)->count())->toBe(2);

    // Seolah penerimaan barang (T-080) sudah mencatat harga.
    DB::table('supplier_products')->where('supplier_id', $supplier->id)->where('product_id', $a->id)->update(['last_price' => '125000.00']);

    $this->actingAs($this->user)->put("/supplier/{$supplier->id}", supplierPayload(['products' => [
        ['product_id' => $a->id, 'supplier_sku' => 'SUP-A2', 'lead_time_days' => 5, 'is_preferred' => true],
    ]]))->assertSessionHasNoErrors();

    $rows = DB::table('supplier_products')->where('supplier_id', $supplier->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->supplier_sku)->toBe('SUP-A2')
        ->and((int) $rows[0]->lead_time_days)->toBe(5)
        ->and($rows[0]->last_price)->toBe('125000.00');

    $this->actingAs($this->user)->post('/supplier', supplierPayload(['products' => [
        ['product_id' => $a->id, 'is_preferred' => false], ['product_id' => $a->id, 'is_preferred' => false],
    ]]))->assertSessionHasErrors('products.1.product_id');
});

test('FR-A11 supplier nonaktif tidak muncul di lookup suppliers (PO); tetap terlihat di daftar dengan filter', function () {
    makeSupplier(['name' => 'Aktif Demo']);
    makeSupplier(['name' => 'Nonaktif Demo', 'is_active' => false]);

    $labels = collect($this->actingAs($this->user)->getJson('/lookup/suppliers')->assertOk()->json())->pluck('label')->all();
    expect($labels)->toBe(['Aktif Demo']);

    $names = fn (string $q) => collect($this->actingAs($this->user)->get("/supplier{$q}")->assertOk()->inertiaProps('rows'))->pluck('name')->all();
    expect($names(''))->toBe(['Aktif Demo', 'Nonaktif Demo'])
        ->and($names('?filter[is_active]=0'))->toBe(['Nonaktif Demo']);
});

test('15 §7.3 daftar supplier: cari kode/nama/NPWP, filter prinsipal, sort nama', function () {
    makeSupplier(['name' => 'Bravo Demo', 'principal_id' => $this->principal->id, 'tax_id' => '01.234.567.8-901.000']);
    makeSupplier(['name' => 'Alfa Demo']);

    $names = fn (string $q) => collect($this->actingAs($this->user)->get("/supplier{$q}")->assertOk()->inertiaProps('rows'))->pluck('name')->all();
    expect($names('?q=SUP-0002'))->toBe(['Alfa Demo'])
        ->and($names('?q=01.234'))->toBe(['Bravo Demo'])
        ->and($names("?filter[principal_id]={$this->principal->id}"))->toBe(['Bravo Demo'])
        ->and($names('?sort=-name'))->toBe(['Bravo Demo', 'Alfa Demo']);
});

test('06 izin: suppliers.view tanpa .manage tidak bisa tambah/ubah; tanpa .view ditolak; id tak ada 404', function () {
    $supplier = makeSupplier();
    Gate::define('suppliers.manage', fn () => false);
    $this->actingAs($this->user)->get('/supplier/baru')->assertForbidden();
    $this->actingAs($this->user)->post('/supplier', supplierPayload())->assertForbidden();
    $this->actingAs($this->user)->put("/supplier/{$supplier->id}", supplierPayload())->assertForbidden();

    Gate::define('suppliers.view', fn () => false);
    $this->actingAs($this->user)->get('/supplier')->assertForbidden();
    Gate::define('suppliers.view', fn () => true);
    $this->actingAs($this->user)->get('/supplier/999999')->assertNotFound();
});

test('FR-A11 produk menyimpan prinsipal; daftar produk & izin edar bisa disaring per prinsipal', function () {
    $a = demoProduct('P-A');
    $b = demoProduct('P-B');
    $a->forceFill(['principal_id' => $this->principal->id])->save();

    $skus = collect($this->actingAs($this->user)->get("/produk?filter[principal_id]={$this->principal->id}")->assertOk()->inertiaProps('rows'))->pluck('sku')->all();
    expect($skus)->toBe(['P-A']);

    foreach ([$a, $b] as $i => $p) {
        (new ProductRegistration)->forceFill(['product_id' => $p->id, 'registration_no' => "RI-{$i}", 'valid_until' => now()->addDays(10)->toDateString(), 'status' => 'active'])->save();
    }
    $nos = collect($this->actingAs($this->user)->get("/izin-edar?filter[principal_id]={$this->principal->id}")->assertOk()->inertiaProps('rows'))->pluck('registration_no')->all();
    expect($nos)->toBe(['RI-0']);
    expect($b->id)->toBeInt();
});
