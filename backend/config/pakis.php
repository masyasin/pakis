<?php

use App\Domain\Audit\Listings\AuditLogListing;
use App\Domain\Audit\Listings\SecurityEventListing;

/*
 * Konfigurasi aplikasi PAKIS ONE yang bukan secret.
 *
 * Parameter bisnis tidak boleh ditambahkan di sini: tempatnya registri setting (08 §2, T-008).
 * Nilai di berkas ini hanya penyangga sementara sampai registri tersedia.
 */

return [

    // Zona tampilan sementara. Sama dengan default registri `general.timezone` (08 §2).
    // TODO(T-008): DisplayTime membaca setting('general.timezone'), lalu kunci ini dihapus.
    'display_timezone' => 'Asia/Jakarta',

    // Ekspor (BR-005). Penyangga sampai registri setting tersedia.
    // TODO(T-008): baca setting('export.*') dan setting('retention.export_days').
    'export' => [
        'sync_max_rows' => 2000,
        'max_rows' => 100000,
        'mass_export_alert_rows' => 1000,
        'retention_days' => 7,
        // Umur signed URL unduhan (05 §4.7 "berumur pendek"); konfigurasi teknis, bukan setting bisnis.
        'download_url_minutes' => 15,
    ],

    // Masa simpan audit & log keamanan (SEC-040). TODO(T-008): setting('retention.*').
    'retention' => [
        'audit_years' => 5,
        'security_days' => 365,
    ],

    // Kunci listing yang bisa diekspor => kelas ExportableListing. Modul mendaftarkan listingnya di sini.
    'exports' => [
        'audit_logs' => AuditLogListing::class,
        'security_events' => SecurityEventListing::class,
    ],

    // Content-Security-Policy (SEC-063). Konfigurasi infrastruktur, bukan setting bisnis.
    // enforce = blokir pelanggaran (produksi) | report-only = hanya laporkan di konsol (staging, lokal) | off
    'csp' => [
        'mode' => in_array(env('CSP_MODE'), ['enforce', 'report-only', 'off'], true)
            ? env('CSP_MODE')
            : 'report-only',
    ],

];
