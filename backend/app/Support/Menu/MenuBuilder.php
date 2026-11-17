<?php

namespace App\Support\Menu;

use App\Domain\Approval\Support\ApprovalVisibility;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Menu web dari matriks 06 §8. Server yang memutuskan item mana yang tampil (06 §10): permission
 * pengguna DAN route modulnya sudah ada — modul yang belum dibangun otomatis tersembunyi, jadi
 * menu tumbuh sendiri seiring task berjalan. Menyembunyikan menu hanya UX; route tetap dijaga
 * middleware `can:` masing-masing.
 */
final class MenuBuilder
{
    /**
     * Seksi sidebar (judul kecil tanpa klik) per grup; keputusan Mas 16 Nov 2026 (06 §8). HRIS =
     * Unit Kerja, Kepegawaian, Presensi (lalu Cuti, Payroll, dst. di Fase HR); Sistem = akses,
     * pengaturan, audit. Modul bisnis nanti cukup menambah seksi baru tanpa merombak yang ada.
     * Distribusi = grup Paket A (15 §6.5: Penjualan, Persediaan, Pembelian, Master), paling atas
     * (keputusan Mas 16 Nov 2026).
     *
     * @var array<string, string>
     */
    public const SECTIONS = [
        'Penjualan' => 'Distribusi',
        'Persediaan' => 'Distribusi',
        'Pembelian' => 'Distribusi',
        'Master' => 'Distribusi',
        'Unit Kerja' => 'HRIS',
        'Kepegawaian' => 'HRIS',
        'Presensi' => 'HRIS',
        'Pengguna & Akses' => 'Sistem',
        'Pengaturan' => 'Sistem',
        'Audit' => 'Sistem',
    ];

    /**
     * [grup, judul, kunci ikon (dipetakan di frontend), permission syarat, nama route].
     * Grup null = tanpa judul grup (Dasbor). Urutan di sini = urutan di sidebar.
     *
     * @var list<array{0: string|null, 1: string, 2: string, 3: string, 4: string}>
     */
    public const ITEMS = [
        [null, 'Dasbor', 'dashboard', 'dashboard.view', 'admin.dashboard'],
        // Persetujuan lintas modul (15 §6.5, PA-27): menu untuk pemegang `approvals.view`. Calon
        // penyetuju tanpa izin itu tetap bisa membuka /persetujuan (tautan notifikasi).
        [null, 'Persetujuan', 'approval', 'approvals.view', 'admin.approvals.index'],
        // Seksi Distribusi (Paket A). Grup Penjualan/Persediaan/Pembelian disisipkan SEBELUM Master
        // oleh task pemiliknya (urutan 15 §6.5); item Master lain menyusul (Produk T-070, Izin Edar T-071, Impor Master T-085).
        // T-073: Pelanggan & Area Penjualan (Verifikasi Kios T-074, Daftar Harga T-075, Promo T-076 menyusul
        // di antaranya sesuai urutan 15 §6.5). Menu ini juga membuka login web pemegang `customers.view` (BR-017).
        ['Penjualan', 'Pelanggan', 'customer', 'customers.view', 'admin.customers.index'],
        // T-074: antrean verifikasi kios untuk penyetuju (`customers.verify`, 15 §6.5).
        ['Penjualan', 'Verifikasi Kios', 'customer_verification', 'customers.verify', 'admin.customer-verifications.index'],
        ['Penjualan', 'Area Penjualan', 'sales_area', 'sales_areas.view', 'admin.sales-areas.index'],
        ['Penjualan', 'Daftar Harga', 'price_list', 'prices.view', 'admin.price-lists.index'],
        ['Penjualan', 'Promo', 'promotion', 'promotions.view', 'admin.promotions.index'],
        // Persediaan (15 §6.5): laporan stok T-087 (Posisi, Kartu, Stok Minimum, Telusur Batch) + Laporan
        // Audit Stok khusus `stock.audit_reports` (PA-72); Mutasi T-082, opname T-083, karantina/pemusnahan T-084.
        ['Persediaan', 'Posisi Stok', 'stock_position', 'stock.view', 'admin.stock.index'],
        ['Persediaan', 'Kartu Stok', 'stock_card', 'stock.view', 'admin.stock-movements.index'],
        ['Persediaan', 'Stok Minimum', 'stock_level', 'stock.view', 'admin.stock-levels.index'],
        ['Persediaan', 'Mutasi Stok', 'stock_transfer', 'stock_transfers.view', 'admin.stock-transfers.index'],
        ['Persediaan', 'Stock Opname', 'stock_opname', 'stock_opnames.view', 'admin.stock-opnames.index'],
        ['Persediaan', 'Karantina', 'quarantine', 'stock_quarantine.manage', 'admin.stock-batches.index'],
        ['Persediaan', 'Pemusnahan', 'disposal', 'stock_disposals.manage', 'admin.disposal-reports.index'],
        ['Persediaan', 'Nomor Seri', 'serial', 'stock.view', 'admin.serials.index'],
        ['Persediaan', 'Telusur Batch', 'stock_trace', 'stock.view', 'admin.stock.trace'],
        ['Persediaan', 'Laporan Audit Stok', 'stock_audit', 'stock.audit_reports', 'admin.stock-audit.fefo.index'],
        ['Pembelian', 'Purchase Order', 'purchase_order', 'purchase_orders.view', 'admin.purchase-orders.index'],
        ['Pembelian', 'Penerimaan Barang', 'goods_receipt', 'goods_receipts.view', 'admin.goods-receipts.index'],
        ['Pembelian', 'Retur ke Prinsipal', 'purchase_return', 'purchase_returns.manage', 'admin.purchase-returns.index'],
        ['Pembelian', 'Supplier', 'supplier', 'suppliers.view', 'admin.suppliers.index'],
        ['Pembelian', 'Prinsipal', 'principal', 'suppliers.view', 'admin.principals.index'],
        ['Master', 'Produk', 'product', 'products.view', 'admin.products.index'],
        ['Master', 'Izin Edar', 'registration', 'products.view', 'admin.product-registrations.index'],
        ['Master', 'Kategori', 'category', 'products.view', 'admin.product-categories.index'],
        // T-068: lokasi stok (gudang/toko/canvas/TRANSIT) & kendaraan minimal; daftar ber-scope Stok (15 §6.2).
        ['Master', 'Lokasi Stok', 'warehouse', 'stock_locations.view', 'admin.stock-locations.index'],
        ['Master', 'Kendaraan', 'vehicle', 'vehicles.view', 'admin.vehicles.index'],
        // T-085: riwayat & mulai impor master (produk, kios, harga, stok minimum); halaman sama dengan riwayat impor pegawai.
        ['Master', 'Impor Master', 'import', 'master_imports.manage', 'admin.imports.index'],
        ['Unit Kerja', 'Lokasi', 'location', 'locations.view', 'admin.locations.index'],
        ['Unit Kerja', 'Departemen', 'department', 'departments.view', 'admin.departments.index'],
        ['Unit Kerja', 'Jabatan', 'position', 'positions.view', 'admin.positions.index'],
        ['Kepegawaian', 'Pegawai', 'employee', 'employees.view', 'admin.employees.index'],
        ['Kepegawaian', 'Penugasan', 'assignment', 'assignments.view', 'admin.assignments.index'],
        ['Kepegawaian', 'Impor Pegawai', 'import', 'employees.import', 'admin.imports.index'],
        ['Presensi', 'Presensi Harian', 'attendance', 'attendance.view', 'admin.attendance.index'],
        ['Presensi', 'Rekap Bulanan', 'recap', 'attendance.view', 'admin.attendance-summary.index'],
        ['Presensi', 'Koreksi Presensi', 'correction', 'attendance_corrections.view', 'admin.attendance-corrections.index'],
        ['Presensi', 'Jadwal', 'schedule', 'schedules.view', 'admin.schedules.index'],
        ['Presensi', 'Shift', 'shift', 'shifts.view', 'admin.shifts.index'],
        ['Presensi', 'Hari Libur', 'holiday', 'holidays.view', 'admin.holidays.index'],
        ['Presensi', 'Tugas Luar Kota', 'trip', 'field_trips.view', 'admin.field-trips.index'],
        // Perangkat = HP Pakis Pro yang dipakai presensi; dikelola bersama alur presensi (keputusan Mas 16 Nov 2026).
        ['Presensi', 'Perangkat', 'device', 'devices.view', 'admin.devices.index'],
        ['Pengguna & Akses', 'Pengguna', 'user', 'users.view', 'admin.users.index'],
        ['Pengguna & Akses', 'Role & Hak Akses', 'role', 'roles.view', 'admin.roles.index'],
        ['Pengaturan', 'Pengaturan Umum', 'settings', 'settings.view', 'admin.settings.edit'],
        ['Pengaturan', 'Penomoran', 'sequence', 'sequences.view', 'admin.sequences.index'],
        ['Pengaturan', 'Referensi', 'reference', 'references.view', 'admin.references.index'],
        ['Pengaturan', 'Wilayah', 'region', 'regions.view', 'admin.regions.index'],
        ['Pengaturan', 'Aturan Persetujuan', 'approval_rule', 'approvals.configure', 'admin.approval-rules.index'],
        ['Audit', 'Audit Log', 'audit', 'audit.view', 'admin.audit.index'],
        ['Audit', 'Log Keamanan', 'security', 'audit.view', 'admin.security-events.index'],
    ];

    /** @return list<string> permission yang membuka setidaknya satu menu (BR-017). */
    public static function permissions(): array
    {
        return array_values(array_unique(array_column(self::ITEMS, 3)));
    }

    /**
     * @return list<array{section: string|null, title: string|null, items: list<array{title: string, href: string, icon: string, badge?: int}>}>
     */
    public function for(User $user): array
    {
        $groups = [];

        foreach (self::ITEMS as [$group, $title, $icon, $permission, $route]) {
            if (! Route::has($route)) {
                continue;
            }
            $badge = self::badge($route, $user);
            // PA-27: item berangka (Persetujuan) juga tampil bagi calon penyetuju tanpa izinnya selama
            // ada yang menunggu dia — route-nya memang terbuka untuk mereka (tab "Menunggu saya").
            if (! $user->can($permission) && ! ($badge !== null && $badge > 0)) {
                continue;
            }

            $key = $group ?? '';
            $groups[$key] ??= ['section' => $group === null ? null : self::SECTIONS[$group], 'title' => $group, 'items' => []];
            // Relatif: aman dari perbedaan host APP_URL dan cocok untuk penanda item aktif.
            $item = ['title' => $title, 'href' => route($route, absolute: false), 'icon' => $icon];
            if ($badge !== null) {
                $item['badge'] = $badge;
            }
            $groups[$key]['items'][] = $item;
        }

        return array_values($groups);
    }

    /**
     * Angka di samping menu (15 §6.5 "dengan angka menunggu"). Dihitung ApprovalVisibility yang sama
     * dengan tab "Menunggu saya", jadi angka menu selalu = isi tab itu.
     */
    private static function badge(string $route, User $user): ?int
    {
        return match ($route) {
            'admin.approvals.index' => app(ApprovalVisibility::class)->pendingCountFor($user),
            default => null,
        };
    }
}
