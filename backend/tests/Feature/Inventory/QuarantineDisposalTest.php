<?php

use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\Models\AccountingEvent;
use App\Domain\Approval\Actions\DecideApprovalStep;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Inventory\Allocation\FefoAllocator;
use App\Domain\Inventory\Enums\DisposalReportStatus;
use App\Domain\Inventory\Enums\QuarantineReleaseStatus;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\DisposalReport;
use App\Domain\Inventory\Models\QuarantineRelease;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Database\Seeders\TransitStockLocationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\EmployeeWorld;
use Tests\Support\LedgerWorld;
use Tests\Support\StockWorld;

/*
 * T-084 — karantina manual, lepas karantina, pemusnahan & berita acara (FR-A29, BR-163, BR-164,
 * SEC-109, PA-69). Data fiktif; saksi tanpa NIK. Waktu dibekukan 17 Nov 2026 03.00 UTC.
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 03:00:00', 'UTC'));
    Notification::fake();
    Storage::fake('local');
    $this->org = EmployeeWorld::make($this);
    $this->seed([TransitStockLocationSeeder::class, ApprovalRuleSeeder::class]);
    $this->useRealPermissions();
    setSetting('approval.reauth_amount', '999999999999');

    $this->gd = StockWorld::stockLocation($this->org->gudang, 'GD-A');
    $this->rack = LedgerWorld::bin($this->gd, 'R-01');
    $this->qbin = LedgerWorld::quarantineBin($this->gd);
    $this->staff = $this->org->actor('staf_gudang', $this->org->employee($this->org->gudang));
    $this->head = $this->org->actor('kepala_gudang', $this->org->employee($this->org->gudang));
    $this->owner = $this->org->actor('owner');
    $this->outsider = $this->org->actor('kepala_gudang', $this->org->employee(StockWorld::location('L-LAIN')));

    $this->product = LedgerWorld::product('KAR-01');
    $this->batch = LedgerWorld::receive($this->gd, $this->product, '50', '1000', new BatchKey('B-001', '2027-12-31', $this->rack->id));
});

function karReason(ReferenceGroup $group, string $code): int
{
    return (int) ReferenceItem::query()->forGroup($group)->where('code', $code)->value('id');
}

function karQuarantine(string $qty = '10', ?User $actor = null): StockBatch
{
    test()->actingAs($actor ?? test()->staff)->post('/stok/karantina', [
        'stock_batch_id' => test()->batch->id, 'qty_base' => $qty, 'reason_id' => karReason(ReferenceGroup::QuarantineReason, 'leaking'),
    ])->assertSessionHasNoErrors();

    return StockBatch::query()->where('stock_bin_id', test()->qbin->id)->where('product_id', test()->product->id)->sole();
}

function karDecide(User $actor, int $requestId, bool $approve = true): ApprovalRequest
{
    $r = ApprovalRequest::query()->findOrFail($requestId);
    $d = app(DecideApprovalStep::class);

    return $approve ? $d->approve($actor, $r->id, $r->current_step, null, true) : $d->reject($actor, $r->id, $r->current_step, 'Belum lolos uji mutu', true);
}

function karReport(StockBatch $batch, string $method = 'destroy', string $qty = '10'): DisposalReport
{
    test()->actingAs(test()->head)->post('/pemusnahan', [
        'stock_location_id' => test()->gd->id, 'reason_id' => karReason(ReferenceGroup::DisposalReason, 'damaged'), 'method' => $method,
        'planned_date' => '2026-11-20', 'note' => null, 'lines' => [['stock_batch_id' => $batch->id, 'qty_base' => $qty]],
    ])->assertSessionHasNoErrors();

    return DisposalReport::query()->latest('id')->firstOrFail();
}

function karApproved(StockBatch $batch, string $method = 'destroy'): DisposalReport
{
    $r = karReport($batch, $method);
    test()->actingAs(test()->head)->post("/pemusnahan/{$r->id}/ajukan")->assertSessionHasNoErrors();
    karDecide(test()->owner, (int) $r->fresh()->approval_request_id);

    return $r->fresh();
}

/** @return array<string, mixed> */
function karEvidence(array $override = []): array
{
    $image = imagecreatetruecolor(32, 32);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    return [
        'executed_location' => 'Halaman belakang gudang',
        'witnesses' => [
            ['name' => 'Saksi Satu', 'role' => 'Staf Dinas', 'organization' => 'Dinas Pertanian'],
            ['name' => 'Saksi Dua', 'role' => 'Perwakilan', 'organization' => 'PT Prinsipal Uji'],
        ],
        'document' => UploadedFile::fake()->createWithContent('ba.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
        'photos' => [UploadedFile::fake()->createWithContent('foto.jpg', $jpeg)],
        ...$override,
    ];
}

function karEvents(DisposalReport $r)
{
    return AccountingEvent::query()->where('source_type', 'disposal_report')->where('source_id', $r->id)->get();
}

test('BR-164 karantina sebagian: qty pindah ke rak karantina berstatus quarantine + alasan; tidak pernah dialokasikan FEFO; tanpa event', function () {
    $q = karQuarantine('10');

    expect($q->status)->toBe(StockBatchStatus::Quarantine)
        ->and($q->qty_on_hand)->toBe('10.000')
        ->and($q->quarantine_reason_id)->toBe(karReason(ReferenceGroup::QuarantineReason, 'leaking'))
        ->and($this->batch->fresh()->qty_on_hand)->toBe('40.000')
        ->and(FefoAllocator::candidates($this->product->id, $this->gd->id)->pluck('stock_batches.id')->all())->toBe([$this->batch->id])
        ->and(StockMovement::query()->where('source_type', 'stock_quarantine')->pluck('movement_type')->map->value->all())->toBe(['bin_move_out', 'bin_move_in'])
        ->and(AccountingEvent::query()->count())->toBe(0);

    // Seluruh qty tersedia dikarantina → FEFO tidak menemukan apa pun.
    karQuarantine('40');
    expect(fn () => app(FefoAllocator::class)->allocate($this->product, $this->gd, Qty::of('1')))
        ->toThrow(fn (ApiError $e) => expect($e->errorCode)->toBe(ErrorCode::INSUFFICIENT_STOCK));
});

test('BR-164 lepas karantina lewat approval ke rak pilihan (tabel stock_quarantine_releases, PA-69); tanpa event', function () {
    $q = karQuarantine('10');
    $this->actingAs($this->staff)->post("/stok/karantina/{$q->id}/lepas", ['qty_base' => '6', 'target_bin_id' => $this->rack->id, 'reason' => 'Lolos uji mutu ulang'])
        ->assertSessionHasNoErrors();
    $release = QuarantineRelease::query()->sole();
    expect($release->status)->toBe(QuarantineReleaseStatus::Pending)
        ->and($q->fresh()->qty_on_hand)->toBe('10.000');

    karDecide($this->head, (int) $release->approval_request_id);

    expect($release->fresh()->status)->toBe(QuarantineReleaseStatus::Approved)
        ->and($q->fresh()->qty_on_hand)->toBe('4.000')
        ->and($this->batch->fresh()->qty_on_hand)->toBe('46.000')
        ->and($this->batch->fresh()->status)->toBe(StockBatchStatus::Available)
        ->and(AccountingEvent::query()->count())->toBe(0);
});

test('BR-164 batch expired tidak bisa dilepas karantina', function () {
    $q = karQuarantine('10');
    StockLedger::transaction(fn () => LedgerWorld::ledger()->setStatus($q, StockBatchStatus::Expired));

    $this->actingAs($this->staff)->post("/stok/karantina/{$q->id}/lepas", ['qty_base' => '1', 'target_bin_id' => $this->rack->id, 'reason' => 'Coba lepas'])
        ->assertSessionHasErrors('stock_batch_id');
    expect(QuarantineRelease::query()->count())->toBe(0);
});

test('BR-164 pindah rak: ke rak biasa boleh tanpa event; masuk/keluar rak karantina lewat pindah rak ditolak', function () {
    $r2 = LedgerWorld::bin($this->gd, 'R-02');
    $this->actingAs($this->staff)->post('/stok/pindah-rak', ['stock_batch_id' => $this->batch->id, 'stock_bin_id' => $r2->id, 'qty_base' => '5'])->assertSessionHasNoErrors();
    expect(StockBatch::query()->where('stock_bin_id', $r2->id)->sole()->qty_on_hand)->toBe('5.000');

    $this->actingAs($this->staff)->post('/stok/pindah-rak', ['stock_batch_id' => $this->batch->id, 'stock_bin_id' => $this->qbin->id, 'qty_base' => '1'])
        ->assertSessionHasErrors('stock_bin_id');
    $q = karQuarantine('2');
    $this->actingAs($this->staff)->post('/stok/pindah-rak', ['stock_batch_id' => $q->id, 'stock_bin_id' => $r2->id, 'qty_base' => '1'])
        ->assertSessionHasErrors('stock_batch_id');
    expect(AccountingEvent::query()->count())->toBe(0);
});

test('BR-162(8) batch yang sedang di opname aktif tidak bisa dikarantina (OPNAME_BATCH_LOCKED)', function () {
    $this->actingAs($this->head)->post('/opname', ['stock_location_id' => $this->gd->id, 'scope' => 'full', 'blind_count' => false])->assertSessionHasNoErrors();
    $opname = StockOpname::query()->sole();
    $this->actingAs($this->head)->post("/opname/{$opname->id}/mulai")->assertSessionHasNoErrors();

    $this->actingAs($this->staff)->postJson('/stok/karantina', [
        'stock_batch_id' => $this->batch->id, 'qty_base' => '1', 'reason_id' => karReason(ReferenceGroup::QuarantineReason, 'damaged'),
    ])->assertStatus(409)->assertJsonPath('error.code', 'OPNAME_BATCH_LOCKED');
});

test('BR-163 berita acara hanya batch karantina/ED; disetujui → batch direservasi (PA-69)', function () {
    $this->actingAs($this->head)->post('/pemusnahan', [
        'stock_location_id' => $this->gd->id, 'reason_id' => karReason(ReferenceGroup::DisposalReason, 'damaged'), 'method' => 'destroy',
        'lines' => [['stock_batch_id' => $this->batch->id, 'qty_base' => '1']],
    ])->assertSessionHasErrors('lines.0.stock_batch_id');

    $q = karQuarantine('10');
    $r = karApproved($q);

    expect($r->status)->toBe(DisposalReportStatus::Approved)
        ->and($r->total_cost)->toBe('10000.00')
        ->and($q->fresh()->qty_reserved)->toBe('10.000');
});

test('BR-163 SEC-109 eksekusi tanpa scan BA / tanpa foto / < 2 saksi / saksi ber-NIK / berkas palsu ditolak; CHECK bap_exec_chk', function () {
    $r = karApproved(karQuarantine('10'));
    $post = fn (array $o) => $this->actingAs($this->head)->post("/pemusnahan/{$r->id}/laksanakan", karEvidence($o));

    $post(['document' => null])->assertSessionHasErrors('document');
    $post(['photos' => []])->assertSessionHasErrors('photos');
    $post(['witnesses' => [['name' => 'Saksi Satu', 'role' => 'Staf', 'organization' => 'Dinas']]])->assertSessionHasErrors('witnesses');
    $post(['witnesses' => [
        ['name' => 'Saksi Satu', 'role' => 'Staf', 'organization' => 'Dinas', 'nik' => '3319000000000001'],
        ['name' => 'Saksi Dua', 'role' => 'Staf', 'organization' => 'Dinas'],
    ]])->assertSessionHasErrors('witnesses.0');
    $post(['document' => UploadedFile::fake()->createWithContent('ba.pdf', 'bukan pdf sama sekali')])->assertSessionHasErrors();

    expect($r->fresh()->status)->toBe(DisposalReportStatus::Approved);
    expect(fn () => DB::table('disposal_reports')->where('id', $r->id)->update(['status' => 'executed']))
        ->toThrow(QueryException::class);
});

test('BR-163 eksekusi → qty 0, status disposed, outbox inventory.written_off seimbang; ADR-115 PeriodGuard tanggal eksekusi', function () {
    $q = karQuarantine('10');
    $r = karApproved($q);

    setSetting('accounting.lock_date', '2026-11-17');
    $this->actingAs($this->head)->post("/pemusnahan/{$r->id}/laksanakan", karEvidence())->assertSessionHasErrors();
    expect($r->fresh()->status)->toBe(DisposalReportStatus::Approved)->and($q->fresh()->qty_on_hand)->toBe('10.000');

    setSetting('accounting.lock_date', null);
    $this->actingAs($this->head)->post("/pemusnahan/{$r->id}/laksanakan", karEvidence())->assertSessionHasNoErrors();
    $r->refresh();
    $q->refresh();
    $event = karEvents($r)->sole();

    expect($r->status)->toBe(DisposalReportStatus::Executed)
        ->and($r->witnesses)->toHaveCount(2)
        ->and(array_keys($r->witnesses[0]))->toBe(['name', 'role', 'organization'])
        ->and($r->document_path)->not->toBeNull()
        ->and($r->photo_paths)->toHaveCount(1)
        ->and($q->qty_on_hand)->toBe('0.000')
        ->and($q->status)->toBe(StockBatchStatus::Disposed)
        ->and(StockMovement::query()->where('source_type', 'disposal_report')->sole()->movement_type)->toBe(MovementType::Disposal)
        ->and($event->event_code)->toBe(EventCode::InventoryWrittenOff->value)
        ->and($event->business_date->toDateString())->toBe('2026-11-17')
        ->and($event->payload['lines'])->toBe([
            ['role' => 'inventory_loss', 'debit' => '10000.00', 'stock_location_id' => $this->gd->id, 'product_id' => $this->product->id],
            ['role' => 'inventory', 'credit' => '10000.00', 'stock_location_id' => $this->gd->id, 'product_id' => $this->product->id],
        ]);
    Storage::disk('local')->assertExists($r->document_path);
});

test('BR-163 metode return_to_principal membuat retur prinsipal draf (T-081), bukan mutasi disposal; tanpa inventory.written_off', function () {
    $supplier = tap((new Supplier)->forceFill(['code' => 'SUP-1', 'name' => 'PT Prinsipal Uji', 'payment_term_days' => 30, 'is_active' => true]))->save();
    $q = karQuarantine('10');
    $r = karApproved($q, 'return_to_principal');

    $this->actingAs($this->head)->post("/pemusnahan/{$r->id}/laksanakan", karEvidence())->assertSessionHasErrors(['supplier_id', 'return_reason_id']);
    $this->actingAs($this->head)->post("/pemusnahan/{$r->id}/laksanakan", karEvidence([
        'supplier_id' => Supplier::query()->sole()->id, 'return_reason_id' => karReason(ReferenceGroup::PurchaseReturnReason, 'damaged'),
    ]))->assertSessionHasNoErrors();

    $return = PurchaseReturn::query()->sole();
    expect($r->fresh()->status)->toBe(DisposalReportStatus::Executed)
        ->and($return->lines()->sole()->stock_batch_id)->toBe($q->id)
        ->and($q->fresh()->qty_on_hand)->toBe('10.000')
        ->and($q->fresh()->qty_reserved)->toBe('0.000')
        ->and(StockMovement::query()->where('source_type', 'disposal_report')->count())->toBe(0)
        ->and(karEvents($r))->toHaveCount(0);

    $this->actingAs($this->head)->get("/pemusnahan/{$r->id}")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('disposal-reports/show')->where('purchaseReturn.id', $return->id));
});

test('BR-155 batal BA yang disetujui melepas reservasi batch', function () {
    $q = karQuarantine('10');
    $r = karApproved($q);
    $this->actingAs($this->head)->post("/pemusnahan/{$r->id}/batal")->assertSessionHasNoErrors();

    expect($r->fresh()->status)->toBe(DisposalReportStatus::Cancelled)
        ->and($q->fresh()->qty_reserved)->toBe('0.000')
        ->and(StockReservation::query()->where('source_type', 'disposal_report')->pluck('status')->all())->each->toBe(ReservationStatus::Released);
});

test('IDOR karantina & pemusnahan: kepala gudang lokasi lain → 404', function () {
    $q = karQuarantine('10');
    $r = karReport($q);

    $this->assertOutOfScope404($this->outsider, 'GET', "/pemusnahan/{$r->id}");
    $this->assertOutOfScope404($this->outsider, 'POST', "/pemusnahan/{$r->id}/ajukan");
    $this->assertOutOfScope404($this->outsider, 'POST', "/pemusnahan/{$r->id}/batal");
    $this->assertOutOfScope404($this->outsider, 'GET', "/pemusnahan/{$r->id}/cetak");
    $this->assertOutOfScope404($this->outsider, 'POST', '/stok/karantina', ['stock_batch_id' => $this->batch->id, 'qty_base' => '1', 'reason_id' => karReason(ReferenceGroup::QuarantineReason, 'damaged')]);
    $this->assertOutOfScope404($this->outsider, 'POST', "/stok/karantina/{$q->id}/lepas", ['qty_base' => '1', 'target_bin_id' => $this->rack->id, 'reason' => 'Coba lepas']);
    $this->assertOutOfScope404($this->outsider, 'POST', '/stok/pindah-rak', ['stock_batch_id' => $this->batch->id, 'stock_bin_id' => $this->rack->id, 'qty_base' => '1']);
});

test('listing stock_batches & disposal_reports: filter status/metode, cari, scope', function () {
    $q = karQuarantine('10');
    karReport($q);

    $batches = fn (User $u, array $p = []) => collect($this->actingAs($u)->get('/stok/karantina?'.http_build_query($p))->assertOk()
        ->viewData('page')['props']['rows'])->pluck('id')->all();
    $reports = fn (User $u, array $p = []) => collect($this->actingAs($u)->get('/pemusnahan?'.http_build_query($p))->assertOk()
        ->viewData('page')['props']['rows'])->pluck('number')->all();

    expect($batches($this->staff))->toHaveCount(2)
        ->and($batches($this->staff, ['filter' => ['status' => ['quarantine']]]))->toBe([$q->id])
        ->and($batches($this->staff, ['q' => 'KAR-01']))->toHaveCount(2)
        ->and($batches($this->outsider))->toBe([])
        ->and($reports($this->head, ['filter' => ['method' => ['destroy']]]))->toHaveCount(1)
        ->and($reports($this->head, ['filter' => ['status' => ['executed']]]))->toBe([])
        ->and($reports($this->outsider))->toBe([]);
});
