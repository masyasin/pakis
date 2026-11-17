<?php

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Pricing\Actions\EndPromotions;
use App\Domain\Pricing\Actions\SavePromotion;
use App\Domain\Pricing\Enums\PromotionStatus;
use App\Domain\Pricing\Models\PriceList;
use App\Domain\Pricing\Models\PriceListItem;
use App\Domain\Pricing\Models\PriceListVersion;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Purchasing\Models\Principal;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Api\ApiError;
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
 * T-076 — promo (FR-A20, BR-169, PA-60, 15 §4.7/§5). Data dummy. Waktu dibekukan
 * 16 Nov 2026 03.00 UTC (10.00 WIB).
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, ApprovalRuleSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 03:00:00', 'UTC'));
    $this->owner = User::factory()->withTwoFactor()->create()->assignRole('owner'); // promotions.manage + approve
    $this->manager = User::factory()->withTwoFactor()->create()->givePermissionTo(['promotions.view', 'promotions.manage']);

    $this->category = ProductCategory::query()->create([
        'code' => 'INS', 'name' => 'Insektisida', 'kind' => 'insecticide', 'requires_batch' => true, 'requires_expiry' => true,
        'requires_serial' => false, 'requires_registration' => true, 'default_bin_zone' => 'pesticide', 'sort_order' => 1, 'is_active' => true,
    ]);
    $unit = ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('code', 'bottle')->value('id');
    $this->product = tap((new Product)->forceFill([
        'sku' => 'P-01', 'name' => 'Produk Uji', 'product_category_id' => $this->category->id, 'base_unit_id' => $unit,
        'tracks_batch' => true, 'tracks_expiry' => true, 'tracks_serial' => false, 'requires_registration' => true,
        'is_restricted' => false, 'is_sellable' => true, 'is_purchasable' => true, 'status' => 'active',
    ]))->save();
    $this->unit = tap((new ProductUnit)->forceFill(['product_id' => $this->product->id, 'unit_id' => $unit, 'factor' => '1', 'is_base' => true,
        'is_default_sales' => true, 'is_default_purchase' => true, 'is_active' => true]))->save();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array<string, mixed> */
function promoPayload(array $overrides = []): array
{
    return [
        'code' => 'promo-uji', 'name' => 'Promo Uji', 'type' => 'percent_discount',
        'starts_at' => '2026-11-16T00:00', 'ends_at' => '2026-12-01T00:00', 'priority' => 100, 'is_stackable' => false,
        'channels' => ['office', 'sales'], 'budget_amount' => null, 'funded_by' => 'company', 'principal_id' => null,
        'principal_share_pct' => null, 'description' => null,
        'targets' => [['type' => 'product', 'id' => test()->product->id]],
        'scopes' => [['type' => 'all', 'id' => null]],
        'tiers' => [['min_qty_base' => '1', 'discount_pct' => '10']],
        ...$overrides,
    ];
}

function makePromo(User $actor, array $overrides = []): Promotion
{
    test()->actingAs($actor)->post('/promo', promoPayload($overrides))->assertSessionHasNoErrors();

    return Promotion::query()->latest('id')->firstOrFail();
}

test('BR-169 dana prinsipal wajib principal_id; dana bersama wajib porsi 0–100', function () {
    $principal = Principal::query()->create(['code' => 'AGRO', 'name' => 'PT Agro Demo', 'is_active' => true]);

    $this->actingAs($this->manager)->post('/promo', promoPayload(['funded_by' => 'principal']))->assertSessionHasErrors('principal_id');
    $this->actingAs($this->manager)->post('/promo', promoPayload(['funded_by' => 'shared', 'principal_id' => $principal->id]))->assertSessionHasErrors('principal_share_pct');
    $this->actingAs($this->manager)->post('/promo', promoPayload(['funded_by' => 'shared', 'principal_id' => $principal->id, 'principal_share_pct' => '100']))->assertSessionHasErrors('principal_share_pct');

    $promo = makePromo($this->manager, ['funded_by' => 'shared', 'principal_id' => $principal->id, 'principal_share_pct' => '40']);
    expect($promo->code)->toBe('PROMO-UJI')->and($promo->principal_share_pct)->toBe('40.0000')->and($promo->status)->toBe(PromotionStatus::Draft);

    // CHECK DB sebagai lapis kedua.
    expect(fn () => DB::table('promotions')->where('id', $promo->id)->update(['funded_by' => 'principal', 'principal_id' => null]))
        ->toThrow(QueryException::class);
});

test('FR-A20 validasi aturan per tipe promo', function () {
    $post = fn (array $o) => $this->actingAs($this->manager)->post('/promo', promoPayload(['code' => 'V-'.uniqid(), ...$o]));

    $post(['tiers' => [['min_qty_base' => '1', 'discount_pct' => '100']]])->assertSessionHasErrors('tiers.0.discount_pct');
    $post(['type' => 'tiered_discount'])->assertSessionHasErrors('tiers');
    $post(['type' => 'buy_x_get_y', 'tiers' => [['min_qty_base' => '10']]])->assertSessionHasErrors('tiers.0.free_product_id');
    $post(['type' => 'bundle_price', 'tiers' => [['min_qty_base' => '3']]])->assertSessionHasErrors('tiers.0.bundle_price');
    $post(['scopes' => [['type' => 'all', 'id' => null], ['type' => 'customer', 'id' => 1]]])->assertSessionHasErrors('scopes');
    $post(['targets' => [['type' => 'product', 'id' => 999999]]])->assertSessionHasErrors('targets.0.id');
    $post(['ends_at' => '2026-11-15T00:00'])->assertSessionHasErrors('ends_at');
    $post(['type' => 'buy_x_get_y', 'tiers' => [['min_qty_base' => '10', 'free_product_id' => $this->product->id, 'free_qty_base' => '1']]])->assertSessionHasNoErrors();
});

test('PA-60 pemegang promotions.approve langsung mengaktifkan; pengguna lain lewat approval; ditolak kembali draf', function () {
    $own = makePromo($this->owner, ['code' => 'OWN']);
    $this->actingAs($this->owner)->post("/promo/{$own->id}/ajukan")->assertSessionHasNoErrors();
    expect($own->fresh()->status)->toBe(PromotionStatus::Active);

    $promo = makePromo($this->manager);
    $this->actingAs($this->manager)->post("/promo/{$promo->id}/ajukan")->assertSessionHasNoErrors();
    $request = ApprovalRequest::query()->where('document_type', 'promotion_activate')->sole();
    expect($promo->fresh()->status)->toBe(PromotionStatus::PendingApproval)->and($promo->fresh()->approval_request_id)->toBe($request->id);

    app(ApprovalEngine::class)->decide($this->owner, $request->id, 1, false, 'Anggaran terlalu besar', true);
    expect($promo->fresh()->status)->toBe(PromotionStatus::Draft);

    $this->actingAs($this->manager)->post("/promo/{$promo->id}/ajukan");
    $request2 = ApprovalRequest::query()->where('document_type', 'promotion_activate')->latest('id')->first();
    app(ApprovalEngine::class)->decide($this->owner, $request2->id, 1, true, null, true);
    expect($promo->fresh()->status)->toBe(PromotionStatus::Active);
});

test('BR-169 promo aktif tidak bisa diubah selain diakhiri', function () {
    $promo = makePromo($this->owner);
    $this->actingAs($this->owner)->post("/promo/{$promo->id}/ajukan");

    $this->actingAs($this->owner)->put("/promo/{$promo->id}", promoPayload(['name' => 'Diubah']))->assertForbidden();
    expect(fn () => app(SavePromotion::class)->handle($promo->fresh(), promoPayload(['code' => 'PROMO-UJI']), $this->owner->id))
        ->toThrow(ApiError::class);

    $this->actingAs($this->owner)->post("/promo/{$promo->id}/akhiri")->assertSessionHasNoErrors();
    expect($promo->fresh()->status)->toBe(PromotionStatus::Ended);
    $this->actingAs($this->owner)->post("/promo/{$promo->id}/akhiri")->assertForbidden();
});

test('BR-169 promo lewat ends_at → ended oleh job (idempoten); anggaran habis juga berakhir', function () {
    $short = makePromo($this->owner, ['code' => 'SINGKAT', 'ends_at' => '2026-11-17T00:00']);
    $budget = makePromo($this->owner, ['code' => 'ANGGARAN', 'budget_amount' => '100000']);
    foreach ([$short, $budget] as $p) {
        $this->actingAs($this->owner)->post("/promo/{$p->id}/ajukan");
    }

    expect(app(EndPromotions::class)->handle())->toBe(0);
    DB::table('promotions')->where('id', $budget->id)->update(['budget_used' => '100000']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 17:00:00', 'UTC')); // 17 Nov 00.00 WIB

    expect(app(EndPromotions::class)->handle())->toBe(2)
        ->and(app(EndPromotions::class)->handle())->toBe(0)
        ->and($short->fresh()->status)->toBe(PromotionStatus::Ended)
        ->and($budget->fresh()->status)->toBe(PromotionStatus::Ended);
});

test('BR-169 simulasi menghitung harga + promo dan tidak menulis apa pun ke DB', function () {
    $list = tap((new PriceList)->forceFill(['code' => 'DEFAULT', 'name' => 'Bawaan', 'kind' => 'default', 'is_active' => true]))->save();
    $version = tap((new PriceListVersion)->forceFill(['price_list_id' => $list->id, 'version_no' => 1, 'effective_from' => '2026-11-01 00:00:00', 'status' => 'draft']))->save();
    (new PriceListItem)->forceFill(['price_list_version_id' => $version->id, 'product_id' => $this->product->id, 'product_unit_id' => $this->unit->id, 'min_qty' => '1', 'price' => '10000'])->save();
    $version->forceFill(['status' => 'published'])->save();

    $promo = makePromo($this->owner, ['type' => 'buy_x_get_y', 'tiers' => [['min_qty_base' => '10', 'free_product_id' => $this->product->id, 'free_qty_base' => '1']]]);
    $this->actingAs($this->owner)->post("/promo/{$promo->id}/ajukan");
    makePromo($this->owner, ['code' => 'DISKON5', 'is_stackable' => true, 'priority' => 200, 'tiers' => [['min_qty_base' => '1', 'discount_pct' => '5']]]);
    $this->actingAs($this->owner)->post('/promo/'.Promotion::query()->where('code', 'DISKON5')->value('id').'/ajukan');

    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $response = $this->actingAs($this->owner)->postJson('/promo/simulasi', [
        'channel' => 'office', 'lines' => [['product_unit_id' => $this->unit->id, 'qty' => '20']],
    ])->assertOk();

    // Sesi web dapat menulis tabel `sessions`; selain itu tidak boleh ada tulisan.
    expect(array_values(array_filter($writes, fn (string $sql) => ! str_contains($sql, '`sessions`'))))->toBe([])
        ->and($response->json('lines.0.unit_price'))->toBe('10000.00')
        ->and($response->json('lines.0.total'))->toBe('200000.00')
        ->and($response->json('lines.0.applied'))->toBe(['PROMO-UJI'])
        ->and($response->json('bonus.0.qty_base'))->toBe('2.000');
});

test('06 izin & 15 §7.3 daftar promo: view saja tidak bisa mengubah; filter status & berlaku pada', function () {
    makePromo($this->owner, ['code' => 'NOV']);
    $des = makePromo($this->owner, ['code' => 'DES', 'starts_at' => '2026-12-05T00:00', 'ends_at' => '2026-12-31T00:00']);
    $this->actingAs($this->owner)->post("/promo/{$des->id}/ajukan");

    $codes = fn (string $q) => collect($this->actingAs($this->owner)->get("/promo{$q}")->assertOk()->inertiaProps('rows'))->pluck('code')->all();
    expect($codes(''))->toBe(['DES', 'NOV'])
        ->and($codes('?filter[status][]=active'))->toBe(['DES'])
        ->and($codes('?filter[active_on][from]=2026-11-20&filter[active_on][to]=2026-11-25'))->toBe(['NOV'])
        ->and($codes('?q=NO'))->toBe(['NOV']);

    $viewer = User::factory()->withTwoFactor()->create()->givePermissionTo('promotions.view');
    $this->actingAs($viewer)->get('/promo')->assertOk();
    $this->actingAs($viewer)->post('/promo', promoPayload(['code' => 'X1']))->assertForbidden();
    $this->actingAs($viewer)->get("/promo/{$des->id}/ubah")->assertOk();
    $this->actingAs(User::factory()->withTwoFactor()->create())->get('/promo')->assertForbidden();
});

test('06 §5 cakupan promo "kios tertentu" hanya untuk kios dalam scope data pengguna (IDOR)', function () {
    $this->seed(SequenceSeeder::class);
    CustomerWorld::regions();
    $customer = CustomerWorld::customer(CustomerWorld::area('KDS'), 'Kios Scope Demo');
    $payload = ['code' => 'KIOS-SCOPE', 'scopes' => [['type' => 'customer', 'id' => $customer->id]]];

    // Pengguna tanpa scope kios: kios dianggap tidak ada.
    $this->actingAs($this->manager)->post('/promo', promoPayload($payload))->assertSessionHasErrors('scopes.0.id');
    // Owner (scope semua) boleh.
    $this->actingAs($this->owner)->post('/promo', promoPayload($payload))->assertSessionHasNoErrors();
    $promo = Promotion::query()->where('code', 'KIOS-SCOPE')->sole();

    // Label nama kios tidak bocor ke pengguna di luar scope.
    $this->actingAs($this->manager)->get("/promo/{$promo->id}/ubah")->assertOk()
        ->assertInertia(fn ($p) => $p->where('labels.customers', []));
    $this->actingAs($this->owner)->get("/promo/{$promo->id}/ubah")->assertOk()
        ->assertInertia(fn ($p) => $p->has('labels.customers', 1));
});
