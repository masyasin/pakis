<?php

use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Listings\StockMovementListing;
use App\Domain\Inventory\Listings\StockPositionListing;
use App\Domain\Inventory\Models\DisposalReport;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportRunner;
use App\Support\Export\UserScopedExportColumns;
use Carbon\CarbonImmutable;
use Database\Seeders\TransitStockLocationSeeder;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EmployeeWorld;
use Tests\Support\LedgerWorld;
use Tests\Support\StockWorld;

/*
 * T-087 — SEC-107 (15 §10.2, TA-08): kolom biaya (`avg_cost`, `value`, `unit_cost`) hanya untuk
 * `stock.view_cost`, diperiksa di props server & kolom ekspor (bukan hanya UI), per role sungguhan.
 * Ekspor stok memakai `stock.export`. Data fiktif.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 03:00:00', 'UTC'));
    Storage::fake(ExportRunner::DISK);
    $this->org = EmployeeWorld::make($this);
    $this->seed([TransitStockLocationSeeder::class]);
    $this->useRealPermissions();

    $this->gd = StockWorld::stockLocation($this->org->gudang, 'GD-A');
    $this->product = LedgerWorld::product('HPP-01');
    LedgerWorld::receive($this->gd, $this->product, '20', '2500', new BatchKey('B-001', '2027-12-31'));
    LedgerWorld::receive($this->gd, $this->product, '5', '2500', new BatchKey('B-002', '2028-01-31'));
    $this->as = fn (string $role) => $this->org->actor($role, $this->org->employee($this->org->gudang));
});

test('SEC-107 posisi & kartu stok per role: tanpa stock.view_cost kunci biaya tidak ada di props', function (string $role, bool $cost) {
    $user = ($this->as)($role);
    $check = fn (Assert $page, string $key) => $cost ? $page->has($key) : $page->missing($key);

    $this->actingAs($user)->get('/stok')->assertOk()
        ->assertInertia(fn (Assert $page) => $check($check($page->where('canViewCost', $cost), 'rows.0.value'), 'rows.0.avg_cost'));
    $this->actingAs($user)->get('/stok?filter[view]=per_batch')->assertOk()
        ->assertInertia(fn (Assert $page) => $check($page, 'rows.0.value'));
    $this->actingAs($user)->get('/stok/kartu')->assertOk()
        ->assertInertia(fn (Assert $page) => $check($page->where('canViewCost', $cost), 'rows.0.unit_cost'));
})->with([
    'admin_penjualan' => ['admin_penjualan', false],
    'kepala_gudang' => ['kepala_gudang', false],
    'staf_gudang' => ['staf_gudang', false],
    'purchasing' => ['purchasing', true],
    'owner' => ['owner', true],
]);

test('SEC-107 ekspor stok: Admin Penjualan ditolak (stock.export ❌ → 403); Kepala Gudang mengekspor tanpa kolom biaya; Purchasing dengan biaya', function () {
    $sales = ($this->as)('admin_penjualan');
    $head = ($this->as)('kepala_gudang');
    $buyer = ($this->as)('purchasing');

    $this->actingAs($sales)->get('/stok/ekspor')->assertForbidden();
    $this->actingAs($sales)->get('/stok/kartu/ekspor')->assertForbidden();
    $this->actingAs($head)->get('/stok/ekspor')->assertSuccessful();
    $this->actingAs($head)->get('/stok/kartu/ekspor')->assertSuccessful();

    $headers = fn ($listing, $user) => $listing->exportColumnsFor($user)->headers();
    expect($headers(new StockPositionListing, $head))->not->toContain('HPP rata-rata')->not->toContain('Nilai')
        ->and($headers(new StockMovementListing, $head))->not->toContain('Biaya satuan')
        ->and($headers(new StockPositionListing, $buyer))->toContain('HPP rata-rata', 'Nilai')
        ->and($headers(new StockMovementListing, $buyer))->toContain('Biaya satuan');
});

test('SEC-110 BR-005 ekspor stok di atas export.mass_export_alert_rows mencatat security event mass_export', function () {
    setSetting('export.mass_export_alert_rows', 1);

    $this->actingAs(($this->as)('kepala_gudang'))->get('/stok/ekspor')->assertSuccessful();

    $event = SecurityEvent::query()->where('event', SecurityEventType::MassExport->value)->sole();
    expect($event->context['listing'])->toBe('stock_positions')->and($event->context['row_count'])->toBe(2);
});

test('15 §5 semua ekspor Paket A lewat ExportableListing + StartExport: tanpa kelas *Export di app/Domain; listing berbiaya memilih kolom per pengguna', function () {
    $exports = array_filter(
        iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain'), FilesystemIterator::SKIP_DOTS))),
        fn (SplFileInfo $f) => $f->isFile() && str_ends_with($f->getFilename(), 'Export.php'),
    );
    expect(array_map(fn (SplFileInfo $f) => $f->getFilename(), array_values($exports)))->toBe([]);

    foreach (['stock_positions', 'stock_movements'] as $key) {
        $class = config("pakis.exports.{$key}");
        expect(is_subclass_of($class, ExportableListing::class))->toBeTrue()
            ->and(is_subclass_of($class, UserScopedExportColumns::class))->toBeTrue("{$key} memuat kolom biaya: wajib memilih kolom per pengguna (SEC-107).");
    }
});

test('SEC-107 sort nilai BA pemusnahan tanpa stock.view_cost tidak membocorkan peringkat HPP (jatuh ke tanggal dibuat)', function () {
    $reason = (int) ReferenceItem::query()->forGroup(ReferenceGroup::DisposalReason)->value('id');
    $make = fn (string $no, string $cost, string $at) => tap((new DisposalReport)->forceFill([
        'number' => $no, 'stock_location_id' => $this->gd->id, 'reason_id' => $reason, 'method' => 'destroy', 'status' => 'draft',
        'total_cost' => $cost, 'created_at' => $at, 'updated_at' => $at,
    ]))->save();
    $make('BAP-MAHAL', '900000', '2026-11-10 03:00:00');
    $make('BAP-MURAH', '1000', '2026-11-15 03:00:00');

    $this->actingAs(($this->as)('kepala_gudang'))->get('/pemusnahan?sort=-total_cost')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.number', 'BAP-MURAH')->missing('rows.0.total_cost'));
    $this->actingAs(($this->as)('owner'))->get('/pemusnahan?sort=-total_cost')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.number', 'BAP-MAHAL')->where('rows.0.total_cost', '900000.00'));
});
