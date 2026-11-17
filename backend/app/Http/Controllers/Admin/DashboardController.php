<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Approval\Listings\ApprovalListing;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Support\ApprovalVisibility;
use App\Domain\Attendance\Listings\AttendanceCorrectionListing;
use App\Domain\Attendance\Listings\ScheduleListing;
use App\Domain\Attendance\Queries\AttendanceInsights;
use App\Domain\Attendance\Queries\TodaySummary;
use App\Domain\Catalog\Listings\ProductListing;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Listings\CustomerVerificationListing;
use App\Domain\Device\Listings\DeviceListing;
use App\Domain\Device\Models\Device;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Listings\EmployeeListing;
use App\Domain\Employee\Models\Employee;
use App\Domain\Inventory\Enums\DisposalReportStatus;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Imports\OpeningStockImportType;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Listings\DisposalReportListing;
use App\Domain\Inventory\Listings\StockBatchListing;
use App\Domain\Inventory\Listings\StockOpnameListing;
use App\Domain\Inventory\Listings\StockTransferListing;
use App\Domain\Inventory\Models\DisposalReport;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Queries\BelowMinimumSummary;
use App\Domain\Inventory\Queries\MissingStockLevelSummary;
use App\Domain\Inventory\Queries\NearExpirySummary;
use App\Domain\Inventory\Queries\StockCharts;
use App\Domain\Pricing\Listings\PriceListListing;
use App\Domain\Pricing\Listings\PromotionListing;
use App\Domain\Pricing\Models\PriceList;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Enums\PurchaseReturnStatus;
use App\Domain\Purchasing\Listings\GoodsReceiptListing;
use App\Domain\Purchasing\Listings\PurchaseOrderListing;
use App\Domain\Purchasing\Listings\PurchaseReturnListing;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Import\ImportRegistry;
use App\Support\Import\ImportStatus;
use App\Support\Import\Listings\ImportBatchListing;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingQuery;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dasbor (05 §4.2, 07 §4, FR-040, T-044). Semua angka dalam scope pengguna. Daftar "Perlu
 * tindakan" dihitung dengan LISTING YANG SAMA dengan halaman tujuannya + filter yang dibawa di URL,
 * jadi angka di kartu = jumlah baris saat kartu diklik (URL state, 07 §3.3). Kartu yang izinnya
 * tidak dimiliki tidak dikirim sama sekali.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, TodaySummary $summary, AttendanceInsights $insights, DataScope $scope, ApprovalVisibility $approvals, NearExpirySummary $nearExpiry, BelowMinimumSummary $belowMin, MissingStockLevelSummary $unsetLevels, StockCharts $stockCharts): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        // Rentang grafik dari URL (?dari=YYYY-MM-DD&sampai=YYYY-MM-DD) agar bertahan saat dimuat
        // ulang. AttendanceInsights::range() merapikan nilai rusak/terlalu panjang; tidak pernah
        // diteruskan mentah ke query.
        $from = $request->query('dari');
        $to = $request->query('sampai');
        $canAttendance = $user->can('attendance.view');

        return Inertia::render('dashboard', [
            'attendance' => $canAttendance ? $summary->for($user) : null,
            'charts' => $canAttendance
                ? $insights->for($user, is_string($from) ? $from : null, is_string($to) ? $to : null)
                : null,
            // Jeda muat ulang otomatis (detik, 0 = mati) dibaca dari pengaturan, bukan konstanta.
            'refresh_seconds' => (int) setting('ui.dashboard_refresh_seconds'),
            'pending' => array_values(array_filter([
                // "Menunggu persetujuan saya" (15 §7.4). Basis sama dengan ApprovalController::index
                // tab "Menunggu saya" (calon penyetuju dihitung mesin, ApprovalVisibility). PA-27:
                // calon penyetuju tanpa `approvals.view` (atasan langsung, kepala lokasi) tetap boleh
                // membuka tab itu, jadi kartu juga muncul untuknya selama ada yang menunggu.
                $user->can('approvals.view') || $approvals->pendingCountFor($user) > 0 ? self::item(
                    'approvals',
                    $approvals->constrainTab(ApprovalRequest::query(), $user, ApprovalVisibility::TAB_MINE),
                    new ApprovalListing,
                    'admin.approvals.index',
                    ['filter' => ['tab' => ApprovalVisibility::TAB_MINE]],
                ) : null,
                $user->can('attendance_corrections.view') ? self::item(
                    'corrections',
                    (new AttendanceCorrectionListing)->exportQuery($user),
                    new AttendanceCorrectionListing,
                    'admin.attendance-corrections.index',
                    ['filter' => ['status' => ['pending']]],
                ) : null,
                $user->can('devices.view') ? self::item(
                    'devices',
                    Device::query()->visibleTo($user),
                    new DeviceListing,
                    'admin.devices.index',
                    ['filter' => ['status' => ['pending']]],
                ) : null,
                $user->can('schedules.view') ? self::item(
                    'unscheduled',
                    // Basis sama dengan ScheduleController::index (pegawai aktif dalam scope).
                    $scope->apply(Employee::query()->where('employees.status', EmployeeStatus::Active->value), $user, 'employees.id'),
                    new ScheduleListing,
                    'admin.schedules.index',
                    ['filter' => ['unscheduled' => '1']],
                ) : null,
                // Izin edar akan habis (15 §7.4, T-071): jumlah PRODUK yang izin berlakunya habis
                // ≤ hari ini + `inventory.registration_reminder_days`; tautan ke daftar produk tersaring.
                $user->can('products.view') ? self::item(
                    'registrations_expiring',
                    Product::query(),
                    new ProductListing,
                    'admin.products.index',
                    ['filter' => ['registration_status' => 'expiring']],
                ) : null,
                // PO terlambat (15 §7.4, T-079): PO terbuka lewat perkiraan datang, scope Stok gudang tujuan
                // (basis sama dengan PurchaseOrderController::index).
                $user->can('purchase_orders.view') ? self::item(
                    'po_overdue',
                    $scope->forStock(PurchaseOrder::query(), $user, 'purchase_orders.stock_location_id'),
                    new PurchaseOrderListing,
                    'admin.purchase-orders.index',
                    ['filter' => ['overdue' => '1']],
                ) : null,
                // Mutasi belum diterima (15 §7.4, T-082): `shipped` lewat `inventory.transfer_receive_reminder_hours`,
                // hanya mutasi yang TUJUAN-nya dalam scope Stok penerima (yang bisa ia terima).
                $user->can('stock_transfers.receive') ? self::item(
                    'transfers_unreceived',
                    $scope->forStock(StockTransfer::query(), $user, 'stock_transfers.to_stock_location_id'),
                    new StockTransferListing,
                    'admin.stock-transfers.index',
                    ['filter' => ['overdue' => '1']],
                ) : null,
                $user->can('employees.view') ? self::item(
                    'no_account',
                    (new EmployeeListing)->exportQuery($user),
                    new EmployeeListing,
                    'admin.employees.index',
                    ['filter' => ['has_account' => '0']],
                ) : null,
                // Kartu tambahan Paket A (T-087A, PA-73). Basis & filter = halaman tujuan; kartu baru
                // bernilai 0 tidak dikirim agar daftar tidak ramai (kartu lama di atas tetap tampil).
                ...self::nonZero([
                    $user->can('stock_quarantine.manage') ? self::item('quarantine_batches', StockBatchListing::query($user), new StockBatchListing,
                        'admin.stock-batches.index', ['filter' => ['status' => [StockBatchStatus::Quarantine->value]]]) : null,
                    $user->can('stock_quarantine.manage') ? self::item('expired_batches', StockBatchListing::query($user), new StockBatchListing,
                        'admin.stock-batches.index', ['filter' => ['status' => [StockBatchStatus::Expired->value]]]) : null,
                    $user->can('stock_opnames.view') ? self::item('opnames_open', $scope->forStock(StockOpname::query(), $user, 'stock_opnames.stock_location_id'),
                        new StockOpnameListing, 'admin.stock-opnames.index', ['filter' => ['status' => [
                            StockOpnameStatus::Counting->value, StockOpnameStatus::Review->value, StockOpnameStatus::PendingApproval->value,
                        ]]]) : null,
                    $user->can('stock_disposals.manage') ? self::item('disposals_approved', $scope->forStock(DisposalReport::query(), $user, 'disposal_reports.stock_location_id'),
                        new DisposalReportListing, 'admin.disposal-reports.index', ['filter' => ['status' => [DisposalReportStatus::Approved->value]]]) : null,
                    $user->can('purchase_returns.manage') ? self::item('returns_open', $scope->forStock(PurchaseReturn::query(), $user, 'purchase_returns.stock_location_id'),
                        new PurchaseReturnListing, 'admin.purchase-returns.index', ['filter' => ['status' => [
                            PurchaseReturnStatus::Approved->value, PurchaseReturnStatus::Shipped->value,
                        ]]]) : null,
                    $user->can('goods_receipts.view') ? self::item('receipts_draft', $scope->forStock(GoodsReceipt::query(), $user, 'goods_receipts.stock_location_id'),
                        new GoodsReceiptListing, 'admin.goods-receipts.index', ['filter' => ['status' => [GoodsReceiptStatus::Draft->value]]]) : null,
                    $user->can('customers.verify') ? self::item('customers_unverified', CustomerVerificationListing::query($user), new CustomerVerificationListing,
                        'admin.customer-verifications.index', ['filter' => ['status' => [VerificationStatus::Pending->value]]]) : null,
                    $user->can('prices.manage') ? self::item('price_drafts', PriceList::query(), new PriceListListing,
                        'admin.price-lists.index', ['filter' => ['has_draft' => '1']]) : null,
                    $user->can('promotions.manage') ? self::item('promos_ending', Promotion::query(), new PromotionListing,
                        'admin.promotions.index', ['filter' => ['ending_soon' => '1']]) : null,
                    ImportRegistry::allowedFor($user) !== [] ? self::item('imports_pending', ImportBatchListing::query($user), new ImportBatchListing,
                        'admin.imports.index', ['filter' => ['status' => [ImportStatus::Validated->value, ImportStatus::Failed->value]]]) : null,
                    $user->can('prices.manage') && $user->can('products.view') ? self::item('products_unpriced', Product::query(), new ProductListing,
                        'admin.products.index', ['filter' => ['price_status' => 'missing']]) : null,
                ]),
            ])),
            // Banner "Saldo awal masih dibuka" (T-087A): hanya selama saldo awal belum ditutup, bagi yang
            // boleh menutupnya; nilai rupiah hanya untuk pemegang `stock.view_cost` (SEC-107).
            'opening_balance' => $user->can('settings.update') && setting('inventory.opening_balance_open') === true
                ? self::openingBalance($user, $scope) : null,
            // Produk tanpa stok minimum per lokasi (T-087A): tiap baris membuka Stok Minimum lokasi itu, filter "belum diatur".
            'levels_unset' => $user->can('stock_locations.manage') ? $unsetLevels->for($user) : null,
            // Grafik stok (T-087A): rentang tren mutasi = rentang grafik presensi (?dari/?sampai).
            'stock_charts' => $user->can('stock.view')
                ? $stockCharts->for($user, ...array_slice(AttendanceInsights::range(is_string($from) ? $from : null, is_string($to) ? $to : null), 0, 2))
                : null,
            // Stok mendekati ED (15 §7.4, T-078): per lokasi stok dalam scope; nilai HPP hanya untuk
            // pemegang `stock.view_cost` (PA-64).
            'near_expiry' => $user->can('stock.view') ? $nearExpiry->for($user) : null,
            // Stok di bawah minimum (15 §7.4, T-087): 10 produk × lokasi terbawah dalam scope Stok.
            'below_min' => $user->can('stock.view') ? $belowMin->for($user) : null,
            'can' => ['view_attendance' => $user->can('attendance.view')],
        ]);
    }

    /**
     * Kartu baru T-087A: buang yang tidak berizin (null) dan yang bernilai 0.
     *
     * @param  list<array{key: string, count: int, href: string}|null>  $items
     * @return list<array{key: string, count: int, href: string}>
     */
    private static function nonZero(array $items): array
    {
        return array_values(array_filter($items, fn (?array $i) => $i !== null && $i['count'] > 0));
    }

    /** @return array{count: int, value: string|null, href: string} */
    private static function openingBalance(User $user, DataScope $scope): array
    {
        // Saring lewat `source_type` (index smov_source_idx): mutasi saldo awal hanya berasal dari impor
        // saldo awal (T-086). Tanpa itu, 1 juta mutasi = pindai penuh ±4,5 detik per query (uji volume T-087A).
        $movements = $scope->forStock(StockMovement::query()
            ->where('stock_movements.source_type', OpeningStockImportType::SOURCE_TYPE)
            ->where('stock_movements.movement_type', MovementType::OpeningBalance->value), $user, 'stock_movements.stock_location_id');
        $row = $movements->toBase()
            ->selectRaw('COUNT(*) AS movements')
            ->selectRaw('CAST(COALESCE(SUM(ROUND(stock_movements.qty * stock_movements.unit_cost, 2)), 0) AS CHAR) AS total_value')
            ->first();

        return [
            'count' => (int) ($row->movements ?? 0),
            'value' => $user->can('stock.view_cost') ? (string) BigDecimal::of((string) ($row->total_value ?? '0'))->toScale(2) : null,
            'href' => route('admin.settings.edit', ['group' => 'inventory']),
        ];
    }

    /**
     * @param  Builder<*>  $base
     * @param  array<string, mixed>  $query
     * @return array{key: string, count: int, href: string}
     */
    private static function item(string $key, Builder $base, HasListing $listing, string $route, array $query): array
    {
        $count = ListingQuery::apply($base, $listing, Request::create('/', 'GET', $query))->query()->count();

        return ['key' => $key, 'count' => $count, 'href' => route($route, $query)];
    }
}
