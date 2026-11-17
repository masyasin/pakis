<?php

namespace App\Domain\Access\Support;

/**
 * Daftar permission resmi (06 §3, ADR-011). SATU-SATUNYA sumber permission: PermissionSeeder
 * membuatnya dari sini, UI hanya bisa menetapkan permission yang ada ke role (BR-030).
 * PermissionCatalogTest mem-parse tabel 06 §3 dan membandingkan dua arah.
 */
final class PermissionCatalog
{
    /**
     * Modul → [label modul, [permission → label]]. Urutan = urutan tampil di matriks role.
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    private const MODULES = [
        'dashboard' => ['Dasbor', ['dashboard.view' => 'Lihat dasbor']],
        'settings' => ['Pengaturan', ['settings.view' => 'Lihat', 'settings.update' => 'Ubah']],
        'sequences' => ['Penomoran', ['sequences.view' => 'Lihat', 'sequences.update' => 'Ubah pola']],
        'references' => ['Referensi', ['references.view' => 'Lihat', 'references.manage' => 'Kelola']],
        'users' => ['Pengguna', [
            'users.view' => 'Lihat',
            'users.create' => 'Tambah',
            'users.update' => 'Ubah',
            'users.deactivate' => 'Nonaktifkan',
            'users.reset_password' => 'Reset password',
            'users.reset_mfa' => 'Reset MFA',
            'users.export' => 'Ekspor',
        ]],
        'roles' => ['Role & hak akses', ['roles.view' => 'Lihat', 'roles.manage' => 'Kelola role & matriks']],
        'audit' => ['Audit', ['audit.view' => 'Lihat audit & log keamanan', 'audit.export' => 'Ekspor']],
        'regions' => ['Wilayah', ['regions.view' => 'Lihat']],
        'locations' => ['Lokasi', ['locations.view' => 'Lihat', 'locations.manage' => 'Kelola']],
        'departments' => ['Departemen', ['departments.view' => 'Lihat', 'departments.manage' => 'Kelola']],
        'positions' => ['Jabatan', ['positions.view' => 'Lihat', 'positions.manage' => 'Kelola']],
        'employees' => ['Pegawai', [
            'employees.view' => 'Lihat',
            'employees.create' => 'Tambah',
            'employees.update' => 'Ubah',
            'employees.delete' => 'Hapus',
            'employees.view_sensitive' => 'Buka NIK lengkap',
            'employees.offboard' => 'Nonaktifkan / aktifkan kembali',
            'employees.import' => 'Impor',
            'employees.export' => 'Ekspor',
        ]],
        'assignments' => ['Penugasan', ['assignments.view' => 'Lihat', 'assignments.manage' => 'Kelola']],
        'devices' => ['Perangkat', ['devices.view' => 'Lihat', 'devices.manage' => 'Setujui / tolak / cabut']],
        'shifts' => ['Shift', ['shifts.view' => 'Lihat', 'shifts.manage' => 'Kelola']],
        'schedules' => ['Jadwal', ['schedules.view' => 'Lihat', 'schedules.manage' => 'Kelola pola & pengecualian']],
        'holidays' => ['Hari libur', ['holidays.view' => 'Lihat', 'holidays.manage' => 'Kelola']],
        'attendance' => ['Presensi', [
            'attendance.view' => 'Lihat',
            'attendance.view_photo' => 'Lihat foto selfie',
            'attendance.export' => 'Ekspor',
            'attendance.self' => 'Presensi & lihat milik sendiri (Pakis Pro)',
        ]],
        'attendance_corrections' => ['Koreksi presensi', [
            'attendance_corrections.view' => 'Lihat',
            'attendance_corrections.create' => 'Ajukan milik sendiri',
            'attendance_corrections.approve' => 'Setujui / tolak',
        ]],
        'field_trips' => ['Tugas luar kota', [
            'field_trips.view' => 'Lihat',
            'field_trips.manage' => 'Buat / ubah / batalkan',
            'field_trips.request' => 'Ajukan milik sendiri (Pakis Pro)',
            'field_trips.approve' => 'Setujui / tolak pengajuan',
        ]],

        // Paket A — Master & Gudang (15 §6.3, T-067). Nominal ambang `_high` dibaca dari setting/aturan
        // persetujuan, jadi labelnya sengaja tidak menyebut angka.
        'approvals' => ['Persetujuan', [
            'approvals.view' => 'Lihat menu & daftar persetujuan',
            'approvals.configure' => 'Atur aturan persetujuan',
        ]],
        'stock_locations' => ['Lokasi stok & kendaraan', [
            'stock_locations.view' => 'Lihat lokasi stok',
            'stock_locations.manage' => 'Kelola lokasi stok & rak',
            'vehicles.view' => 'Lihat kendaraan',
            'vehicles.manage' => 'Kelola kendaraan',
        ]],
        'products' => ['Produk', [
            'products.view' => 'Lihat produk & kategori',
            'products.manage' => 'Kelola produk & kategori',
            'product_registrations.manage' => 'Kelola izin edar',
        ]],
        'suppliers' => ['Prinsipal & supplier', [
            'suppliers.view' => 'Lihat',
            'suppliers.manage' => 'Kelola',
        ]],
        'customers' => ['Area & pelanggan', [
            'sales_areas.view' => 'Lihat area penjualan',
            'sales_areas.manage' => 'Kelola area penjualan',
            'customers.view' => 'Lihat pelanggan',
            'customers.export' => 'Ekspor pelanggan (HP pemilik)',
            'customers.manage' => 'Tambah / ubah pelanggan',
            'customers.verify' => 'Verifikasi kios',
            'customers.merge' => 'Gabungkan pelanggan ganda',
            'customers.change_phone' => 'Ganti HP pemilik',
            'customers.change_phone_approve' => 'Setujui ganti HP pemilik',
            'credit_limits.set' => 'Atur limit kredit',
            'credit_limits.set_high' => 'Atur limit kredit di atas ambang',
        ]],
        'pricing' => ['Harga & promo', [
            'prices.view' => 'Lihat daftar harga',
            'prices.manage' => 'Susun & ajukan daftar harga',
            'prices.publish' => 'Terbitkan daftar harga',
            'promotions.view' => 'Lihat promo',
            'promotions.manage' => 'Susun & ajukan promo',
            'promotions.approve' => 'Setujui promo',
        ]],
        'purchasing' => ['Pembelian', [
            'purchase_orders.view' => 'Lihat purchase order',
            'purchase_orders.create' => 'Buat purchase order',
            'purchase_orders.approve' => 'Setujui purchase order',
            'purchase_orders.approve_high' => 'Setujui purchase order di atas ambang',
            'goods_receipts.view' => 'Lihat penerimaan barang',
            'goods_receipts.create' => 'Catat penerimaan barang',
            'goods_receipts.approve_short_shelf_life' => 'Setujui terima barang berumur simpan pendek',
            'purchase_returns.manage' => 'Kelola retur ke prinsipal',
            'purchase_returns.approve' => 'Setujui retur ke prinsipal',
        ]],
        'stock' => ['Stok', [
            'stock.view' => 'Lihat posisi & kartu stok',
            'stock.view_cost' => 'Lihat HPP / biaya stok',
            'stock.export' => 'Ekspor stok (dengan HPP)',
            // T-087 (PA-72): laporan pelanggaran FEFO & selisih opname per penghitung, bawaan hanya Owner.
            'stock.audit_reports' => 'Laporan audit stok (pelanggaran FEFO, selisih per penghitung)',
            'stock_transfers.view' => 'Lihat mutasi stok',
            'stock_transfers.create' => 'Buat mutasi stok',
            'stock_transfers.receive' => 'Terima mutasi stok',
            'stock_transfers.approve_variance' => 'Setujui selisih mutasi',
            'stock_opnames.view' => 'Lihat stock opname',
            'stock_opnames.manage' => 'Kelola stock opname',
            'stock_opnames.approve_variance' => 'Setujui selisih opname',
            'stock_opnames.approve_variance_high' => 'Setujui selisih opname di atas ambang',
            'stock_quarantine.manage' => 'Karantina batch',
            'stock_quarantine.release' => 'Setujui lepas karantina',
            'stock_disposals.manage' => 'Kelola pemusnahan',
            'stock_disposals.approve' => 'Setujui pemusnahan',
            'serials.manage' => 'Kelola nomor seri',
            'serials.approve_swap' => 'Setujui tukar nomor seri',
        ]],
        'master_imports' => ['Impor master', [
            'master_imports.manage' => 'Impor data master',
        ]],
    ];

    /**
     * Modul Paket A (15 §6.3). Dipakai migrasi data `add_paket_a_permissions_to_builtin_roles`
     * dan PaketAMatrixTest untuk memisahkan izin Paket A dari izin Fase 0 (06 §3).
     */
    public const PAKET_A_MODULES = [
        'approvals', 'stock_locations', 'products', 'suppliers', 'customers', 'pricing', 'purchasing', 'stock', 'master_imports',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        $names = [];
        foreach (self::MODULES as [, $permissions]) {
            array_push($names, ...array_keys($permissions));
        }

        return $names;
    }

    /** @return list<string> izin Paket A (15 §6.3), urutan katalog. */
    public static function paketANames(): array
    {
        $names = [];
        foreach (self::PAKET_A_MODULES as $module) {
            array_push($names, ...array_keys(self::MODULES[$module][1]));
        }

        return $names;
    }

    /**
     * Untuk matriks di form role.
     *
     * @return list<array{module: string, label: string, permissions: list<array{name: string, label: string}>}>
     */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::MODULES as $module => [$label, $permissions]) {
            $groups[] = [
                'module' => $module,
                'label' => $label,
                'permissions' => array_map(
                    fn (string $name, string $permissionLabel) => ['name' => $name, 'label' => $permissionLabel],
                    array_keys($permissions),
                    array_values($permissions),
                ),
            ];
        }

        return $groups;
    }
}
