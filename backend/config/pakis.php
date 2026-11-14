<?php

use App\Domain\Audit\Listings\AuditLogListing;
use App\Domain\Audit\Listings\SecurityEventListing;

/*
 * Konfigurasi TEKNIS PAKIS ONE yang bukan secret dan bukan parameter bisnis.
 *
 * Parameter bisnis (radius, batas, masa simpan, dst.) ada di registri setting dan dibaca dengan
 * `setting('grup.kunci')` (08 §2, T-008). Jangan menambahkannya di sini.
 */

return [

    'export' => [
        // Umur signed URL unduhan (05 §4.7 "berumur pendek").
        'download_url_minutes' => 15,
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
