<?php

use App\Domain\Access\Listings\UserListing;
use App\Domain\Attendance\Listings\AttendanceCorrectionListing;
use App\Domain\Attendance\Listings\AttendanceDayListing;
use App\Domain\Attendance\Listings\AttendanceSummaryListing;
use App\Domain\Attendance\Listings\HolidayListing;
use App\Domain\Attendance\Listings\ScheduleOverrideListing;
use App\Domain\Audit\Listings\AuditLogListing;
use App\Domain\Audit\Listings\SecurityEventListing;
use App\Domain\Catalog\Imports\ProductImportType;
use App\Domain\Catalog\Listings\ProductListing;
use App\Domain\Customer\Approvables\CustomerActivation;
use App\Domain\Customer\Approvables\CustomerCreditLimit;
use App\Domain\Customer\Approvables\CustomerMerge;
use App\Domain\Customer\Approvables\CustomerPhoneChange;
use App\Domain\Customer\Imports\CustomerImportType;
use App\Domain\Customer\Listings\CustomerListing;
use App\Domain\Device\Listings\DeviceListing;
use App\Domain\Employee\Import\EmployeeImportType;
use App\Domain\Employee\Listings\AssignmentListing;
use App\Domain\Employee\Listings\EmployeeListing;
use App\Domain\Inventory\Approvables\DisposalApproval;
use App\Domain\Inventory\Approvables\OpnameVarianceApproval;
use App\Domain\Inventory\Approvables\QuarantineReleaseApproval;
use App\Domain\Inventory\Approvables\TransferVarianceApproval;
use App\Domain\Inventory\Imports\OpeningStockImportType;
use App\Domain\Inventory\Imports\StockLevelImportType;
use App\Domain\Inventory\Listings\SerialNumberListing;
use App\Domain\Inventory\Listings\StockMovementListing;
use App\Domain\Inventory\Listings\StockPositionListing;
use App\Domain\Organization\Listings\DepartmentListing;
use App\Domain\Organization\Listings\LocationListing;
use App\Domain\Organization\Listings\PositionListing;
use App\Domain\Pricing\Approvables\PriceListPublish;
use App\Domain\Pricing\Approvables\PromotionActivate;
use App\Domain\Pricing\Imports\PriceItemImportType;
use App\Domain\Pricing\Listings\PriceListItemListing;
use App\Domain\Purchasing\Approvables\PurchaseOrderApproval;
use App\Domain\Purchasing\Approvables\PurchaseReturnApproval;
use App\Domain\Purchasing\Approvables\ShortShelfLifeApproval;
use App\Domain\Purchasing\Listings\GoodsReceiptListing;
use App\Domain\Purchasing\Listings\PurchaseOrderListing;

/*
 * Konfigurasi TEKNIS PAKIS ONE yang bukan secret dan bukan parameter bisnis.
 *
 * Parameter bisnis (radius, batas, masa simpan, dst.) ada di registri setting dan dibaca dengan
 * `setting('grup.kunci')` (08 §2, T-008). Jangan menambahkannya di sini.
 */

return [
    /*
     * Akun Super Admin awal (SEC-008, SuperAdminSeeder). Dibaca lewat config, bukan env() langsung,
     * agar tetap terbaca saat `config:cache` di produksi. Kosong = seeder gagal (tidak ada akun default).
     */
    'superadmin' => [
        'username' => env('SUPERADMIN_USERNAME', ''),
        'email' => env('SUPERADMIN_EMAIL', ''),
        'password' => env('SUPERADMIN_PASSWORD', ''),
    ],

    /*
     * Kunci HMAC `employees.nik_hash` (SEC-010): 64 karakter hex, terpisah dari APP_KEY. Kosong =
     * NikCipher menolak bekerja (fail-closed). Dibaca lewat config agar tetap ada saat config:cache.
     */
    'nik_hash_key' => env('NIK_HASH_KEY', ''),

    'export' => [
        // Umur signed URL unduhan (05 §4.7 "berumur pendek").
        'download_url_minutes' => 15,
    ],

    // Kunci listing yang bisa diekspor => kelas ExportableListing. Modul mendaftarkan listingnya di sini.
    'exports' => [
        'audit_logs' => AuditLogListing::class,
        'security_events' => SecurityEventListing::class,
        'users' => UserListing::class,
        'locations' => LocationListing::class,
        'departments' => DepartmentListing::class,
        'positions' => PositionListing::class,
        'holidays' => HolidayListing::class,
        'employees' => EmployeeListing::class,
        'assignments' => AssignmentListing::class,
        'devices' => DeviceListing::class,
        'schedule_overrides' => ScheduleOverrideListing::class,
        'attendance_days' => AttendanceDayListing::class,
        'attendance_summary' => AttendanceSummaryListing::class,
        'attendance_corrections' => AttendanceCorrectionListing::class,
        'products' => ProductListing::class, // T-070 (15 §5, izin products.view)
        'price_list_items' => PriceListItemListing::class, // T-075 (15 §5, izin prices.view)
        'purchase_orders' => PurchaseOrderListing::class, // T-079 (15 §5, izin purchase_orders.view, scope Stok)
        'goods_receipts' => GoodsReceiptListing::class, // T-080 (15 §5, izin goods_receipts.view, scope Stok; tanpa kolom biaya, SEC-107)
        'serial_numbers' => SerialNumberListing::class, // T-080 (15 §5, izin stock.view, scope Stok lewat batch)
        'customers' => CustomerListing::class, // T-073 (15 §5, izin customers.export; HP selalu termasking, SEC-108)
        'stock_positions' => StockPositionListing::class, // T-087 (15 §5, izin stock.export, scope Stok; HPP hanya stock.view_cost, SEC-107)
        'stock_movements' => StockMovementListing::class, // T-087 (15 §5, izin stock.export, scope Stok; biaya hanya stock.view_cost, SEC-107)
    ],

    // Tipe impor (BR-170, T-085A): kunci = ImportType::key() => kelas. Kerangka ada di app/Support/Import.
    // IMPORT_TEST_TYPE hanya diisi phpunit.xml (tipe uji di tests/Fixtures), kosong di lingkungan lain.
    'imports' => [
        'employees' => EmployeeImportType::class,
        // T-085 impor master (BR-170): rute /impor/{slug}, izin tipe + master_imports.manage.
        'products' => ProductImportType::class,
        'customers' => CustomerImportType::class,
        'price_items' => PriceItemImportType::class,
        'stock_levels' => StockLevelImportType::class,
        'opening_stock' => OpeningStockImportType::class,
        ...(is_string(env('IMPORT_TEST_TYPE')) && env('IMPORT_TEST_TYPE') !== '' ? ['uji_produk' => env('IMPORT_TEST_TYPE')] : []),
    ],

    /*
     * Jenis dokumen mesin approval (15 §4.2.1, ADR-105, T-067): kunci = `approval_requests.document_type`.
     * `model` = kelas Eloquent yang mengimplementasikan App\Domain\Approval\Contracts\Approvable, diisi
     * task pemilik dokumen (null = dokumennya belum dibangun; aturannya sudah bisa disiapkan).
     * `conditions` = kunci `approval_rules.conditions` yang diizinkan untuk jenis itu.
     */
    'approvables' => [
        // T-079: dokumen = baris purchase_orders (nominal = grand_total).
        'purchase_order' => ['label' => 'Purchase order', 'model' => PurchaseOrderApproval::class, 'conditions' => []],
        // T-080: dokumen = baris goods_receipts (tanpa nominal, BR-103).
        'goods_receipt_short_shelf_life' => ['label' => 'Penerimaan sisa umur pendek', 'model' => ShortShelfLifeApproval::class, 'conditions' => []],
        // T-082: dokumen = baris stock_transfers (nominal = Σ selisih × HPP rata-rata, BR-161).
        'stock_transfer_variance' => ['label' => 'Selisih mutasi stok', 'model' => TransferVarianceApproval::class, 'conditions' => []],
        // T-083: dokumen = baris stock_opnames (nominal = variance_value Σ |selisih| × HPP, BR-162).
        'stock_opname_variance' => ['label' => 'Selisih stock opname', 'model' => OpnameVarianceApproval::class, 'conditions' => []],
        // T-084: dokumen = berita acara (nominal = total_cost HPP) & permintaan lepas karantina (tanpa nominal).
        'stock_disposal' => ['label' => 'Pemusnahan stok', 'model' => DisposalApproval::class, 'conditions' => []],
        'stock_quarantine_release' => ['label' => 'Lepas karantina', 'model' => QuarantineReleaseApproval::class, 'conditions' => []],
        // T-081: dokumen = baris purchase_returns (nominal = nilai HPP saat diajukan).
        'purchase_return' => ['label' => 'Retur ke prinsipal', 'model' => PurchaseReturnApproval::class, 'conditions' => []],
        // T-074: aktivasi & ganti HP = baris customer_verifications; limit & gabung = baris customers (PA-49).
        'customer_activation' => ['label' => 'Aktivasi kios', 'model' => CustomerActivation::class, 'conditions' => []],
        'customer_credit_limit' => ['label' => 'Limit kredit kios', 'model' => CustomerCreditLimit::class, 'conditions' => []],
        'customer_phone_change' => ['label' => 'Ganti nomor HP kios', 'model' => CustomerPhoneChange::class, 'conditions' => []],
        'customer_merge' => ['label' => 'Gabung kios duplikat', 'model' => CustomerMerge::class, 'conditions' => []],
        // T-075: dokumen = baris price_list_versions.
        'price_list_publish' => ['label' => 'Terbit daftar harga', 'model' => PriceListPublish::class, 'conditions' => []],
        // T-076: dokumen = baris promotions (nominal = anggaran).
        'promotion_activate' => ['label' => 'Aktivasi promo', 'model' => PromotionActivate::class, 'conditions' => []],
        'serial_swap' => ['label' => 'Tukar nomor seri', 'model' => null, 'conditions' => []],
    ],

    // Content-Security-Policy (SEC-063). Konfigurasi infrastruktur, bukan setting bisnis.
    // enforce = blokir pelanggaran (produksi) | report-only = hanya laporkan di konsol (staging, lokal) | off
    'csp' => [
        'mode' => in_array(env('CSP_MODE'), ['enforce', 'report-only', 'off'], true)
            ? env('CSP_MODE')
            : 'report-only',
    ],

];
