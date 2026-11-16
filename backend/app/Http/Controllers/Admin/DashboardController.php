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
use App\Domain\Device\Listings\DeviceListing;
use App\Domain\Device\Models\Device;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Listings\EmployeeListing;
use App\Domain\Employee\Models\Employee;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingQuery;
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
    public function __invoke(Request $request, TodaySummary $summary, AttendanceInsights $insights, DataScope $scope, ApprovalVisibility $approvals): Response
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
                $user->can('employees.view') ? self::item(
                    'no_account',
                    (new EmployeeListing)->exportQuery($user),
                    new EmployeeListing,
                    'admin.employees.index',
                    ['filter' => ['has_account' => '0']],
                ) : null,
            ])),
            'can' => ['view_attendance' => $user->can('attendance.view')],
        ]);
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
