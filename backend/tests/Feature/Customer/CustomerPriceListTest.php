<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customer\Models\Customer;
use App\Domain\Pricing\Enums\PriceListKind;
use App\Domain\Pricing\Models\PriceList;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CustomerWorld;

/*
 * Susulan 15 §4.6/§7.3: daftar harga khusus kios (`customers.price_list_id`, BR-168 urutan 2).
 * Keputusan Mas 17 Nov 2026 (PA-61): hanya daftar harga jenis `tier` yang bisa dipilih.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Jakarta'));
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, SequenceSeeder::class]);
    $this->useRealPermissions();
    CustomerWorld::regions();
    $this->apj = User::factory()->withTwoFactor()->create()->assignRole('admin_penjualan');
    $this->area = CustomerWorld::area('AR-KDS', ['name' => 'Kudus']);
    $this->grosir = priceListOf('TR-GROSIR', 'Grosir', PriceListKind::Tier);
    $this->areaList = priceListOf('AR-KUDUS', 'Harga Kudus', PriceListKind::Area, ['sales_area_id' => $this->area->id]);
    $this->default = priceListOf('DEFAULT', 'Harga umum', PriceListKind::Default);
});

/** @param array<string, mixed> $extra */
function priceListOf(string $code, string $name, PriceListKind $kind, array $extra = []): PriceList
{
    $list = new PriceList;
    $list->forceFill(['code' => $code, 'name' => $name, 'kind' => $kind, 'is_active' => true, ...$extra])->save();

    return $list;
}

test('BR-168 PA-61 kios baru dengan daftar harga tier tersimpan & diaudit; jenis area/default ditolak', function () {
    $this->actingAs($this->apj)->post('/pelanggan', CustomerWorld::payload($this->area, ['price_list_id' => $this->grosir->id]))
        ->assertSessionHasNoErrors();
    $customer = Customer::query()->sole();
    expect($customer->price_list_id)->toBe($this->grosir->id)
        ->and(AuditLog::query()->where('event', 'customer.created')->sole()->new_values)->toMatchArray(['price_list_id' => $this->grosir->id]);

    foreach ([$this->areaList, $this->default] as $notTier) {
        $this->actingAs($this->apj)->put("/pelanggan/{$customer->id}", CustomerWorld::payload($this->area, ['price_list_id' => $notTier->id]))
            ->assertSessionHasErrors('price_list_id');
    }
});

test('BR-168 PA-61 ubah: kosongkan → null; daftar tier nonaktif ditolak kecuali nilai lama kios itu', function () {
    $customer = CustomerWorld::customer($this->area, 'Toko Satu');
    $customer->forceFill(['price_list_id' => $this->grosir->id])->save();
    $this->grosir->forceFill(['is_active' => false])->save();

    // Nilai lama yang kini nonaktif tetap sah (kios bisa disimpan tanpa dipaksa ganti).
    $this->actingAs($this->apj)->put("/pelanggan/{$customer->id}", CustomerWorld::payload($this->area, ['price_list_id' => $this->grosir->id]))
        ->assertSessionHasNoErrors();

    $other = CustomerWorld::customer($this->area, 'Toko Dua');
    $this->actingAs($this->apj)->put("/pelanggan/{$other->id}", CustomerWorld::payload($this->area, ['price_list_id' => $this->grosir->id]))
        ->assertSessionHasErrors('price_list_id');

    $this->actingAs($this->apj)->put("/pelanggan/{$customer->id}", CustomerWorld::payload($this->area, ['price_list_id' => null]))
        ->assertSessionHasNoErrors();
    expect($customer->fresh()->price_list_id)->toBeNull();
});

test('15 §7.3 form ubah membawa price_list_id + label; daftar kios bisa difilter daftar harga (hanya tier)', function () {
    $withList = CustomerWorld::customer($this->area, 'Toko Grosir');
    $withList->forceFill(['price_list_id' => $this->grosir->id])->save();
    CustomerWorld::customer($this->area, 'Toko Biasa');

    $this->actingAs($this->apj)->get("/pelanggan/{$withList->id}/ubah")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('customers/form')
            ->where('customer.price_list_id', $this->grosir->id)
            ->where('priceListLabel.value', $this->grosir->id));

    $rows = $this->actingAs($this->apj)->get('/pelanggan?filter[price_list_id]='.$this->grosir->id)->assertOk()->viewData('page')['props']['rows'];
    expect(collect($rows)->pluck('store_name')->all())->toBe(['Toko Grosir']);

    // ID daftar harga non-tier di filter diabaikan (RelationFilter validIds), bukan galat.
    $all = $this->actingAs($this->apj)->get('/pelanggan?filter[price_list_id]='.$this->areaList->id)->assertOk()->viewData('page')['props']['rows'];
    expect($all)->toHaveCount(2);
});

test('PA-61 lookup price_lists ?kind=tier hanya daftar harga tier aktif; kind tak dikenal → 422', function () {
    $this->actingAs($this->apj)->getJson('/lookup/price_lists?kind=tier')->assertOk()
        ->assertJsonCount(1)->assertJsonPath('0.value', $this->grosir->id);
    $this->actingAs($this->apj)->getJson('/lookup/price_lists?kind=salah')->assertUnprocessable();
});
