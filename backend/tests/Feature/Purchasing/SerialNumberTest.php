<?php

use App\Domain\Approval\Actions\DecideApprovalStep;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Inventory\Enums\SerialInputMethod;
use App\Domain\Inventory\Enums\SerialStatus;
use App\Domain\Inventory\Models\SerialNumber;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Purchasing\Actions\SavePurchaseOrder;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EmployeeWorld;
use Tests\Support\StockWorld;

/*
 * T-080 — nomor seri saat penerimaan (BR-144, SEC-109, UAT-A12) dan daftar nomor seri
 * (`admin.serials.index`, 15 §7.3). Data fiktif; sprayer = produk alat `tracks_serial`.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 03:00:00', 'UTC'));
    Notification::fake();
    Storage::fake('local');
    $this->org = EmployeeWorld::make($this);
    $this->seed(ApprovalRuleSeeder::class);
    $this->useRealPermissions();

    $this->clerk = $this->org->actor('staf_gudang', $this->org->employee());
    $this->clerk2 = $this->org->actor('staf_gudang', $this->org->employee());
    $this->owner = $this->org->actor('owner');
    $this->warehouse = StockWorld::stockLocation($this->org->gudang, 'GD-UJI');
    $this->store = StockWorld::stockLocation($this->org->toko, 'TK-UJI', 'store');
    $this->supplier = tap((new Supplier)->forceFill(['code' => 'SUP-1', 'name' => 'UD Alat Uji', 'payment_term_days' => 0, 'is_active' => true]))->save();

    $category = ProductCategory::query()->create([
        'code' => 'ALT', 'name' => 'Alat', 'kind' => 'tool', 'requires_batch' => false, 'requires_expiry' => false,
        'requires_serial' => true, 'requires_registration' => false, 'default_bin_zone' => 'general', 'sort_order' => 1, 'is_active' => true,
    ]);
    $unit = ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('code', 'unit')->value('id');
    $this->sprayer = tap((new Product)->forceFill([
        'sku' => 'ALT-01', 'name' => 'Sprayer Uji 16 L', 'product_category_id' => $category->id, 'base_unit_id' => $unit,
        'tracks_batch' => false, 'tracks_expiry' => false, 'tracks_serial' => true, 'requires_registration' => false,
        'is_restricted' => false, 'is_sellable' => true, 'is_purchasable' => true, 'status' => 'active',
    ]))->save();
    $this->sprayerUnit = tap((new ProductUnit)->forceFill(['product_id' => $this->sprayer->id, 'unit_id' => $unit, 'factor' => '1', 'is_base' => true, 'is_default_sales' => true, 'is_default_purchase' => true, 'is_active' => true]))->save();
});

function serialPo(string $qty = '5', ?int $locationId = null): PurchaseOrder
{
    $po = app(SavePurchaseOrder::class)->handle(null, [
        'supplier_id' => test()->supplier->id, 'stock_location_id' => $locationId ?? test()->warehouse->id,
        'order_date' => '2026-11-16', 'expected_date' => null, 'payment_term_days' => null, 'note' => null,
    ], [['product_unit_id' => test()->sprayerUnit->id, 'qty' => $qty, 'unit_price' => '650000', 'discount_pct' => '0', 'note' => null]], test()->owner->id);
    $po->forceFill(['status' => PurchaseOrderStatus::Approved])->save();

    return $po;
}

function serialGr(PurchaseOrder $po, ?User $actor = null, string $qty = '5'): GoodsReceipt
{
    test()->actingAs($actor ?? test()->clerk)->post('/penerimaan', [
        'purchase_order_id' => $po->id, 'received_at' => '2026-11-17T09:00', 'supplier_delivery_no' => 'SJ-ALT',
        'lines' => [['purchase_order_line_id' => $po->lines()->value('id'), 'qty_received' => $qty]],
    ])->assertSessionHasNoErrors();

    return GoodsReceipt::query()->latest('id')->firstOrFail();
}

function putSerials(GoodsReceipt $gr, array $data, ?User $actor = null)
{
    $line = $gr->lines()->firstOrFail();

    return test()->actingAs($actor ?? test()->clerk)->put("/penerimaan/{$gr->id}/baris/{$line->id}/seri", $data);
}

test('BR-144 jumlah nomor seri ≠ qty → SERIAL_COUNT_MISMATCH saat periksa', function () {
    $gr = serialGr(serialPo());
    putSerials($gr, ['serials' => ['SN-1', 'SN-2'], 'method' => 'scan'])->assertSessionHasNoErrors();

    $this->actingAs($this->clerk)->postJson("/penerimaan/{$gr->id}/periksa")
        ->assertStatus(422)->assertJsonPath('error.code', 'SERIAL_COUNT_MISMATCH')->assertJsonPath('error.details.lines.0.serials', 5);
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::Draft);
});

test('BR-144 duplikat dalam kiriman & yang sudah tercatat → SERIAL_DUPLICATE; normalisasi huruf besar tanpa spasi', function () {
    $gr = serialGr(serialPo());
    $line = $gr->lines()->first();
    $this->actingAs($this->clerk)->putJson("/penerimaan/{$gr->id}/baris/{$line->id}/seri", ['serials' => ['sn 01', 'SN01'], 'method' => 'scan'])
        ->assertStatus(422)->assertJsonPath('error.code', 'SERIAL_DUPLICATE');

    putSerials($gr, ['serials_text' => "sn-01\n SN-02 ,sn-03", 'method' => 'scan'])->assertSessionHasNoErrors();
    expect(SerialNumber::query()->orderBy('serial_no')->pluck('serial_no')->all())->toBe(['SN-01', 'SN-02', 'SN-03']);

    // GR lain untuk produk sama tidak boleh memakai nomor yang sudah ada.
    $other = serialGr(serialPo());
    $otherLine = $other->lines()->first();
    $this->actingAs($this->clerk)->putJson("/penerimaan/{$other->id}/baris/{$otherLine->id}/seri", ['serials' => ['SN-02'], 'method' => 'scan'])
        ->assertStatus(422)->assertJsonPath('error.code', 'SERIAL_DUPLICATE')->assertJsonPath('error.details.serials', ['SN-02']);

    // Mengirim ulang daftar menggantikan set baris: SN-03 dihapus, SN-04 ditambah.
    putSerials($gr, ['serials' => ['SN-01', 'SN-02', 'SN-04'], 'method' => 'scan'])->assertSessionHasNoErrors();
    expect(SerialNumber::query()->where('goods_receipt_line_id', $line->id)->orderBy('serial_no')->pluck('serial_no')->all())->toBe(['SN-01', 'SN-02', 'SN-04']);
});

test('SEC-109 input manual wajib foto label; foto disimpan privat', function () {
    $gr = serialGr(serialPo('1'), qty: '1');
    putSerials($gr, ['serials' => ['MAN-1'], 'method' => 'manual'])->assertSessionHasErrors('photo');
    expect(SerialNumber::query()->count())->toBe(0);

    putSerials($gr, ['serials' => ['MAN-1'], 'method' => 'manual', 'photo' => UploadedFile::fake()->image('label.jpg', 800, 600)])->assertSessionHasNoErrors();
    $serial = SerialNumber::query()->sole();
    expect($serial->input_method)->toBe(SerialInputMethod::Manual)
        ->and($serial->label_photo_path)->toStartWith('serial-labels/');
    Storage::disk('local')->assertExists($serial->label_photo_path);
});

test('BR-144 UAT-A12 5 sprayer diterima → 5 nomor seri in_stock dengan batch; daftar nomor seri hanya yang sudah diposting', function () {
    $gr = serialGr(serialPo());
    putSerials($gr, ['serials' => ['SPR-001', 'SPR-002', 'SPR-003', 'SPR-004', 'SPR-005'], 'method' => 'scan'])->assertSessionHasNoErrors();

    // Belum diposting: tidak tampil di daftar.
    $this->actingAs($this->owner)->get('/nomor-seri')->assertInertia(fn (Assert $page) => $page->component('serials/index')->has('rows', 0));

    $this->actingAs($this->clerk)->post("/penerimaan/{$gr->id}/periksa")->assertSessionHasNoErrors();
    $this->actingAs($this->clerk2)->post("/penerimaan/{$gr->id}/posting")->assertSessionHasNoErrors();

    $batchId = $gr->lines()->first()->stock_batch_id;
    expect(SerialNumber::query()->where('status', SerialStatus::InStock)->where('stock_batch_id', $batchId)->count())->toBe(5);
    // Sesudah posting nomor seri tidak bisa diubah lewat penerimaan.
    putSerials($gr, ['serials' => [], 'method' => 'scan'])->assertForbidden();

    $this->actingAs($this->owner)->get('/nomor-seri?q=SPR-003')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.serial_no', 'SPR-003')->where('rows.0.stock_location', $this->warehouse->name));
    $this->actingAs($this->owner)->get('/nomor-seri?q=ALT-01')->assertInertia(fn (Assert $page) => $page->has('rows', 5));
    $this->actingAs($this->owner)->get("/nomor-seri?filter[stock_location_id]={$this->store->id}")->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    $this->actingAs($this->owner)->get('/nomor-seri?filter[status][]=sold')->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    $this->actingAs($this->owner)->get('/nomor-seri?sort=serial_no')->assertInertia(fn (Assert $page) => $page->where('rows.0.serial_no', 'SPR-001'));
});

test('SEC-104 ganti nomor seri setelah diperiksa → kembali draf; posting ditolak sampai diperiksa ulang', function () {
    $gr = serialGr(serialPo('2'), qty: '2');
    putSerials($gr, ['serials' => ['SPR-A', 'SPR-B'], 'method' => 'scan'])->assertSessionHasNoErrors();
    $this->actingAs($this->clerk)->post("/penerimaan/{$gr->id}/periksa")->assertSessionHasNoErrors();
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::Checked);

    // Kirim ulang daftar yang sama: tidak ada perubahan, hasil periksa tetap berlaku.
    putSerials($gr, ['serials' => ['SPR-A', 'SPR-B'], 'method' => 'scan'], $this->clerk2)->assertSessionHasNoErrors();
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::Checked);

    // Pemosting mengganti satu nomor → kembali draf, pemeriksa dikosongkan, posting ditolak.
    putSerials($gr, ['serials' => ['SPR-A', 'SPR-Z'], 'method' => 'scan'], $this->clerk2)->assertSessionHasNoErrors();
    $gr->refresh();
    expect($gr->status)->toBe(GoodsReceiptStatus::Draft)
        ->and($gr->checked_by)->toBeNull()
        ->and($gr->checked_at)->toBeNull();
    $this->actingAs($this->clerk2)->postJson("/penerimaan/{$gr->id}/posting")->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');
    expect(StockMovement::query()->count())->toBe(0);

    // Diperiksa ulang oleh staf 2 → posting harus oleh orang lain (staf 1).
    $this->actingAs($this->clerk2)->post("/penerimaan/{$gr->id}/periksa")->assertSessionHasNoErrors();
    $this->actingAs($this->clerk2)->postJson("/penerimaan/{$gr->id}/posting")->assertStatus(403)->assertJsonPath('error.code', 'RECEIPT_SAME_PERSON');
    $this->actingAs($this->clerk)->post("/penerimaan/{$gr->id}/posting")->assertSessionHasNoErrors();
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::Posted);
});

test('06 §5 daftar nomor seri ber-scope Stok: staf gudang lain lokasi tidak melihat nomor seri gudang ini', function () {
    $gr = serialGr(serialPo('1'), qty: '1');
    putSerials($gr, ['serials' => ['SPR-X'], 'method' => 'scan']);
    $this->actingAs($this->clerk)->post("/penerimaan/{$gr->id}/periksa");
    $this->actingAs($this->clerk2)->post("/penerimaan/{$gr->id}/posting");

    $storeClerk = $this->org->actor('staf_gudang', $this->org->employee($this->org->toko));
    $this->actingAs($storeClerk)->get('/nomor-seri')->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    $this->actingAs($this->clerk)->get('/nomor-seri')->assertInertia(fn (Assert $page) => $page->has('rows', 1));
});

test('PA-65 nomor seri GR yang ditolak approval dihapus sehingga bisa dipakai GR lain', function () {
    $headEmployee = $this->org->employee();
    $this->org->gudang->update(['head_employee_id' => $headEmployee->id]);
    $head = $this->org->actor('kepala_gudang', $headEmployee);
    // Sprayer ber-ED dengan batas sisa umur 12 bulan: ED 3 bulan → approval.
    $this->sprayer->forceFill(['tracks_batch' => true, 'tracks_expiry' => true, 'min_shelf_life_months' => 12])->save();

    $po = serialPo('1');
    $this->actingAs($this->clerk)->post('/penerimaan', [
        'purchase_order_id' => $po->id, 'received_at' => '2026-11-17T09:00', 'supplier_delivery_no' => 'SJ-ALT',
        'lines' => [['purchase_order_line_id' => $po->lines()->value('id'), 'qty_received' => '1', 'batch_no' => 'SPR-B1', 'expiry_date' => '2027-02-17']],
    ])->assertSessionHasNoErrors();
    $gr = GoodsReceipt::query()->latest('id')->firstOrFail();
    putSerials($gr, ['serials' => ['SPR-TOLAK'], 'method' => 'scan'])->assertSessionHasNoErrors();
    $this->actingAs($this->clerk)->post("/penerimaan/{$gr->id}/periksa")->assertSessionHasNoErrors();
    $this->actingAs($this->clerk2)->post("/penerimaan/{$gr->id}/posting")->assertSessionHasNoErrors();
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::PendingApproval);

    $request = ApprovalRequest::query()->findOrFail($gr->fresh()->approval_request_id);
    app(DecideApprovalStep::class)->reject($head, $request->id, $request->current_step, 'Terlalu dekat ED', true);

    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::Rejected)
        ->and(SerialNumber::query()->count())->toBe(0)
        ->and(SerialNumber::normalize(' spr tolak '))->toBe('SPRTOLAK');
    // Produk tanpa pelacakan seri menolak input nomor seri.
    $this->sprayer->forceFill(['tracks_serial' => false, 'tracks_expiry' => false, 'tracks_batch' => false])->save();
    putSerials(serialGr(serialPo('1'), qty: '1'), ['serials' => ['X'], 'method' => 'scan'])->assertSessionHasErrors('serials');
});
