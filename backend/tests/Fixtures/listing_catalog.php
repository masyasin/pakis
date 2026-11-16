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
    'sequences', // T-009
    'references', // T-010
    'regions', // T-012
    'roles', // T-018
    'users', // T-020
    'shifts', // T-033
    'holidays', // T-034
    'schedules', // T-035
    'schedule_overrides', // T-035
    'field_trips', // T-036A
    'notifications', // T-029
    'locations', // T-021
    'departments', // T-022
    'positions', // T-023
    'employees', // T-024
    'assignments', // T-025
    'devices', // T-031
    'import_batches', // T-027
    'import_rows', // T-027
    'import_profiles', // T-027
    'attendance_days', // T-038
    'attendance_summary', // T-039
    'attendance_corrections', // T-041
    'my_field_trips', // T-036A bagian 2 (API)
    'approval_field_trips', // T-036A bagian 2 (API)
    'my_corrections', // T-040 (API)
    'approval_corrections', // T-040 (API)
    'my_attendance_days', // T-043 (API)
    'my_notifications', // T-043 (API)
    'approvals', // T-067
    'approval_rules', // T-067
    'approval_delegations', // T-067
    'product_categories', // T-069
    'products', // T-070
    'stock_locations', // T-068
    'stock_bins', // T-068
    'vehicles', // T-068
];
