<?php

namespace App\Domain\Access\Support;

use App\Domain\Access\Enums\DataScope;

/**
 * Role bawaan (06 §2, 15 §6.1) dan matriks permission default (06 §4, 15 §6.4) untuk RoleSeeder.
 * RoleSeederTest memastikan hasil seeder identik dengan `tests/Fixtures/permission_matrix.php`,
 * dan fixture itu identik dengan tabel dokumen.
 */
final class RoleCatalog
{
    public const SUPER_ADMIN = 'super_admin';

    public const OWNER = 'owner';

    public const PEGAWAI = 'pegawai';

    /** Role yang permission-nya terkunci penuh (BR-031). */
    public const LOCKED = [self::SUPER_ADMIN, self::OWNER];

    /** Role baru Paket A (15 §6.1); dibuat migrasi data bila instalasi lama belum punya. */
    public const PAKET_A_ROLES = ['admin_penjualan', 'kepala_gudang', 'staf_gudang', 'purchasing'];

    /**
     * Izin Fase 0 (06 §4) per role yang TIDAK terkunci, sebagai daftar eksplisit (PA-11). Jangan
     * kembali ke pola "semua kecuali": izin baru di katalog (Paket A–G) tidak boleh otomatis jatuh
     * ke role mana pun selain owner/super_admin.
     *
     * @var array<string, list<string>>
     */
    private const FASE_0 = [
        'administrator' => [
            'dashboard.view', 'settings.view', 'settings.update', 'sequences.view', 'sequences.update',
            'references.view', 'references.manage', 'users.view', 'users.create', 'users.update',
            'users.deactivate', 'users.reset_password', 'users.reset_mfa', 'users.export', 'roles.view',
            'roles.manage', 'audit.view', 'regions.view', 'locations.view', 'locations.manage',
            'departments.view', 'departments.manage', 'positions.view', 'positions.manage', 'employees.view',
            'assignments.view', 'devices.view', 'devices.manage', 'shifts.view', 'schedules.view',
            'holidays.view', 'attendance.view', 'attendance.self', 'attendance_corrections.view',
            'attendance_corrections.create', 'field_trips.view', 'field_trips.request',
        ],
        'hrd' => [
            'dashboard.view', 'references.view', 'references.manage', 'users.view', 'users.create',
            'users.update', 'users.deactivate', 'users.reset_password', 'regions.view', 'locations.view',
            'locations.manage', 'departments.view', 'departments.manage', 'positions.view', 'positions.manage',
            'employees.view', 'employees.create', 'employees.update', 'employees.delete',
            'employees.view_sensitive', 'employees.offboard', 'employees.import', 'employees.export',
            'assignments.view', 'assignments.manage', 'devices.view', 'devices.manage', 'shifts.view',
            'shifts.manage', 'schedules.view', 'schedules.manage', 'holidays.view', 'holidays.manage',
            'attendance.view', 'attendance.view_photo', 'attendance.export', 'attendance.self',
            'attendance_corrections.view', 'attendance_corrections.create', 'attendance_corrections.approve',
            'field_trips.view', 'field_trips.manage', 'field_trips.request', 'field_trips.approve',
        ],
        'kepala_lokasi' => [
            'dashboard.view', 'locations.view', 'employees.view', 'assignments.view', 'devices.view', 'shifts.view',
            'schedules.view', 'schedules.manage', 'holidays.view', 'attendance.view', 'attendance.view_photo',
            'attendance.export', 'attendance.self', 'attendance_corrections.view', 'attendance_corrections.create',
            'attendance_corrections.approve', 'field_trips.view', 'field_trips.manage', 'field_trips.request',
            'field_trips.approve',
        ],
        'supervisor' => [
            'dashboard.view', 'employees.view', 'assignments.view', 'schedules.view', 'holidays.view',
            'attendance.view', 'attendance.view_photo', 'attendance.self', 'attendance_corrections.view',
            'attendance_corrections.create', 'attendance_corrections.approve', 'field_trips.view',
            'field_trips.manage', 'field_trips.request', 'field_trips.approve',
        ],
        self::PEGAWAI => ['attendance.self', 'attendance_corrections.create', 'field_trips.request'],
        // Role Paket A: dari izin Fase 0 hanya dasbor, karena login web selalu mendarat di /dasbor
        // (PA-25, 14 §10.1); tanpa ini mereka kena 403 tepat setelah login.
        'admin_penjualan' => ['dashboard.view'],
        'kepala_gudang' => ['dashboard.view'],
        'staf_gudang' => ['dashboard.view'],
        'purchasing' => ['dashboard.view'],
    ];

    /**
     * Sel ✅/🔸 Paket A (15 §6.4) per role tidak terkunci; 🔸 = diberikan, batasnya diatur
     * `data_scope` role (ADR-106). HRD & pegawai tidak mendapat izin Paket A. Migrasi data
     * `add_paket_a_permissions_to_builtin_roles` menambahkan sel ini ke instalasi yang sudah berjalan.
     *
     * @var array<string, list<string>>
     */
    private const PAKET_A = [
        'administrator' => [
            'stock_locations.view', 'stock_locations.manage', 'vehicles.view', 'vehicles.manage', 'products.view',
            'sales_areas.view',
        ],
        'kepala_lokasi' => [
            'approvals.view', 'stock_locations.view', 'vehicles.view', 'products.view', 'stock.view',
            'stock_transfers.view', 'stock_opnames.view',
        ],
        'supervisor' => [
            'approvals.view', 'stock_locations.view', 'vehicles.view', 'products.view', 'sales_areas.view',
            'customers.view', 'customers.manage', 'customers.verify', 'credit_limits.set', 'prices.view',
            'prices.manage', 'promotions.view', 'promotions.manage', 'stock.view', 'stock_transfers.view',
        ],
        'admin_penjualan' => [
            'approvals.view', 'stock_locations.view', 'vehicles.view', 'products.view', 'sales_areas.view',
            'sales_areas.manage', 'customers.view', 'customers.export', 'customers.manage', 'customers.verify',
            'customers.change_phone', 'prices.view', 'promotions.view', 'stock.view', 'stock_transfers.view',
            'master_imports.manage',
        ],
        'kepala_gudang' => [
            'approvals.view', 'stock_locations.view', 'vehicles.view', 'products.view', 'suppliers.view',
            'purchase_orders.view', 'goods_receipts.view', 'goods_receipts.create',
            'goods_receipts.approve_short_shelf_life', 'purchase_returns.manage', 'stock.view', 'stock.export',
            'stock_transfers.view', 'stock_transfers.create', 'stock_transfers.receive',
            'stock_transfers.approve_variance', 'stock_opnames.view', 'stock_opnames.manage',
            'stock_opnames.approve_variance', 'stock_quarantine.manage', 'stock_quarantine.release',
            'stock_disposals.manage', 'serials.manage', 'serials.approve_swap',
        ],
        'staf_gudang' => [
            'stock_locations.view', 'products.view', 'goods_receipts.view', 'goods_receipts.create', 'stock.view',
            'stock_transfers.view', 'stock_transfers.create', 'stock_transfers.receive', 'stock_opnames.view',
            'stock_opnames.manage', 'stock_quarantine.manage', 'serials.manage',
        ],
        'purchasing' => [
            'approvals.view', 'stock_locations.view', 'products.view', 'products.manage',
            'product_registrations.manage', 'suppliers.view', 'suppliers.manage', 'prices.view', 'promotions.view',
            'promotions.manage', 'purchase_orders.view', 'purchase_orders.create', 'goods_receipts.view',
            'purchase_returns.manage', 'stock.view', 'stock.view_cost', 'stock.export', 'stock_transfers.view',
            'stock_opnames.view', 'master_imports.manage',
        ],
    ];

    /**
     * @return list<array{name: string, label: string, data_scope: DataScope, mfa_required: bool, is_system: bool, description: string}>
     */
    public static function roles(): array
    {
        return [
            ['name' => self::SUPER_ADMIN, 'label' => 'Super Admin', 'data_scope' => DataScope::All, 'mfa_required' => true, 'is_system' => true, 'description' => 'Dukungan teknis vendor (Sintech). Bisa dinonaktifkan Owner.'],
            ['name' => self::OWNER, 'label' => 'Owner', 'data_scope' => DataScope::All, 'mfa_required' => true, 'is_system' => true, 'description' => 'Pemilik usaha, akses penuh.'],
            ['name' => 'administrator', 'label' => 'Administrator', 'data_scope' => DataScope::All, 'mfa_required' => true, 'is_system' => false, 'description' => 'Admin kantor / IT internal. Mengurus sistem, bukan data SDM.'],
            ['name' => 'hrd', 'label' => 'HRD', 'data_scope' => DataScope::All, 'mfa_required' => true, 'is_system' => false, 'description' => 'Staf HRD: data pegawai, jadwal, presensi.'],
            // Deskripsi dibedakan dari `kepala_gudang` (PA-24f): kepala lokasi = pimpinan unit kerja
            // untuk SDM & presensi, bukan pemegang transaksi gudang.
            ['name' => 'kepala_lokasi', 'label' => 'Kepala Lokasi', 'data_scope' => DataScope::Location, 'mfa_required' => false, 'is_system' => false, 'description' => 'Pimpinan unit kerja (kantor / toko / gudang): presensi & jadwal pegawai di lokasinya.'],
            // MFA wajib sejak T-067 karena memutuskan approval Paket A (PA-12).
            ['name' => 'supervisor', 'label' => 'Supervisor', 'data_scope' => DataScope::Team, 'mfa_required' => true, 'is_system' => false, 'description' => 'Supervisor sales, terbatas timnya.'],
            ['name' => self::PEGAWAI, 'label' => 'Pegawai', 'data_scope' => DataScope::Self, 'mfa_required' => false, 'is_system' => true, 'description' => 'Presensi lewat Pakis Pro. Tidak masuk web admin.'],
            // Paket A (15 §6.1).
            ['name' => 'admin_penjualan', 'label' => 'Admin Penjualan', 'data_scope' => DataScope::All, 'mfa_required' => true, 'is_system' => false, 'description' => 'Staf admin penjualan kantor: master kios, area, dan verifikasi kios.'],
            ['name' => 'kepala_gudang', 'label' => 'Kepala Gudang', 'data_scope' => DataScope::Location, 'mfa_required' => true, 'is_system' => false, 'description' => 'Penanggung jawab stok gudang: penerimaan, mutasi, opname, karantina.'],
            ['name' => 'staf_gudang', 'label' => 'Staf Gudang', 'data_scope' => DataScope::Location, 'mfa_required' => false, 'is_system' => false, 'description' => 'Staf penerimaan barang, mutasi, & picking di gudangnya.'],
            ['name' => 'purchasing', 'label' => 'Purchasing', 'data_scope' => DataScope::All, 'mfa_required' => true, 'is_system' => false, 'description' => 'Staf pembelian: produk, prinsipal/supplier, purchase order.'],
        ];
    }

    /**
     * Permission default per role (06 §4 + 15 §6.4). `owner`/`super_admin` = seluruh katalog;
     * role lain = daftar eksplisit, sehingga izin yang kelak ditambah ke katalog tidak pernah
     * jatuh ke mereka tanpa ditulis di sini (PA-11). `$catalog` hanya untuk menguji perilaku itu.
     *
     * @param  list<string>|null  $catalog  default `PermissionCatalog::names()`
     * @return list<string>
     */
    public static function permissionsFor(string $role, ?array $catalog = null): array
    {
        if (in_array($role, self::LOCKED, true)) {
            return $catalog ?? PermissionCatalog::names();
        }

        return [...self::FASE_0[$role] ?? [], ...self::PAKET_A[$role] ?? []];
    }

    /**
     * Sel Paket A (15 §6.4) per role; owner/super_admin = semua izin Paket A.
     *
     * @return list<string>
     */
    public static function paketAPermissionsFor(string $role): array
    {
        if (in_array($role, self::LOCKED, true)) {
            return PermissionCatalog::paketANames();
        }

        return self::PAKET_A[$role] ?? [];
    }
}
