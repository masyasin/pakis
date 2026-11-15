<?php

use App\Domain\Access\Listings\UserListing;
use App\Domain\Audit\Listings\AuditLogListing;
use App\Domain\Audit\Listings\SecurityEventListing;
use App\Domain\Organization\Listings\DepartmentListing;
use App\Domain\Organization\Listings\LocationListing;

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
    ],

    // Content-Security-Policy (SEC-063). Konfigurasi infrastruktur, bukan setting bisnis.
    // enforce = blokir pelanggaran (produksi) | report-only = hanya laporkan di konsol (staging, lokal) | off
    'csp' => [
        'mode' => in_array(env('CSP_MODE'), ['enforce', 'report-only', 'off'], true)
            ? env('CSP_MODE')
            : 'report-only',
    ],

];
