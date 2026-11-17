<?php

use App\Domain\Accounting\Outbox\Models\AccountingEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockMovement;
use App\Models\User;
use App\Support\Api\ErrorCode;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportRegistry;
use App\Support\Import\ImportRow;
use App\Support\Import\ImportRowStatus;
use App\Support\Import\ImportStatus;
use App\Support\Import\MappingSuggester;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EmployeeWorld;
use Tests\Support\LedgerWorld;

/*
 * T-086 — saldo awal stok & cutover (FR-A30, BR-170, PA-71). Berkas dummy dibuat di test: judul di atas
 * tabel, angka id-ID ("1.300"), tanggal "17/08/2027". Data fiktif.
 */

require_once __DIR__.'/../../Support/import_helpers.php';

const OS_DATE = '2026-11-17';

beforeEach(function () {
    Storage::fake('local');
    $this->org = EmployeeWorld::make($this);
    $this->useRealPermissions();
    $this->owner = $this->org->actor('owner');

    $this->gdA = LedgerWorld::warehouse('GD-A');
    $this->gdB = LedgerWorld::warehouse('GD-B');
    $this->bin = LedgerWorld::bin($this->gdA, 'R1');
    $this->p1 = LedgerWorld::product('P-01');
    $this->p2 = LedgerWorld::product('P-02', tracksBatch: false);
    $this->p3 = LedgerWorld::product('P-03', tracksBatch: false);
    $this->p3->forceFill(['tracks_serial' => true])->save();
    // GD-B sudah bertransaksi (penerimaan biasa): saldo awal ke sana ditolak.
    LedgerWorld::receive($this->gdB, $this->p1, '4', '900');
});

/** Unggah ke `/impor/saldo-awal`, petakan sesuai saran, kembalikan batch tervalidasi. */
function osUpload(User $actor, UploadedFile $file): ImportBatch
{
    test()->actingAs($actor)->post('/impor/saldo-awal', ['file' => $file])->assertSessionHasNoErrors()->assertRedirect();
    $batch = ImportBatch::query()->latest('id')->firstOrFail();
    $columns = MappingSuggester::suggest(ImportRegistry::forBatch($batch), $batch->headers())['columns'];
    test()->actingAs($actor)->put("/impor/{$batch->id}/pemetaan", ['columns' => $columns])->assertSessionHasNoErrors();

    return $batch->refresh();
}

function osProcess(User $actor, ImportBatch $batch, ?string $date = OS_DATE): ImportBatch
{
    test()->actingAs($actor)->post("/impor/{$batch->id}/proses", ['business_date' => $date])->assertSessionHasNoErrors();

    return $batch->refresh();
}

/** @return list<string> "baris kolom" galat, urut baris */
function osErrors(ImportBatch $batch): array
{
    $out = [];
    foreach (ImportRow::query()->where('import_batch_id', $batch->id)->where('status', ImportRowStatus::Invalid)->orderBy('row_number')->get() as $row) {
        foreach (array_keys((array) $row->errors) as $field) {
            $out[] = "{$row->row_number} {$field}";
        }
    }

    return $out;
}

function osFile(): UploadedFile
{
    return impXlsx([
        ['Stok fisik cutover 17 Nov 2026'],
        ['Gudang', 'SKU', 'Batch', 'ED', 'Rak', 'Jumlah', 'HPP'],
        ['gd-a', 'P-01', 'B1', '17/08/2027', 'r1', '10', '1.000'],
        ['GD-A', 'P-01', 'B2', '2027-09-01', '', '20', '1.300'],
        ['GD-A', 'P-02', 'abaikan', '', '', '5', 'Rp 2.500'],
        ['GD-B', 'P-01', 'B9', '17/08/2027', '', '1', '100'],
        ['GD-A', 'P-01', '', '17/08/2027', '', '1', '1'],
        ['GD-A', 'P-01', 'B3', '17/08/2027', 'ZZ', '1', '1'],
        ['GD-A', 'P-03', '', '', '', '1', '1'],
    ], 'saldo.xlsx');
}

test('BR-170 T-086 saldo awal: lokasi bertransaksi LOCATION_HAS_MOVEMENTS, HPP rata-rata tertimbang, outbox Σ qty × biaya, impor ulang = 0 perubahan', function () {
    $batch = osUpload($this->owner, osFile());
    expect(osErrors($batch))->toBe(['6 stock_location', '7 batch_no', '8 bin', '9 sku'])
        ->and(ImportRow::query()->where('import_batch_id', $batch->id)->where('row_number', 6)->value('errors')['stock_location'][0])
        ->toBe(ErrorCode::LOCATION_HAS_MOVEMENTS->message());

    // Ringkasan sebelum konfirmasi: baris valid, total qty per lokasi, total nilai.
    $this->actingAs($this->owner)->get("/impor/{$batch->id}/baris")->assertInertia(fn (Assert $page) => $page
        ->where('can.requires_business_date', true)
        ->where('typeSummary', [
            ['label' => 'Baris valid', 'value' => '3'],
            ['label' => 'Total qty GD-A', 'value' => '35'],
            ['label' => 'Total nilai', 'value' => 'Rp. 48.500,00'],
        ]));

    $batch = osProcess($this->owner, $batch);
    expect([$batch->created_rows, $batch->skipped_rows, $batch->error_rows])->toBe([3, 0, 4]);

    $movements = StockMovement::query()->where('movement_type', 'opening_balance')->get();
    expect($movements)->toHaveCount(3)
        ->and($movements->every(fn (StockMovement $m) => $m->business_date->toDateString() === OS_DATE && $m->source_type === 'opening_stock'))->toBeTrue()
        ->and($movements->firstWhere('product_id', $this->p2->id)?->batch->batch_no)->toBe(BatchKey::NO_BATCH)
        ->and($movements->firstWhere('unit_cost', '1000.0000')?->batch->stock_bin_id)->toBe($this->bin->id);

    // P-01: 4 @ 900 (penerimaan GD-B) + 10 @ 1.000 + 20 @ 1.300 → (3.600 + 10.000 + 26.000) / 34.
    expect(ProductCost::query()->where('product_id', $this->p1->id)->value('avg_cost'))->toBe('1164.7059')
        ->and(ProductCost::query()->where('product_id', $this->p2->id)->value('avg_cost'))->toBe('2500.0000');

    $events = AccountingEvent::query()->where('event_code', 'inventory.opening_balance')->get();
    $debits = $events->flatMap(fn (AccountingEvent $e) => collect($e->payload['lines'])->where('role', 'inventory'));
    expect($events)->toHaveCount(3)
        ->and($debits->sum(fn (array $l) => (float) $l['debit']))->toBe(48500.0)
        ->and($events->every(fn (AccountingEvent $e) => $e->business_date->toDateString() === OS_DATE))->toBeTrue()
        ->and($events->flatMap(fn (AccountingEvent $e) => $e->payload['lines'])->every(fn (array $l) => ($l['stock_location_id'] ?? null) === $this->gdA->id && is_int($l['product_id'] ?? null)))->toBeTrue()
        ->and(AccountingEvent::query()->where('event_code', 'inventory.goods_received')->count())->toBe(0);

    $again = osProcess($this->owner, osUpload($this->owner, osFile()));
    expect([$again->created_rows, $again->skipped_rows])->toBe([0, 3])
        ->and(StockMovement::query()->where('movement_type', 'opening_balance')->count())->toBe(3)
        ->and(AccountingEvent::query()->where('event_code', 'inventory.opening_balance')->count())->toBe(3);
});

test('BR-170 T-086 tanggal cutover wajib dan di periode terbuka (PeriodGuard)', function () {
    $batch = osUpload($this->owner, impXlsx([['Gudang', 'SKU', 'Batch', 'ED', 'Jumlah', 'HPP'], ['GD-A', 'P-01', 'B1', '17/08/2027', '1', '1']], 's.xlsx'));
    setSetting('accounting.lock_date', OS_DATE);

    $this->actingAs($this->owner)->post("/impor/{$batch->id}/proses", [])->assertSessionHasErrors('business_date');
    $this->actingAs($this->owner)->post("/impor/{$batch->id}/proses", ['business_date' => OS_DATE])->assertSessionHasErrors('business_date');
    expect($batch->refresh()->status)->toBe(ImportStatus::Validated);

    expect(osProcess($this->owner, $batch, '2026-11-18')->created_rows)->toBe(1)
        ->and(StockMovement::query()->where('movement_type', 'opening_balance')->value('business_date')->toDateString())->toBe('2026-11-18');
    setSetting('accounting.lock_date', null);
});

test('BR-170 T-086 tutup saldo awal: wajib password, audit penutupan, tidak bisa dibuka lagi, impor berikutnya OPENING_BALANCE_CLOSED', function () {
    osProcess($this->owner, osUpload($this->owner, impXlsx([['Gudang', 'SKU', 'Batch', 'ED', 'Jumlah', 'HPP'], ['GD-A', 'P-01', 'B1', '17/08/2027', '2', '1.500']], 's.xlsx')));
    $pending = osUpload($this->owner, impXlsx([['Gudang', 'SKU', 'Batch', 'ED', 'Jumlah', 'HPP'], ['GD-A', 'P-01', 'B7', '17/08/2027', '1', '1']], 's2.xlsx'));
    $field = 'inventory__opening_balance_open';

    // Grup Gudang & stok berisi kunci one_way yang masih menyala: konfirmasi password dulu.
    $this->actingAs($this->owner)->put('/pengaturan/inventory', ['values' => [$field => false]])->assertRedirect(route('password.confirm'));
    expect(setting('inventory.opening_balance_open'))->toBeTrue();

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
        ->put('/pengaturan/inventory', ['values' => [$field => false]])->assertSessionHasNoErrors();
    app()->forgetScopedInstances();
    expect(setting('inventory.opening_balance_open'))->toBeFalse();
    $audit = AuditLog::query()->where('event', 'inventory.opening_balance_closed')->sole();
    expect($audit->new_values['movements'])->toBe(1)->and($audit->new_values['total_value'])->toBe('3000.00');

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
        ->put('/pengaturan/inventory', ['values' => [$field => true]])->assertSessionHasErrors($field);
    app()->forgetScopedInstances();
    expect(setting('inventory.opening_balance_open'))->toBeFalse();

    // Unggah, pemetaan ulang, dan proses ditolak; batch yang tertunda tidak diproses.
    $before = ImportBatch::query()->count();
    $this->actingAs($this->owner)->post('/impor/saldo-awal', ['file' => impXlsx([['Gudang', 'SKU', 'Jumlah', 'HPP'], ['GD-A', 'P-02', '1', '1']], 's3.xlsx')])->assertRedirect();
    $this->actingAs($this->owner)->post("/impor/{$pending->id}/proses", ['business_date' => OS_DATE])->assertRedirect();
    expect(ImportBatch::query()->count())->toBe($before)
        ->and($pending->refresh()->status)->toBe(ImportStatus::Validated)
        ->and(StockMovement::query()->where('movement_type', 'opening_balance')->count())->toBe(1);
    $this->actingAs($this->owner)->get('/impor')->assertInertia(fn (Assert $page) => $page
        ->where('importTypes', fn ($types) => collect($types)->pluck('key')->doesntContain('opening_stock')));
});

test('BR-170 T-086 berkas 30.000 baris diterima (import.max_rows_opening_stock, NFR-A01); 30.001 ditolak', function () {
    $csv = fn (int $n) => impCsv("Gudang,SKU,Jumlah,HPP\n".str_repeat("GD-A,P-02,1,1\n", $n), 'besar.csv');

    $this->actingAs($this->owner)->post('/impor/saldo-awal', ['file' => $csv(30_000)])->assertSessionHasNoErrors()->assertRedirect();
    expect(ImportBatch::query()->latest('id')->value('total_rows'))->toBe(30_000);

    $this->actingAs($this->owner)->post('/impor/saldo-awal', ['file' => $csv(30_001)])->assertSessionHasErrors('file');
});

test('BR-170 T-086 izin: master_imports.manage + stock.view_cost; tanpa salah satunya 403', function () {
    $sales = $this->org->actor('admin_penjualan'); // master_imports.manage tanpa stock.view_cost
    $head = $this->org->actor('kepala_gudang'); // tanpa master_imports.manage
    $purchasing = $this->org->actor('purchasing');

    $this->actingAs($sales)->get('/impor/saldo-awal/baru')->assertForbidden();
    $this->actingAs($head)->get('/impor/saldo-awal/baru')->assertForbidden();
    $this->actingAs($purchasing)->get('/impor/saldo-awal/baru')->assertOk();
});
