<?php

use App\Domain\Access\Listings\UserListing;
use App\Domain\Attendance\Listings\AttendanceCorrectionListing;
use App\Domain\Attendance\Listings\AttendanceDayListing;
use App\Domain\Attendance\Listings\AttendanceSummaryListing;
use App\Domain\Attendance\Listings\HolidayListing;
use App\Domain\Attendance\Listings\ScheduleOverrideListing;
use App\Domain\Audit\Listings\AuditLogListing;
use App\Domain\Audit\Listings\SecurityEventListing;
use App\Domain\Catalog\Listings\ProductListing;
use App\Domain\Customer\Approvables\CustomerActivation;
use App\Domain\Customer\Listings\CustomerListing;
use App\Domain\Device\Listings\DeviceListing;
use App\Domain\Employee\Import\EmployeeImportType;
use App\Domain\Employee\Listings\AssignmentListing;
use App\Domain\Employee\Listings\EmployeeListing;
use App\Domain\Organization\Listings\DepartmentListing;
use App\Domain\Organization\Listings\LocationListing;
use App\Domain\Organization\Listings\PositionListing;

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
        'customers' => CustomerListing::class, // T-073 (15 §5, izin customers.export; HP selalu termasking, SEC-108)
    ],

    // Tipe impor (BR-170, T-085A): kunci = ImportType::key() => kelas. Kerangka ada di app/Support/Import.
    // IMPORT_TEST_TYPE hanya diisi phpunit.xml (tipe uji di tests/Fixtures), kosong di lingkungan lain.
    'imports' => [
        'employees' => EmployeeImportType::class,
        ...(is_string(env('IMPORT_TEST_TYPE')) && env('IMPORT_TEST_TYPE') !== '' ? ['uji_produk' => env('IMPORT_TEST_TYPE')] : []),
    ],

    /*
     * Jenis dokumen mesin approval (15 §4.2.1, ADR-105, T-067): kunci = `approval_requests.document_type`.
     * `model` = kelas Eloquent yang mengimplementasikan App\Domain\Approval\Contracts\Approvable, diisi
     * task pemilik dokumen (null = dokumennya belum dibangun; aturannya sudah bisa disiapkan).
     * `conditions` = kunci `approval_rules.conditions` yang diizinkan untuk jenis itu.
     */
    'approvables' => [
        'purchase_order' => ['label' => 'Purchase order', 'model' => null, 'conditions' => []],
        'goods_receipt_short_shelf_life' => ['label' => 'Penerimaan sisa umur pendek', 'model' => null, 'conditions' => []],
        'stock_transfer_variance' => ['label' => 'Selisih mutasi stok', 'model' => null, 'conditions' => []],
        'stock_opname_variance' => ['label' => 'Selisih stock opname', 'model' => null, 'conditions' => []],
        'stock_disposal' => ['label' => 'Pemusnahan stok', 'model' => null, 'conditions' => []],
        'stock_quarantine_release' => ['label' => 'Lepas karantina', 'model' => null, 'conditions' => []],
        'purchase_return' => ['label' => 'Retur ke prinsipal', 'model' => null, 'conditions' => []],
        // T-074: dokumen = baris customer_verifications (PA-49).
        'customer_activation' => ['label' => 'Aktivasi kios', 'model' => CustomerActivation::class, 'conditions' => []],
        'customer_credit_limit' => ['label' => 'Limit kredit kios', 'model' => null, 'conditions' => []],
        'customer_phone_change' => ['label' => 'Ganti nomor HP kios', 'model' => null, 'conditions' => []],
        'customer_merge' => ['label' => 'Gabung kios duplikat', 'model' => null, 'conditions' => []],
        'price_list_publish' => ['label' => 'Terbit daftar harga', 'model' => null, 'conditions' => []],
        'promotion_activate' => ['label' => 'Aktivasi promo', 'model' => null, 'conditions' => []],
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
