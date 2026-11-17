<?php

use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\Models\AccountingEvent;
use App\Domain\Approval\Actions\DecideApprovalStep;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameLine;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Database\Seeders\TransitStockLocationSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\EmployeeWorld;
use Tests\Support\LedgerWorld;
use Tests\Support\StockWorld;

/*
 * T-083 — stock opname (FR-A28, BR-162, SEC-103, 15 §6.6/§11.4). Data fiktif. Waktu dibekukan
 * 17 Nov 2026 03.00 UTC (10.00 WIB). Role & izin Spatie sungguhan: dua staf gudang menghitung &
 * menghitung ulang, kepala gudang menyetujui < Rp 1 jt, Owner langkah kedua ≥ Rp 1 jt.
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 03:00:00', 'UTC'));
    Notification::fake();
    $this->org = EmployeeWorld::make($this);
    $this->seed([TransitStockLocationSeeder::class, ApprovalRuleSeeder::class]);
    $this->useRealPermissions();
    setSetting('approval.reauth_amount', '999999999999');

    $other = StockWorld::location('L-LAIN');
    $this->gd = StockWorld::stockLocation($this->org->gudang, 'GD-A');
    $this->far = StockWorld::stockLocation($other, 'GD-C');
    $this->bin = LedgerWorld::bin($this->gd, 'R-01');

    $this->counter = $this->org->actor('staf_gudang', $this->org->employee($this->org->gudang));
    $this->counter2 = $this->org->actor('staf_gudang', $this->org->employee($this->org->gudang));
    $this->head = $this->org->actor('kepala_gudang', $this->org->employee($this->org->gudang));
    $this->owner = $this->org->actor('owner');
    $this->outsider = $this->org->actor('staf_gudang', $this->org->employee($other));

    $this->product = LedgerWorld::product('OPN-01');
    $this->batch = LedgerWorld::receive($this->gd, $this->product, '50', '1000', new BatchKey('B-001', '2027-12-31', $this->bin->id));
});

function opnCreate(array $override = [], ?User $actor = null): StockOpname
{
    test()->actingAs($actor ?? test()->counter)->post('/opname', [
        'stock_location_id' => test()->gd->id, 'scope' => 'full', 'scope_ids' => null, 'blind_count' => true, 'note' => null, ...$override,
    ])->assertSessionHasNoErrors();

    return StockOpname::query()->latest('id')->firstOrFail();
}

function opnStarted(array $override = []): StockOpname
{
    $o = opnCreate($override);
    test()->actingAs(test()->counter)->post("/opname/{$o->id}/mulai")->assertSessionHasNoErrors();

    return $o->fresh();
}

function opnLine(StockOpname $o): StockOpnameLine
{
    return $o->lines()->where('stock_batch_id', test()->batch->id)->sole();
}

function opnPut(StockOpname $o, StockOpnameLine $l, array $data, ?User $actor = null): TestResponse
{
    return test()->actingAs($actor ?? test()->counter)->put("/opname/{$o->id}/baris/{$l->id}", $data);
}

function opnReason(): int
{
    return (int) ReferenceItem::query()->forGroup(ReferenceGroup::OpnameVarianceReason)->where('code', 'lost')->value('id');
}

function opnDecide(User $actor, StockOpname $o, bool $approve): ApprovalRequest
{
    $r = ApprovalRequest::query()->findOrFail($o->fresh()->approval_request_id);
    $d = app(DecideApprovalStep::class);

    return $approve ? $d->approve($actor, $r->id, $r->current_step, null, true) : $d->reject($actor, $r->id, $r->current_step, 'Hitung ulang rak R-01', true);
}

/** Hitung → (hitung ulang orang lain bila perlu) → selesai → alasan → ajukan posting. */
function opnToSubmitted(StockOpname $o, string $count, ?string $recount = null): StockOpname
{
    $line = opnLine($o);
    opnPut($o, $line, ['mode' => 'count', 'qty' => $count])->assertSessionHasNoErrors();
    if ($recount !== null) {
        opnPut($o, $line, ['mode' => 'recount', 'qty' => $recount], test()->counter2)->assertSessionHasNoErrors();
    }
    test()->actingAs(test()->counter)->post("/opname/{$o->id}/ajukan")->assertSessionHasNoErrors();
    opnPut($o, $line, ['mode' => 'reason', 'variance_reason_id' => opnReason()])->assertSessionHasNoErrors();
    test()->actingAs(test()->counter)->post("/opname/{$o->id}/posting")->assertSessionHasNoErrors();

    return $o->fresh();
}

function opnEvents(StockOpname $o)
{
    return AccountingEvent::query()->where('source_type', 'stock_opname')->where('source_id', $o->id)->get();
}

test('SEC-103 lembar hitung blind saat counting: respons tidak memuat qty_system_base, ekspektasi, maupun selisih', function () {
    $o = opnStarted();
    opnPut($o, opnLine($o), ['mode' => 'count', 'qty' => '50'])->assertSessionHasNoErrors();

    $response = $this->actingAs($this->counter)->get("/opname/{$o->id}/baris")->assertOk();
    $response->assertInertia(fn (Assert $p) => $p->component('stock-opnames/count-sheet')
        ->where('opname.hides_system', true)
        ->has('rows.0', fn (Assert $row) => $row->missing('qty_system_base')->missing('qty_expected_base')->missing('variance_base')
            ->missing('recount_required')->where('qty_counted_base', '50.000')->etc()));
    $props = json_encode($response->viewData('page')['props']);
    expect($props)->not->toContain('qty_system_base')
        ->and(collect($response->viewData('page')['props']['listing']['spec']['filters'])->pluck('key')->all())->not->toContain('has_variance');
});

test('BR-162(2) jual/mutasi 5 unit setelah freeze tidak muncul sebagai selisih; tanpa selisih → posting langsung tanpa event', function () {
    $o = opnStarted();
    // Waktu test dibekukan; di lapangan penjualan selalu tercatat sesudah `frozen_at`.
    $this->travel(1)->seconds();
    StockLedger::transaction(fn () => LedgerWorld::ledger()->issue($this->batch->fresh(), Qty::of('5'), new Source('sales_invoice', 1, 1), MovementType::Sale, '2026-11-17'));

    $line = opnLine($o);
    opnPut($o, $line, ['mode' => 'count', 'qty' => '45'])->assertSessionHasNoErrors();
    $this->actingAs($this->counter)->post("/opname/{$o->id}/ajukan")->assertSessionHasNoErrors();
    $this->actingAs($this->counter)->get("/opname/{$o->id}/baris")
        ->assertInertia(fn (Assert $p) => $p->where('rows.0.qty_system_base', '50.000')->where('rows.0.qty_expected_base', '45.000')->where('rows.0.variance_base', '0.000'));
    $this->actingAs($this->counter)->post("/opname/{$o->id}/posting")->assertSessionHasNoErrors();

    expect($o->fresh()->status)->toBe(StockOpnameStatus::Posted)
        ->and($this->batch->fresh()->qty_on_hand)->toBe('45.000')
        ->and(opnEvents($o))->toHaveCount(0)
        ->and(StockMovement::query()->where('source_type', 'stock_opname')->count())->toBe(0);
});

test('BR-162(4) SEC-103 selisih ≥ ambang tidak bisa review sebelum dihitung ulang orang lain (OPNAME_RECOUNT_REQUIRED)', function () {
    $o = opnStarted();
    $line = opnLine($o);
    opnPut($o, $line, ['mode' => 'count', 'qty' => '40'])->assertSessionHasNoErrors(); // 20% > 5%

    $this->actingAs($this->counter)->postJson("/opname/{$o->id}/ajukan")
        ->assertStatus(422)->assertJsonPath('error.code', 'OPNAME_RECOUNT_REQUIRED')->assertJsonPath('error.details.lines', [$line->id]);
    // Penghitung pertama tidak boleh menghitung ulang sendiri.
    opnPut($o, $line, ['mode' => 'recount', 'qty' => '40'])->assertSessionHasErrors('qty');

    opnPut($o, $line, ['mode' => 'recount', 'qty' => '41'], $this->counter2)->assertSessionHasNoErrors();
    $this->actingAs($this->counter)->post("/opname/{$o->id}/ajukan")->assertSessionHasNoErrors();

    expect($o->fresh()->status)->toBe(StockOpnameStatus::Review)
        ->and(opnLine($o)->recounted_by)->toBe($this->counter2->id)
        ->and(ErrorCode::OPNAME_RECOUNT_REQUIRED->status())->toBe(422);
});

test('BR-162(8) batch yang sedang di opname lain → OPNAME_BATCH_LOCKED', function () {
    opnStarted();
    $second = opnCreate(['scope' => 'product', 'scope_ids' => [$this->product->id]]);

    $this->actingAs($this->counter)->postJson("/opname/{$second->id}/mulai")
        ->assertStatus(409)->assertJsonPath('error.code', 'OPNAME_BATCH_LOCKED');

    expect($second->fresh()->status)->toBe(StockOpnameStatus::Draft)
        ->and($second->lines()->count())->toBe(0);
});

test('BR-162(6) selisih Rp 1,2 jt → rantai 2 langkah; posting adjustment_out + inventory.adjusted seimbang dengan lokasi & produk per baris', function () {
    $costly = LedgerWorld::product('OPN-MAHAL');
    $this->batch = LedgerWorld::receive($this->gd, $costly, '20', '100000', new BatchKey('B-MHL', '2027-12-31', $this->bin->id));
    $o = opnCreate(['scope' => 'product', 'scope_ids' => [$costly->id]]);
    $this->actingAs($this->counter)->post("/opname/{$o->id}/mulai")->assertSessionHasNoErrors();

    $o = opnToSubmitted($o->fresh(), '8', '8'); // kurang 12 × Rp 100.000
    $request = ApprovalRequest::query()->findOrFail($o->approval_request_id);
    expect($o->status)->toBe(StockOpnameStatus::PendingApproval)
        ->and($o->variance_value)->toBe('1200000.00')
        ->and($request->amount)->toBe('1200000.00')
        ->and(count($request->rule_snapshot['steps']))->toBe(2);

    opnDecide($this->head, $o, true);
    expect($o->fresh()->status)->toBe(StockOpnameStatus::PendingApproval);
    opnDecide($this->owner, $o, true);
    $o->refresh();

    $event = opnEvents($o)->sole();
    $debit = collect($event->payload['lines'])->sum(fn ($l) => (float) ($l['debit'] ?? 0));
    $credit = collect($event->payload['lines'])->sum(fn ($l) => (float) ($l['credit'] ?? 0));
    expect($o->status)->toBe(StockOpnameStatus::Posted)
        ->and($this->batch->fresh()->qty_on_hand)->toBe('8.000')
        ->and(StockMovement::query()->where('source_type', 'stock_opname')->sole()->movement_type)->toBe(MovementType::AdjustmentOut)
        ->and($event->event_code)->toBe(EventCode::InventoryAdjusted->value)
        ->and($debit)->toBe($credit)->toBe(1200000.0)
        ->and($event->payload['lines'][0])->toMatchArray(['role' => 'inventory_variance', 'stock_location_id' => $this->gd->id, 'product_id' => $costly->id])
        ->and($event->payload['lines'][1])->toMatchArray(['role' => 'inventory', 'stock_location_id' => $this->gd->id, 'product_id' => $costly->id]);
});

test('ADR-115 PostStockOpname memanggil PeriodGuard dengan tanggal keputusan: terkunci → rollback utuh', function () {
    $o = opnToSubmitted(opnStarted(), '49');
    setSetting('accounting.lock_date', '2026-11-17');

    expect(fn () => opnDecide($this->head, $o, true))->toThrow(fn (ApiError $e) => expect($e->errorCode)->toBe(ErrorCode::PERIOD_LOCKED));

    expect($o->fresh()->status)->toBe(StockOpnameStatus::PendingApproval)
        ->and($this->batch->fresh()->qty_on_hand)->toBe('50.000')
        ->and(opnEvents($o))->toHaveCount(0);
});

test('BR-162(7) batch temuan → adjustment_in batch baru dengan inventory.adjusted (bukan goods_received/opening_balance)', function () {
    $o = opnStarted(['blind_count' => false]);
    opnPut($o, opnLine($o), ['mode' => 'count', 'qty' => '50'])->assertSessionHasNoErrors();
    $this->actingAs($this->counter)->post("/opname/{$o->id}/baris", [
        'product_id' => $this->product->id, 'batch_no' => 'b-temu', 'expiry_date' => '2028-01-31', 'stock_bin_id' => $this->bin->id, 'qty' => '3',
    ])->assertSessionHasNoErrors();
    $found = $o->lines()->whereNull('stock_batch_id')->sole();
    opnPut($o, $found, ['mode' => 'recount', 'qty' => '3'], $this->counter2)->assertSessionHasNoErrors(); // ekspektasi 0 = wajib hitung ulang
    $this->actingAs($this->counter)->post("/opname/{$o->id}/ajukan")->assertSessionHasNoErrors();
    opnPut($o, $found, ['mode' => 'reason', 'variance_reason_id' => opnReason()])->assertSessionHasNoErrors();
    $this->actingAs($this->counter)->post("/opname/{$o->id}/posting")->assertSessionHasNoErrors();
    opnDecide($this->head, $o, true);

    $found->refresh();
    $newBatch = StockBatch::query()->findOrFail($found->stock_batch_id);
    expect($o->fresh()->status)->toBe(StockOpnameStatus::Posted)
        ->and($newBatch->batch_no)->toBe('B-TEMU')
        ->and($newBatch->qty_on_hand)->toBe('3.000')
        ->and(StockMovement::query()->where('source_type', 'stock_opname')->sole()->movement_type)->toBe(MovementType::AdjustmentIn)
        ->and(opnEvents($o)->pluck('event_code')->all())->toBe([EventCode::InventoryAdjusted->value])
        ->and(opnEvents($o)->sole()->payload['lines'][0])->toMatchArray(['role' => 'inventory', 'debit' => '3000.00']);
});

test('BR-162 ditolak → kembali review (bukan cancelled); hitung ulang dihapus dan setiap baris selisih wajib dihitung ulang orang lain', function () {
    $o = opnToSubmitted(opnStarted(), '40', '40');
    opnDecide($this->head, $o, false);
    $o->refresh();

    expect($o->status)->toBe(StockOpnameStatus::Review)
        ->and($o->approval_request_id)->toBeNull()
        ->and(opnLine($o)->qty_recount_base)->toBeNull();

    $this->actingAs($this->counter)->postJson("/opname/{$o->id}/posting")->assertStatus(422)->assertJsonPath('error.code', 'OPNAME_RECOUNT_REQUIRED');

    opnPut($o, opnLine($o), ['mode' => 'recount', 'qty' => '50'], $this->counter2)->assertSessionHasNoErrors();
    $this->actingAs($this->counter)->post("/opname/{$o->id}/posting")->assertSessionHasNoErrors();

    expect($o->fresh()->status)->toBe(StockOpnameStatus::Posted)
        ->and($this->batch->fresh()->qty_on_hand)->toBe('50.000');
});

test('BR-162(5) baris selisih wajib alasan sebelum diajukan', function () {
    $o = opnStarted();
    opnPut($o, opnLine($o), ['mode' => 'count', 'qty' => '49'])->assertSessionHasNoErrors(); // 2% < 5%, Rp 1.000 < ambang
    $this->actingAs($this->counter)->post("/opname/{$o->id}/ajukan")->assertSessionHasNoErrors();

    $this->actingAs($this->counter)->post("/opname/{$o->id}/posting")->assertSessionHasErrors('lines');
    expect($o->fresh()->status)->toBe(StockOpnameStatus::Review);
});

test('IDOR admin.stock-opnames.*: staf lokasi lain → 404', function () {
    $o = opnStarted();
    $line = opnLine($o);

    $this->assertOutOfScope404($this->outsider, 'GET', "/opname/{$o->id}");
    $this->assertOutOfScope404($this->outsider, 'GET', "/opname/{$o->id}/baris");
    $this->assertOutOfScope404($this->outsider, 'PUT', "/opname/{$o->id}/baris/{$line->id}", ['mode' => 'count', 'qty' => '1']);
    $this->assertOutOfScope404($this->outsider, 'POST', "/opname/{$o->id}/ajukan");
    $this->assertOutOfScope404($this->outsider, 'POST', "/opname/{$o->id}/posting");
    $this->assertOutOfScope404($this->outsider, 'POST', "/opname/{$o->id}/batal");
    $this->assertOutOfScope404($this->outsider, 'POST', "/opname/{$o->id}/baris", ['product_id' => 1, 'batch_no' => 'X', 'qty' => '1']);

    $this->actingAs($this->head)->get("/opname/{$o->id}")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('stock-opnames/show')->where('can.finish', true)->missing('summary.variance_lines'));
});

test('listing stock_opnames: cari nomor, filter status & cakupan, sort, scope lokasi', function () {
    $a = opnStarted();
    $this->travel(1)->minutes();
    $b = opnCreate(['scope' => 'bin', 'scope_ids' => [$this->bin->id]]);

    $rows = fn (User $u, array $q = []) => collect($this->actingAs($u)->get('/opname?'.http_build_query($q))->assertOk()
        ->viewData('page')['props']['rows'])->pluck('number')->all();

    expect($rows($this->counter))->toBe([$b->number, $a->number])
        ->and($rows($this->counter, ['sort' => 'number']))->toBe([$a->number, $b->number])
        ->and($rows($this->counter, ['q' => $a->number]))->toBe([$a->number])
        ->and($rows($this->counter, ['filter' => ['status' => ['counting']]]))->toBe([$a->number])
        ->and($rows($this->counter, ['filter' => ['scope' => ['bin']]]))->toBe([$b->number])
        ->and($rows($this->outsider))->toBe([]);
});

test('listing stock_opname_lines: filter sudah dihitung, cari batch, batal mengosongkan kunci batch', function () {
    $o = opnStarted(['blind_count' => false]);
    LedgerWorld::receive($this->gd, $this->product, '5', '1000', new BatchKey('B-002', '2028-03-31', $this->bin->id));
    // Batch B-002 diterima setelah mulai: bukan bagian snapshot.
    expect($o->lines()->count())->toBe(1);
    opnPut($o, opnLine($o), ['mode' => 'count', 'qty' => '50'])->assertSessionHasNoErrors();

    $rows = fn (array $q = []) => collect($this->actingAs($this->counter)->get("/opname/{$o->id}/baris?".http_build_query($q))->assertOk()
        ->viewData('page')['props']['rows'])->pluck('batch_no')->all();
    expect($rows(['filter' => ['counted' => '1']]))->toBe(['B-001'])
        ->and($rows(['filter' => ['counted' => '0']]))->toBe([])
        ->and($rows(['q' => 'B-00']))->toBe(['B-001'])
        ->and($rows(['filter' => ['has_variance' => '0']]))->toBe(['B-001']);

    $this->actingAs($this->counter)->post("/opname/{$o->id}/batal")->assertSessionHasNoErrors();
    expect($o->fresh()->status)->toBe(StockOpnameStatus::Cancelled);
    // Batch bebas lagi untuk opname baru.
    opnStarted();
});
