<?php

/*
 * Kunci listing yang SUDAH dibuat, salinan dari docs/07-UI-SPEC.md §3.4 (10 §3).
 *
 * Dicocokkan dua arah oleh tests/Contract/ListingContractTest:
 * - setiap kunci di sini wajib ada di katalog 07 §3.4 dan punya route yang terdaftar;
 * - setiap route `admin.*.index` / `api.v1.*.index` wajib memakai listing dengan kunci di sini.
 *
 * Task yang membuat daftar baru menambahkan kuncinya di sini (mis. T-006: 'export_files').
 */

return [
    'export_files', // T-006
    'audit_logs', // T-007
    'security_events', // T-007
];
