<?php

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Customer\Models\Customer;
use App\Domain\Inventory\Actions\SaveStockLocation;
use App\Domain\Organization\Models\Location;
use App\Domain\Pricing\Actions\ActivatePriceVersions;
use App\Domain\Pricing\Actions\UpsertPriceItems;
use App\Domain\Pricing\Enums\PriceListKind;
use App\Domain\Pricing\Enums\PriceVersionStatus;
use App\Domain\Pricing\Models\PriceList;
use App\Domain\Pricing\Models\PriceListItem;
use App\Domain\Pricing\Models\PriceListVersion;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\CustomerWorld;

/*
 * T-075 — daftar harga berversi (FR-A19, BR-168, SEC-106, ADR-108, 15 §4.7/§5). Data dummy.
 * Waktu dibekukan 16 Nov 2026 03.00 UTC (10.00 WIB).
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, SequenceSeeder::class, ApprovalRuleSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 03:00:00', 'UTC'));
    $this->owner = User::factory()->withTwoFactor()->create()->assignRole('owner'); // prices.manage + prices.publish

    $category = ProductCategory::query()->create([
        'code' => 'INS', 'name' => 'Insektisida', 'kind' => 'insecticide', 'requires_batch' => true, 'requires_expiry' => true,
        'requires_serial' => false, 'requires_registration' => true, 'default_bin_zone' => 'pesticide', 'sort_order' => 1, 'is_active' => true,
    ]);
    $this->product = pricingProduct($category, 'P-01');
    $this->bottle = ProductUnit::query()->where('product_id', $this->product->id)->sole();
    $this->box = tap((new ProductUnit)->forceFill([
        'product_id' => $this->product->id, 'unit_id' => ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('code', 'box')->value('id'),
        'factor' => '24', 'is_base' => false, 'is_default_sales' => false, 'is_default_purchase' => true, 'is_active' => true,
    ]))->save();
    $this->other = pricingProduct($category, 'P-02');
    $this->otherUnit = ProductUnit::query()->where('product_id', $this->other->id)->sole();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function pricingProduct(ProductCategory $category, string $sku): Product
{
    $unit = ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('code', 'bottle')->value('id');
    $p = (new Product)->forceFill([
        'sku' => $sku, 'name' => "Produk {$sku}", 'product_category_id' => $category->id, 'base_unit_id' => $unit,
        'tracks_batch' => true, 'tracks_expiry' => true, 'tracks_serial' => false, 'requires_registration' => true,
        'is_restricted' => false, 'is_sellable' => true, 'is_purchasable' => true, 'status' => 'active',
    ]);
    $p->save();
    (new ProductUnit)->forceFill(['product_id' => $p->id, 'unit_id' => $unit, 'factor' => '1', 'is_base' => true, 'is_default_sales' => true, 'is_default_purchase' => true, 'is_active' => true])->save();

    return $p;
}

function priceList(string $code, string $kind = 'tier', array $attrs = []): PriceList
{
    $l = (new PriceList)->forceFill(['code' => $code, 'name' => "List {$code}", 'kind' => $kind, 'is_active' => true, ...$attrs]);
    $l->save();

    return $l;
}

/**
 * Versi terbit langsung (lewat jalur DB) berisi item [unit, min_qty, harga].
 *
 * @param  list<array{0: ProductUnit, 1: string, 2: string}>  $items
 */
function publishedVersion(PriceList $list, array $items, string $from = '2026-11-01 00:00:00', ?string $to = null, int $no = 1): PriceListVersion
{
    $v = (new PriceListVersion)->forceFill(['price_list_id' => $list->id, 'version_no' => $no, 'effective_from' => $from, 'effective_to' => $to, 'status' => 'draft']);
    $v->save();
    foreach ($items as [$unit, $min, $price]) {
        (new PriceListItem)->forceFill(['price_list_version_id' => $v->id, 'product_id' => $unit->product_id, 'product_unit_id' => $unit->id, 'min_qty' => $min, 'price' => $price])->save();
    }
    $v->forceFill(['status' => 'published', 'published_at' => now()])->save();

    return $v;
}

function resolvePrice(?Customer $customer, ProductUnit $unit, string $qty = '1', ?CarbonImmutable $at = null, string $channel = 'office'): string|ErrorCode
{
    try {
        return (string) app(PriceResolver::class)->resolve($customer, $unit->product, $unit, Qty::of($qty), $at, $channel)->price;
    } catch (ApiError $e) {
        return $e->errorCode;
    }
}

test('SEC-106 ubah item versi published ditolak Action (PRICE_VERSION_LOCKED 409) dan trigger DB', function () {
    $version = publishedVersion(priceList('DEFAULT', 'default'), [[$this->bottle, '1', '10000']]);

    $this->actingAs($this->owner)->putJson("/daftar-harga/versi/{$version->id}/item", ['items' => [
        ['product_unit_id' => $this->bottle->id, 'min_qty' => '1', 'price' => '1'],
    ]])->assertForbidden();

    expect(fn () => app(UpsertPriceItems::class)->handle($version, [['product_unit_id' => $this->bottle->id, 'min_qty' => '1', 'price' => '1']], [], $this->owner->id))
        ->toThrow(fn (ApiError $e) => expect($e->errorCode)->toBe(ErrorCode::PRICE_VERSION_LOCKED));

    // Lapis kedua: jalur DB langsung pun ditolak trigger.
    expect(fn () => DB::table('price_list_items')->where('price_list_version_id', $version->id)->update(['price' => '1']))->toThrow(QueryException::class);
    expect(fn () => DB::table('price_list_items')->where('price_list_version_id', $version->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('price_list_items')->insert(['price_list_version_id' => $version->id, 'product_id' => $this->other->id, 'product_unit_id' => $this->otherUnit->id, 'min_qty' => '1', 'price' => '5']))->toThrow(QueryException::class);
    expect(PriceListItem::query()->sole()->price)->toBe('10000.00');
});

test('BR-168 resolusi 4 tingkat (store→kios→area→default) dengan fallback per produk', function () {
    CustomerWorld::regions();
    $area = CustomerWorld::area('KDS');
    $otherArea = CustomerWorld::area('JPR');
    $tier = priceList('GROSIR');
    $areaList = priceList('AREA-KDS', 'area', ['sales_area_id' => $area->id]);
    $default = priceList('DEFAULT', 'default');

    publishedVersion($tier, [[$this->bottle, '1', '9000']]);                                          // hanya produk 1
    publishedVersion($areaList, [[$this->bottle, '1', '9500'], [$this->otherUnit, '1', '4500']]);     // produk 1 & 2
    publishedVersion($default, [[$this->bottle, '1', '10000'], [$this->otherUnit, '1', '5000']]);

    $tierCustomer = tap(CustomerWorld::customer($area, 'Kios Tier Demo'))->forceFill(['price_list_id' => $tier->id])->save();
    $tierCustomer = Customer::query()->where('store_name', 'Kios Tier Demo')->sole();
    $plainCustomer = CustomerWorld::customer($area, 'Kios Area Demo');
    $farCustomer = CustomerWorld::customer($otherArea, 'Kios Luar Demo');

    expect(resolvePrice($tierCustomer, $this->bottle))->toBe('9000.00')        // list kios
        ->and(resolvePrice($tierCustomer, $this->otherUnit))->toBe('4500.00')  // tidak ada di list kios → area
        ->and(resolvePrice($plainCustomer, $this->bottle))->toBe('9500.00')    // tanpa list kios → area
        ->and(resolvePrice($farCustomer, $this->bottle))->toBe('10000.00')     // area tanpa list → default
        ->and(resolvePrice(null, $this->otherUnit))->toBe('5000.00');          // tanpa kios → default

    // List nonaktif dilewati; tidak ada harga sama sekali → PRICE_NOT_FOUND.
    $tier->forceFill(['is_active' => false])->save();
    expect(resolvePrice($tierCustomer, $this->bottle))->toBe('9500.00')
        ->and(resolvePrice($farCustomer, $this->box))->toBe(ErrorCode::PRICE_NOT_FOUND);
});

test('BR-168 list toko hanya dipakai channel pos', function () {
    $store = app(SaveStockLocation::class)->handle(null, [
        'code' => 'TK-UJI', 'name' => 'Toko Uji', 'type' => 'store',
        'location_id' => Location::query()->create(['code' => 'LOK-UJI', 'name' => 'Lokasi Uji', 'location_type_id' => ReferenceItem::query()->forGroup(ReferenceGroup::LocationType)->value('id'), 'is_active' => true])->id, 'is_sellable' => true, 'is_active' => true,
    ], null);
    publishedVersion(priceList('TOKO', 'store', ['stock_location_id' => $store->id]), [[$this->bottle, '1', '11000']]);
    publishedVersion(priceList('DEFAULT', 'default'), [[$this->bottle, '1', '10000']]);

    $resolve = fn (string $channel) => (string) app(PriceResolver::class)->resolve(null, $this->product, $this->bottle, Qty::of('1'), null, $channel, $store)->price;
    expect($resolve('pos'))->toBe('11000.00')->and($resolve('office'))->toBe('10000.00');
});

test('BR-168 min_qty 1/10/50: qty 49 memakai tier 10; satuan berbeda tidak tercampur', function () {
    $version = publishedVersion(priceList('DEFAULT', 'default'), [
        [$this->bottle, '1', '10000'], [$this->bottle, '10', '9500'], [$this->bottle, '50', '9000'], [$this->box, '1', '220000'],
    ]);

    $resolved = app(PriceResolver::class)->resolve(null, $this->product, $this->bottle, Qty::of('49'));
    expect((string) $resolved->price)->toBe('9500.00')
        ->and((string) $resolved->minQty)->toBe('10.000')
        ->and($resolved->priceListVersionId)->toBe($version->id)
        ->and($resolved->sourceListKind)->toBe(PriceListKind::Default)
        ->and(resolvePrice(null, $this->bottle, '50'))->toBe('9000.00')
        ->and(resolvePrice(null, $this->bottle, '9'))->toBe('10000.00')
        ->and(resolvePrice(null, $this->box, '60'))->toBe('220000.00');
});

test('BR-168 versi berlaku besok tidak dipakai hari ini; effective_to versi lama terisi; job menandai superseded tepat waktu', function () {
    $list = priceList('DEFAULT', 'default');
    $old = publishedVersion($list, [[$this->bottle, '1', '10000']]);

    // Draf versi 2 berlaku besok 00.00 WIB, diterbitkan pemegang prices.publish.
    $this->actingAs($this->owner)->post("/daftar-harga/{$list->id}/versi", ['mode' => 'copy_percent', 'source_version_id' => $old->id, 'percent' => '10', 'effective_from' => '2026-11-17T00:00'])
        ->assertSessionHasNoErrors();
    $new = PriceListVersion::query()->where('version_no', 2)->sole();
    expect($new->effective_from->toIso8601String())->toBe('2026-11-16T17:00:00+00:00');
    $this->actingAs($this->owner)->post("/daftar-harga/versi/{$new->id}/ajukan")->assertSessionHasNoErrors();

    expect($new->fresh()->status)->toBe(PriceVersionStatus::Published)
        ->and($old->fresh()->effective_to->toIso8601String())->toBe('2026-11-16T17:00:00+00:00')
        ->and($old->fresh()->status)->toBe(PriceVersionStatus::Published)
        ->and(resolvePrice(null, $this->bottle))->toBe('10000.00')
        ->and(app(ActivatePriceVersions::class)->handle())->toBe(0);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 17:00:00', 'UTC'));
    expect(resolvePrice(null, $this->bottle))->toBe('11000.00')
        ->and(app(ActivatePriceVersions::class)->handle())->toBe(1)
        ->and(app(ActivatePriceVersions::class)->handle())->toBe(0)
        ->and($old->fresh()->status)->toBe(PriceVersionStatus::Superseded);
});

test('BR-168 tidak berlaku surut kecuali setting + prices.publish + alasan', function () {
    $list = priceList('DEFAULT', 'default');
    $this->actingAs($this->owner)->post("/daftar-harga/{$list->id}/versi", ['mode' => 'empty', 'effective_from' => '2026-11-15T08:00'])->assertSessionHasNoErrors();
    $version = PriceListVersion::query()->sole();
    $this->actingAs($this->owner)->put("/daftar-harga/versi/{$version->id}/item", ['items' => [['product_unit_id' => $this->bottle->id, 'min_qty' => '1', 'price' => '12.500']]])->assertSessionHasNoErrors();
    expect(PriceListItem::query()->sole()->price)->toBe('12500.00');

    $this->actingAs($this->owner)->post("/daftar-harga/versi/{$version->id}/ajukan", ['backdate_reason' => 'Koreksi'])->assertSessionHasErrors('effective_from');

    setSetting('pricing.allow_backdated_publish', true);
    $this->actingAs($this->owner)->post("/daftar-harga/versi/{$version->id}/ajukan")->assertSessionHasErrors('effective_from');
    $this->actingAs($this->owner)->post("/daftar-harga/versi/{$version->id}/ajukan", ['backdate_reason' => 'Koreksi salah ketik harga'])->assertSessionHasNoErrors();
    expect($version->fresh()->status)->toBe(PriceVersionStatus::Published)->and($version->fresh()->note)->toContain('Koreksi salah ketik');
});

test('BR-153 penerbitan: prices.publish langsung terbit; pengguna lain → approval; disetujui terbit, ditolak rejected', function () {
    $list = priceList('DEFAULT', 'default');
    $manager = User::factory()->withTwoFactor()->create()->givePermissionTo(['prices.view', 'prices.manage']);
    $make = function () use ($list, $manager) {
        $this->actingAs($manager)->post("/daftar-harga/{$list->id}/versi", ['mode' => 'empty', 'effective_from' => '2026-12-01T00:00'])->assertSessionHasNoErrors();
        $v = PriceListVersion::query()->latest('id')->first();
        $this->actingAs($manager)->put("/daftar-harga/versi/{$v->id}/item", ['items' => [['product_unit_id' => $this->bottle->id, 'min_qty' => '1', 'price' => '10000']]])->assertSessionHasNoErrors();

        return $v;
    };

    // Draf kosong tidak bisa diajukan.
    $this->actingAs($manager)->post("/daftar-harga/{$list->id}/versi", ['mode' => 'empty', 'effective_from' => '2026-11-20T00:00']);
    $empty = PriceListVersion::query()->sole();
    $this->actingAs($manager)->post("/daftar-harga/versi/{$empty->id}/ajukan")->assertSessionHasErrors('version');
    $empty->forceFill(['status' => 'rejected'])->save();

    $first = $make();
    $this->actingAs($manager)->post("/daftar-harga/versi/{$first->id}/ajukan")->assertSessionHasNoErrors();
    $request = ApprovalRequest::query()->where('document_type', 'price_list_publish')->sole();
    expect($first->fresh()->status)->toBe(PriceVersionStatus::PendingApproval)
        ->and($first->fresh()->approval_request_id)->toBe($request->id);
    // Versi menunggu tidak bisa diubah lagi.
    $this->actingAs($manager)->put("/daftar-harga/versi/{$first->id}/item", ['items' => []])->assertForbidden();

    app(ApprovalEngine::class)->decide($this->owner, $request->id, 1, false, 'Harga terlalu rendah', true);
    expect($first->fresh()->status)->toBe(PriceVersionStatus::Rejected);

    $second = $make();
    $this->actingAs($manager)->post("/daftar-harga/versi/{$second->id}/ajukan");
    $request2 = ApprovalRequest::query()->where('document_type', 'price_list_publish')->latest('id')->first();
    app(ApprovalEngine::class)->decide($this->owner, $request2->id, 1, true, null, true);
    expect($second->fresh()->status)->toBe(PriceVersionStatus::Published)
        ->and($second->fresh()->published_by)->toBe($this->owner->id)
        // SEC-110: terbit harga tercatat audit (PaketAAuditTest).
        ->and(AuditLog::query()->where('event', 'price_version.published')->where('auditable_id', $second->id)->exists())->toBeTrue();
});

test('FR-A19 salin versi ±% dibulatkan pricing.rounding (ke atas)', function () {
    $list = priceList('DEFAULT', 'default');
    $old = publishedVersion($list, [[$this->bottle, '1', '10120'], [$this->bottle, '10', '9999.50']]);
    setSetting('pricing.rounding', '500');

    $this->actingAs($this->owner)->post("/daftar-harga/{$list->id}/versi", ['mode' => 'copy_percent', 'source_version_id' => $old->id, 'percent' => '-2,5', 'effective_from' => '2026-12-01T00:00'])
        ->assertSessionHasNoErrors();
    $prices = PriceListItem::query()->whereHas('version', fn ($q) => $q->where('version_no', 2))->orderBy('min_qty')->pluck('price')->all();
    // 10120 × 0,975 = 9867 → 10000; 9999,50 × 0,975 = 9749,51 → 10000 (kelipatan 500 ke atas).
    expect($prices)->toBe(['10000.00', '10000.00']);

    $this->actingAs($this->owner)->post("/daftar-harga/{$list->id}/versi", ['mode' => 'copy_percent', 'source_version_id' => $old->id, 'percent' => '-150', 'effective_from' => '2026-12-02T00:00'])
        ->assertSessionHasErrors('percent');
});

test('FR-A19 hanya satu daftar harga bawaan; jenis list tidak bisa diubah; izin view/manage', function () {
    $this->actingAs($this->owner)->post('/daftar-harga', ['code' => 'default', 'name' => 'Bawaan', 'kind' => 'default', 'is_active' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/daftar-harga', ['code' => 'DEF2', 'name' => 'Bawaan 2', 'kind' => 'default', 'is_active' => true])->assertSessionHasErrors('kind');
    $this->actingAs($this->owner)->post('/daftar-harga', ['code' => 'AREA1', 'name' => 'Area tanpa area', 'kind' => 'area', 'is_active' => true])->assertSessionHasErrors('sales_area_id');

    $list = PriceList::query()->sole();
    expect($list->code)->toBe('DEFAULT');
    $this->actingAs($this->owner)->put("/daftar-harga/{$list->id}", ['code' => 'DEFAULT', 'name' => 'Ubah', 'kind' => 'tier', 'is_active' => true])->assertSessionHasErrors('kind');

    $viewer = User::factory()->withTwoFactor()->create()->givePermissionTo('prices.view');
    $this->actingAs($viewer)->get('/daftar-harga')->assertOk();
    $this->actingAs($viewer)->post('/daftar-harga', ['code' => 'X', 'name' => 'X', 'kind' => 'tier', 'is_active' => true])->assertForbidden();
    $this->actingAs(User::factory()->withTwoFactor()->create())->get('/daftar-harga')->assertForbidden();
});

test('15 §7.3 daftar item versi: cari, filter kategori, sort produk, ekspor; versi lain tidak ikut', function () {
    $list = priceList('DEFAULT', 'default');
    $v1 = publishedVersion($list, [[$this->bottle, '1', '10000'], [$this->otherUnit, '1', '5000']]);
    publishedVersion(priceList('LAIN'), [[$this->bottle, '1', '1']]);

    $skus = fn (string $q) => collect($this->actingAs($this->owner)->get("/daftar-harga/versi/{$v1->id}/item{$q}")->assertOk()->inertiaProps('rows'))->pluck('sku')->all();
    expect($skus(''))->toBe(['P-01', 'P-02'])
        ->and($skus('?q=P-02'))->toBe(['P-02'])
        ->and($skus('?sort=-name'))->toBe(['P-02', 'P-01']);

    $this->actingAs($this->owner)->get("/daftar-harga/versi/{$v1->id}/item/ekspor")->assertOk()->assertDownload();
});

test('06 tab Harga produk hanya untuk pemegang prices.view (staf gudang tidak melihat harga)', function () {
    publishedVersion(priceList('DEFAULT', 'default'), [[$this->bottle, '1', '10000']]);

    $gudang = User::factory()->withTwoFactor()->create()->assignRole('staf_gudang'); // products.view tanpa prices.view
    $this->actingAs($gudang)->get("/produk/{$this->product->id}?tab=harga")->assertOk()
        ->assertInertia(fn ($p) => $p->where('prices', null));

    $this->actingAs($this->owner)->get("/produk/{$this->product->id}?tab=harga")->assertOk()
        ->assertInertia(fn ($p) => $p->has('prices', 1)->where('prices.0.price', '10000.00'));
});

test('BR-168 persetujuan yang datang setelah tanggal berlaku ditolak (tidak terbit surut); versi tetap menunggu', function () {
    $list = priceList('DEFAULT', 'default');
    $manager = User::factory()->withTwoFactor()->create()->givePermissionTo(['prices.view', 'prices.manage']);
    $this->actingAs($manager)->post("/daftar-harga/{$list->id}/versi", ['mode' => 'empty', 'effective_from' => '2026-11-18T00:00'])->assertSessionHasNoErrors();
    $version = PriceListVersion::query()->sole();
    $this->actingAs($manager)->put("/daftar-harga/versi/{$version->id}/item", ['items' => [['product_unit_id' => $this->bottle->id, 'min_qty' => '1', 'price' => '10000']]]);
    $this->actingAs($manager)->post("/daftar-harga/versi/{$version->id}/ajukan")->assertSessionHasNoErrors();
    $request = ApprovalRequest::query()->sole();

    // Penyetuju baru memutuskan dua hari kemudian.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-19 03:00:00', 'UTC'));
    expect(fn () => app(ApprovalEngine::class)->decide($this->owner, $request->id, 1, true, null, true))
        ->toThrow(fn (ApiError $e) => expect($e->errorCode)->toBe(ErrorCode::STATE_CONFLICT));
    expect($version->fresh()->status)->toBe(PriceVersionStatus::PendingApproval)
        ->and(resolvePrice(null, $this->bottle))->toBe(ErrorCode::PRICE_NOT_FOUND);
});
