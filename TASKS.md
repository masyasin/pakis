# TASKS — PAKIS ONE Fase 0 + 0,5 (T-001 s.d. T-048) + Fase HR (T-049 s.d. T-066)

Format & aturan: lihat `CLAUDE.md` §4. Satu task = satu sesi = satu commit/PR. Estimasi = jam kerja efektif developer + Claude Code.
**Setiap task yang membuat halaman/endpoint daftar wajib menyalin checklist `docs/07-UI-SPEC.md` §3.3 ke PR-nya.**

Ringkasan: Fase 0A (T-001–T-007) 37 jam · Fase 0B (T-008–T-012) 24 jam · Fase 0C (T-013–T-020) 41 jam · Fase 0D (T-021–T-029) 44 jam · Fase 0E (T-030–T-032) 22 jam · Fase 0,5 (T-033–T-045) 76 jam · Fase H (T-046–T-048) 26 jam · **Total ±270 jam**. Fase HR (T-049–T-066, setelah Fase 0,5) ±132 jam.

**Urutan eksekusi** mengikuti dependensi, bukan nomor. Lihat `Implementasi_Plan.md` §3 (beberapa task bernomor kecil menunggu task bernomor besar, mis. T-016 menunggu T-018 dan T-026 menunggu T-031).

---

## Fase 0A — Fondasi Teknis

### T-001 — Scaffold monorepo & Laravel 13
- Fase: 0A | Prioritas: P0 | Estimasi: 4 jam
- Memenuhi: NFR-012, SEC-060, ADR-002, ADR-003, OQ-013
- Dependensi: —
- Konteks wajib: `docs/03-ARCHITECTURE.md` §3, §5, §6
- File: `backend/` (starter kit React), `backend/CLAUDE.md`, `.gitignore`, `.editorconfig`, `backend/.env.example`, `docs/03-ARCHITECTURE.md` §5 (isi versi terkunci)
- Deskripsi: Buat struktur monorepo. `laravel new backend` dengan React starter kit. Pasang `spatie/laravel-permission`, `laravel/sanctum`, `openspout/openspout`, Larastan, Pest. Pastikan berjalan di PHP 8.5. Bila ada dependency yang belum kompatibel, berhenti dan laporkan (OQ-013). Buat struktur `app/Domain/*` kosong sesuai `03` §3. Konfigurasi `APP_TIMEZONE=UTC` (penyimpanan) + helper tampilan zona waktu dari setting. Hapus `remember_token` & fitur "ingat saya" dari starter kit (SEC-005).
- Kriteria penerimaan:
  - [x] `composer install`, `npm ci`, `php artisan test`, `npm run build` sukses di PHP 8.5
  - [x] Tabel versi di `03` §5 diisi versi terkunci (Laravel, Inertia, React, Tailwind, Spatie, Sanctum, OpenSpout, Pest, Larastan)
  - [x] `.env.example` lengkap tanpa nilai rahasia (termasuk `NIK_HASH_KEY`, `SUPERADMIN_*`, `OTP_DRIVER=log`)
  - [x] `backend/CLAUDE.md` berisi perintah khusus backend dan merujuk ke root `CLAUDE.md`
- Verifikasi: `php -v` → 8.5.x · `php artisan about` · `php artisan test`
- Di luar lingkup: fitur apa pun

### T-002 — CI & pre-commit
- Fase: 0A | Prioritas: P0 | Estimasi: 3 jam
- Memenuhi: NFR-012, SEC-060, SEC-066
- Dependensi: T-001
- Konteks wajib: `docs/09-SECURITY.md` §3.7, `docs/10-TEST-PLAN.md` §1
- File: `.github/workflows/backend.yml`, `.github/workflows/mobile.yml` (kerangka, path filter), `.gitleaks.toml`, `.pre-commit-config.yaml` atau `lefthook.yml`, `backend/phpstan.neon` (level 7, keputusan 14 Nov 2026)
- Deskripsi: Pipeline backend: Pint `--test`, Larastan, Pest (service MariaDB 11.8 + Redis), ESLint, `tsc`, gitleaks, `composer audit`, `npm audit --audit-level=high`. Pre-commit: gitleaks + Pint.
- Kriteria penerimaan:
  - [x] PR dengan secret tiruan (`AKIA...`) gagal di gitleaks (PR #2, ditutup)
  - [x] Semua job hijau pada commit kosong fitur (`da56ed8`)
- Verifikasi: push branch uji
- Di luar lingkup: deploy (T-046)

### T-003 — Infrastruktur lintas modul
- Fase: 0A | Prioritas: P0 | Estimasi: 5 jam
- Memenuhi: NFR-011, SEC-035, SEC-042, SEC-061, SEC-063
- Dependensi: T-001
- Konteks wajib: `docs/05-API-CONTRACT.md` §2, `docs/09-SECURITY.md` SEC-061, SEC-063
- File: `app/Http/Middleware/{AssignRequestId,SecurityHeaders}.php`, `app/Exceptions/ApiExceptionRenderer.php`, `app/Support/Api/{ApiError,ErrorCode}.php`, `config/logging.php`, `bootstrap/app.php`, `tests/Contract/ApiErrorFormatTest.php`
- Deskripsi: Request ID (ULID) di log & header. Log JSON. Renderer galat API sesuai `05` §2.2 dengan enum `ErrorCode` (semua kode di `05` §2.3). Halaman galat web generik. Middleware security headers (CSP report-only bisa diaktifkan via env, nonce Vite). `PreventRequestForgery` aktif.
- Kriteria penerimaan:
  - [x] Galat validasi API → format `05` §2.2 dengan `details` per field
  - [x] Exception tak terduga di produksi → `SERVER_ERROR` tanpa stack trace, `request_id` sama di log & respons
  - [x] Semua header SEC-063 ada di respons web
- Verifikasi: `php artisan test --filter=ApiErrorFormat`
- Di luar lingkup: rate limiter spesifik (task masing-masing)

### T-004 — Mesin listing (backend)
- Fase: 0A | Prioritas: P0 | Estimasi: 7 jam
- Memenuhi: BR-001, BR-002, BR-003, BR-004, SEC-032, NFR-010, ADR-007
- Dependensi: T-001
- Konteks wajib: `docs/03-ARCHITECTURE.md` §4.3, `docs/05-API-CONTRACT.md` §3, `docs/08-BUSINESS-RULES.md` §1
- File: `app/Support/Listing/{ListingSpec,ListingQuery,HasListing,UsesListing,ListingResult}.php`, `app/Support/Listing/Filters/{SelectFilter,MultiSelectFilter,BooleanFilter,DateRangeFilter,NumberRangeFilter,RelationFilter}.php`, `app/Support/Listing/Search/TextSearch.php`, `tests/Unit/Listing/*`
- Deskripsi: Implementasi spec, penerapan pencarian (escape wildcard, multi-kata AND, prefix vs contains), filter bertipe dengan validasi & `invalid_filters`, sort allowlist + tie-breaker `id desc`, paging dengan opsi & batas `ui.max_per_page` (sementara konstanta 100 sampai T-008), serialisasi `listing` untuk Inertia & API, `options_source` untuk lookup.
- Kriteria penerimaan:
  - [x] Unit test semua tipe filter, termasuk nilai tidak valid
  - [x] `sort=password` dan `filter[x]=1 OR 1=1` tidak memengaruhi SQL (cek query log)
  - [x] `q` dengan `%` dan `_` dicari sebagai literal
- Verifikasi: `php artisan test tests/Unit/Listing`
- Di luar lingkup: komponen frontend (T-005)

### T-005 — DataTable frontend & test kontrak listing
- Fase: 0A | Prioritas: P0 | Estimasi: 8 jam
- Memenuhi: NFR-009, NFR-010, BR-001–BR-004
- Dependensi: T-004
- Konteks wajib: `docs/07-UI-SPEC.md` §1–§3, `docs/10-TEST-PLAN.md` §3
- File: `resources/js/components/pakis/{DataTable,FilterBar,FilterSheet,ActiveFilterChips,PerPageSelect,Pagination,EmptyState,TableSkeleton,StatusBadge}.tsx`, `resources/js/hooks/useListing.ts`, `resources/js/types/listing.ts`, `tests/Contract/{ListingContractTest,ListingRouteFixtures}.php`, `tests/Fixtures/listing_catalog.php`, `resources/js/components/pakis/__tests__/DataTable.test.tsx`
- Deskripsi: Komponen daftar standar sesuai `07` §3.1–§3.2 (sinkron URL, debounce, chip filter, reset, dua jenis empty state, skeleton, mode kartu < 768 px, aksesibilitas). Test kontrak menelusuri route `*.index` dan katalog dua arah (route & katalog harus cocok). Untuk sementara katalog fixture hanya berisi kunci yang sudah dibuat, dan setiap task berikutnya menambah kuncinya. Buat satu halaman contoh internal (`/dev/listing-demo`, hanya `local`) untuk uji visual.
- Kriteria penerimaan:
  - [x] Vitest: perubahan pencarian/filter mengubah URL & kembali ke halaman 1
  - [x] Route `*.index` tanpa `#[UsesListing]` membuat test kontrak gagal dengan pesan jelas
  - [x] Tema terang & gelap, kontras AA (token status terukur 6,4–8,8:1; dicek di browser)
- Catatan pelaksanaan: nama file mengikuti konvensi kebab-case starter kit (`data-table.tsx`, `hooks/use-listing.ts`, `__tests__/data-table.test.tsx`), export tetap PascalCase.
- Verifikasi: `npm run test`, `php artisan test tests/Contract`
- Di luar lingkup: ekspor (T-006)

### T-006 — Mesin ekspor & Unduhan
- Fase: 0A | Prioritas: P0 | Estimasi: 4 jam
- Memenuhi: FR-029, BR-005, SEC-051
- Dependensi: T-004, T-005
- Konteks wajib: `docs/04-DATABASE.md` §2.10 (`export_files`), `docs/08-BUSINESS-RULES.md` BR-005
- File: migration `export_files`, `app/Support/Export/{XlsxExporter,FormulaEscaper,ExportColumns}.php`, `app/Jobs/RunExport.php`, `app/Http/Controllers/Admin/ExportController.php`, `app/Support/Export/ExportFileListing.php`, `resources/js/pages/Exports/Index.tsx`
- Deskripsi: Ekspor .xlsx streaming (OpenSpout) dari `ListingSpec` + kolom ekspor. Sinkron vs antrean sesuai ambang (sementara konstanta sampai T-008). Daftar `export_files` milik sendiri (`admin.exports.index`), unduh via signed URL. Notifikasi saat siap ditambahkan di T-029 (sediakan event `ExportReady`).
- Kriteria penerimaan:
  - [x] Sel `=HYPERLINK("x")` diekspor sebagai teks
  - [x] Ekspor memakai filter & scope yang sama dengan tampilan (test)
  - [x] Pengguna lain tidak bisa mengunduh berkas ekspor orang lain (404)
  - [x] Kunci listing `export_files` di katalog + test kontrak hijau
- Catatan pelaksanaan: halaman di `pages/exports/index.tsx` (kebab-case). Event `ExportCreated` disediakan untuk audit & `mass_export` di T-007.
- Verifikasi: `php artisan test --filter=Export`
- Di luar lingkup: kolom ekspor per modul (task modul)

### T-007 — Audit log & log keamanan
- Fase: 0A | Prioritas: P0 | Estimasi: 6 jam
- Memenuhi: FR-019, FR-020, SEC-040, SEC-041 (pencatatan), BR-005 (`mass_export`)
- Dependensi: T-004, T-005, T-006
- Konteks wajib: `docs/04-DATABASE.md` §2.11, `docs/09-SECURITY.md` §3.5, §7.1
- File: migrations `audit_logs`, `security_events` + trigger, `app/Domain/Audit/{AuditLogger,SecurityLogger,Redactor}.php`, `Models/{AuditLog,SecurityEvent}`, `Listings/{AuditLogListing,SecurityEventListing}`, controller + halaman `Audit/Index`, `Audit/Show`, `SecurityEvents/Index`, command `audit:purge`
- Deskripsi: Logger dipanggil eksplisit dari Actions (bukan observer otomatis) dengan nilai lama/baru dan redaksi field sensitif. Trigger append-only. Purge memakai `@audit_purge`. Halaman daftar + detail diff + ekspor.
- Kriteria penerimaan:
  - [x] `UPDATE audit_logs ...` langsung → galat DB (test)
  - [x] Field `password`, `nik_enc`, `nik_hash`, `two_factor_secret` tersimpan `***`
  - [x] Listing `audit_logs` & `security_events` lolos test kontrak, default filter 7 hari
- Catatan pelaksanaan: halaman `pages/audit/{index,show}.tsx`, `pages/security-events/index.tsx` (kebab-case). Izin `audit.*` sementara deny-by-default (lokal saja) sampai T-018. Menu sidebar Audit/Log Keamanan menyusul T-018 (sidebar sedang dirombak agen desain).
- Verifikasi: `php artisan test --filter=Audit`
- Di luar lingkup: alert email (T-045)

## Fase 0B — Setting & Master

### T-008 — Registri site setting, halaman Pengaturan, config publik
- Fase: 0B | Prioritas: P0 | Estimasi: 8 jam
- Memenuhi: FR-011, FR-012, FR-015, FR-042 (server), BR-020–BR-024, BR-027 (helper semver), SEC-052, SEC-062
- Dependensi: T-007
- Konteks wajib: `docs/08-BUSINESS-RULES.md` §2, `docs/03-ARCHITECTURE.md` §4.2, `docs/05-API-CONTRACT.md` §5.2
- File: migration `settings`, `app/Domain/Settings/{SettingRegistry,SettingDefinition,SettingStore,SettingType,Branding}.php`, helper `setting()`, `Actions/UpdateSettingGroup`, `Http/Controllers/Admin/SettingController`, `Api/V1/ConfigController`, `resources/js/pages/Settings/Edit.tsx`, `components/pakis/SettingField.tsx`, `tests/Contract/PublicConfigTest.php`
- Deskripsi: Registri berisi **semua** kunci di `08` §2 (tipe, default, validasi, publik). Form dibuat otomatis per grup. Unggah logo/favicon (tanpa SVG, buang metadata). Validasi kontras warna. Cache + invalidasi. Audit per kunci. "Kembalikan default". Grup `security` minta konfirmasi password. Kirim email uji. Ganti konstanta sementara di T-004/T-006 dengan `setting()`. Branding dibagikan ke Inertia (logo, nama, warna) dan diterapkan ke CSS variable.
- Kriteria penerimaan:
  - [x] Test memastikan setiap kunci di `08` §2 ada di registri dengan default & validasi yang sama (fixture = tabel dokumen yang di-parse)
  - [x] Kunci tak dikenal → 422. Nilai di luar batas → 422 dengan pesan per field
  - [x] `/api/v1/config` hanya memuat kunci `is_public` (SEC-062)
  - [x] Warna `#9BE59B` ditolak (kontras rendah)
  - [x] **Sisa (setelah `feat/ui-desain` digabung):** branding dibagikan ke Inertia (logo, nama, warna) dan diterapkan ke CSS variable `--primary` (+ varian gelap dari `ContrastRatio::darkVariant`), logo/favicon di layout & login.
- Catatan pelaksanaan: halaman `pages/site-settings/edit.tsx` (bukan `pages/Settings/Edit.tsx`: folder `pages/settings/` milik pengaturan pengguna starter kit). Nama field form `grup__kunci`.
- Verifikasi: `php artisan test --filter=Setting`
- Di luar lingkup: gate versi di middleware API (T-031)

### T-009 — Penomoran dokumen
- Fase: 0B | Prioritas: P0 | Estimasi: 5 jam
- Memenuhi: FR-013, BR-025, SEC-030, ADR-009
- Dependensi: T-008
- Konteks wajib: `docs/04-DATABASE.md` §2.5, `docs/08-BUSINESS-RULES.md` BR-025
- File: migrations, `app/Domain/Numbering/{NumberGenerator,PatternParser,PeriodKey}.php`, `SequenceSeeder`, `Listings/SequenceListing`, controller + `pages/Sequences/Index.tsx` (dialog ubah + pratinjau)
- Deskripsi: Parser pola + validasi konsistensi periode. Generator dengan `lockForUpdate` di transaksi pemanggil, dukungan `{LOC}`, retry bila tabrakan (maks 50). Pratinjau tanpa menaikkan counter.
- Kriteria penerimaan:
  - [x] Test konkurensi: 50 proses paralel → 50 nomor unik berurutan
  - [x] Pola `monthly` tanpa `{MM}` ditolak
  - [x] Reset bulanan menghasilkan `KOR-202612-0001` setelah `KOR-202611-0042`
  - [x] Listing `sequences` lolos test kontrak
- Verifikasi: `php artisan test --filter=Numbering`
- Di luar lingkup: pemakaian di pegawai (T-024) & koreksi (T-040)

### T-010 — Referensi dinamis
- Fase: 0B | Prioritas: P0 | Estimasi: 4 jam
- Memenuhi: FR-014, BR-026
- Dependensi: T-008
- Konteks wajib: `docs/04-DATABASE.md` §2.6, §4.1, `docs/08-BUSINESS-RULES.md` §3
- File: migration, `app/Domain/Reference/{Models/ReferenceItem,Enums/ReferenceGroup,Actions/*}`, `ReferenceSeeder`, `Listings/ReferenceListing`, controller + `pages/References/Index.tsx` (tab grup, drag urutan)
- Kriteria penerimaan:
  - [x] Item `is_system` tidak bisa dihapus/ganti kode (test)
  - [x] Item yang dipakai tidak bisa dihapus (test memakai tabel dummy di test sampai T-021 ada)
  - [x] Listing `references` lolos test kontrak dengan `filter[group]`
- Verifikasi: `php artisan test --filter=Reference`
- Di luar lingkup: —

### T-011 — Kerangka lookup
- Fase: 0B | Prioritas: P1 | Estimasi: 2 jam
- Memenuhi: BR-006
- Dependensi: T-004
- Konteks wajib: `docs/05-API-CONTRACT.md` §4.11
- File: `app/Http/Controllers/Admin/LookupController.php`, `app/Support/Lookup/{LookupSource,LookupRegistry}.php`, `resources/js/components/pakis/AsyncSelect.tsx`
- Deskripsi: Endpoint `admin.lookup.{source}` dengan pencarian wajib, limit 20, aktif saja, dan hook scope (diisi T-019). Sumber didaftarkan oleh modul masing-masing. Komponen select async dipakai `FilterBar` untuk `options_source`.
- Kriteria penerimaan:
  - [x] Tanpa `q` → maksimal 20 hasil teratas (tidak pernah seluruh tabel)
  - [x] Sumber tak dikenal → 404
- Verifikasi: `php artisan test --filter=Lookup`
- Di luar lingkup: sumber spesifik (task modul)

### T-012 — Master wilayah
- Fase: 0B | Prioritas: P1 | Estimasi: 5 jam
- Memenuhi: FR-021
- Dependensi: T-005, T-011
- Konteks wajib: `docs/04-DATABASE.md` §2.7, OQ-003
- File: migrations 4 tabel, `app/Domain/Region/*`, command `regions:import {path} {--format=}`, `Listings/RegionListing`, controller + `pages/Regions/Index.tsx`, lookup source `regions`
- Deskripsi: Import idempoten (upsert) dari SQL Sintech. **Pemetaan format sumber menunggu OQ-003.** Sementara itu buat adapter untuk format kode bertitik dan fixture kecil (Kab. Kudus) untuk test. Daftar desa dengan filter berjenjang.
- Kriteria penerimaan:
  - [x] Import 2× tidak menggandakan baris
  - [x] Filter kecamatan hanya aktif setelah kab/kota dipilih
  - [x] Listing `regions` lolos test kontrak
- Verifikasi: `php artisan regions:import tests/Fixtures/regions_kudus.sql`
- Di luar lingkup: data wilayah selain Indonesia

## Fase 0C — Autentikasi & Akses

### T-013 — Login, lockout, sesi, log keamanan
- Fase: 0C | Prioritas: P0 | Estimasi: 6 jam
- Memenuhi: FR-001, FR-009, BR-010, BR-016, BR-017, BR-018, SEC-001, SEC-003, SEC-005
- Dependensi: T-007, T-008
- Konteks wajib: `docs/04-DATABASE.md` §2.2, `docs/08-BUSINESS-RULES.md` §4, `docs/09-SECURITY.md` §3.1
- File: migration modifikasi `users`, `app/Domain/Access/Actions/{AttemptLogin,RecordLoginFailure}.php`, konfigurasi Fortify (`authenticateUsing`), `app/Http/Middleware/{EnsureUserActive,EnforceSessionLifetime,RequireWebMenuAccess}.php`, rate limiter `login`, notifikasi `NewDeviceLogin`, `AccountLocked`, halaman `auth/login.tsx`
- Deskripsi: Login dengan username/email (case-insensitive). Respons generik + waktu konstan. Lockout akun + throttle IP. Idle/absolute timeout dari setting. Deteksi browser/perangkat baru. Gerbang web untuk akun mobile-only (BR-017). Catat semua event ke `security_events`.
- Kriteria penerimaan:
  - [x] 4 kasus gagal (user tak ada, salah password, akun nonaktif, pegawai nonaktif) → respons identik (SEC-001)
  - [x] Salah 5× → `locked_until`, event `lockout`, email (fake) terkirim
  - [x] Sesi idle > batas → logout otomatis
  - [x] Akun dengan hanya role `pegawai` ditolak login web dengan pesan BR-017
- Verifikasi: `php artisan test --filter=Login`
- Di luar lingkup: MFA (T-016), API login (T-031)

### T-014 — Ubah password & wajib ganti password (web)
- Fase: 0C | Prioritas: P0 | Estimasi: 4 jam
- Memenuhi: FR-004, FR-007 (sisi pengguna), BR-011, SEC-002, SEC-006
- Dependensi: T-013
- Konteks wajib: `docs/08-BUSINESS-RULES.md` BR-011, BR-014
- File: `app/Domain/Access/Rules/PasswordPolicy.php`, `Actions/ChangePassword.php`, middleware `RequirePasswordChange`, `pages/Auth/ForceChangePassword.tsx`, `pages/Profile/Password` (bagian profil)
- Deskripsi: Satu kelas kebijakan password dipakai di semua tempat (web, API, reset, admin, import akun). Cek password bocor dengan fail-open terdokumentasi. Ubah password → keluarkan sesi lain + cabut token mobile milik sendiri kecuali token perangkat saat ini (API). Middleware memaksa ganti password.
- Kriteria penerimaan:
  - [x] Password yang memuat username ditolak. Password sama dengan lama ditolak
  - [x] Pengguna `must_change_password` diarahkan ke `/ganti-password` dari route mana pun
  - [x] Event `password_changed` + email pemberitahuan
- Verifikasi: `php artisan test --filter=Password`
- Di luar lingkup: reset oleh admin (T-020)

### T-015 — Lupa password (email & adapter OTP)
- Fase: 0C | Prioritas: P0 | Estimasi: 5 jam
- Memenuhi: FR-005, FR-006, BR-012, BR-013, SEC-004
- Dependensi: T-014
- Konteks wajib: `docs/05-API-CONTRACT.md` §4.1, `docs/08-BUSINESS-RULES.md` BR-012, BR-013, OQ-004
- File: kustomisasi Fortify reset, `app/Domain/Notification/Otp/{OtpChannel,LogOtpChannel,OtpService}.php`, `config/otp.php` (`OTP_DRIVER`), `pages/Auth/{ForgotPassword,ResetPassword,ResetPasswordOtp}.tsx`, rate limiter `password`
- Deskripsi: Field `login` + pilihan kanal sesuai `auth.forgot_channels`. Email: tautan sekali pakai. OTP: kontrak `OtpChannel` + driver `log` (menulis ke log channel khusus, hanya non-produksi). Driver nyata dibuat di paket lain setelah OQ-004. Respons selalu generik. Reset sukses mencabut sesi & token.
- Kriteria penerimaan:
  - [x] Respons identik untuk akun ada/tidak ada/tanpa email
  - [x] Token dipakai dua kali → gagal
  - [x] OTP salah 5× → OTP hangus. Kirim ulang < 60 s → ditolak
  - [x] Driver `log` menolak aktif di `APP_ENV=production` (boot exception)
- Verifikasi: `php artisan test --filter=ForgotPassword`
- Di luar lingkup: endpoint API lupa password (T-031)

### T-016 — MFA wajib per role
- Fase: 0C | Prioritas: P0 | Estimasi: 4 jam
- Memenuhi: FR-008 (web), BR-015, SEC-007
- Dependensi: T-013, T-018
- Konteks wajib: `docs/08-BUSINESS-RULES.md` BR-015, `docs/06-AUTHORIZATION.md` §2
- File: middleware `EnforceMfa`, `pages/Profile/Security.tsx` (aktivasi TOTP via Fortify), `pages/Auth/MfaSetupRequired.tsx`, `Actions/ResetMfa.php`
- Deskripsi: Bila salah satu role `mfa_required` dan MFA belum aktif → paksa aktivasi sebelum menu lain. Kode pemulihan ditampilkan sekali. Event `mfa_enabled/disabled/failed`.
- Kriteria penerimaan:
  - [x] Owner tanpa MFA tidak bisa membuka `/dasbor` (redirect ke aktivasi)
  - [x] Kode pemulihan sekali pakai
- Verifikasi: `php artisan test --filter=Mfa`
- Di luar lingkup: MFA di API (T-031)

### T-017 — Profil & manajemen sesi (web)
- Fase: 0C | Prioritas: P1 | Estimasi: 4 jam
- Memenuhi: FR-003 (web), FR-010, BR-018, SEC-005
- Dependensi: T-014
- Konteks wajib: `docs/07-UI-SPEC.md` §4 (Profil & Keamanan)
- File: `Http/Controllers/Admin/ProfileController.php`, `Actions/{UpdateProfile,RevokeSession,RevokeOtherSessions}.php`, `pages/Profile/{Edit,Security}.tsx`
- Deskripsi: Ubah nama, HP, avatar (validasi gambar), email dengan verifikasi ulang. Daftar sesi web (dari tabel `sessions`/Redis: browser, IP, terakhir aktif) + perangkat mobile. Cabut satu / semua lainnya (konfirmasi password).
- Kriteria penerimaan:
  - [x] Tidak bisa mencabut sesi milik pengguna lain (404)
  - [x] Ganti email → `email_verified_at` null + email verifikasi
- Verifikasi: `php artisan test --filter=Profile`
- Di luar lingkup: —

### T-018 — Role & permission dinamis + Super Admin
- Fase: 0C | Prioritas: P0 | Estimasi: 6 jam
- Memenuhi: FR-017, BR-030, BR-031, SEC-008, ADR-011
- Dependensi: T-007
- Konteks wajib: `docs/06-AUTHORIZATION.md` §2–§4, §8, `docs/04-DATABASE.md` §2.3, §4.1
- File: migration kolom `roles`, `PermissionSeeder` (50 permission persis `06` §3), `RoleSeeder` (matriks `06` §4), `SuperAdminSeeder`, `app/Domain/Access/{Actions/SaveRole,DeleteRole}.php`, `Listings/RoleListing`, `Support/Menu/MenuBuilder.php` (dari `06` §8), `HandleInertiaRequests` (`auth.permissions`, `auth.menu`), `pages/Roles/{Index,Form}.tsx`
- Deskripsi: Kelola role (non-sistem) + matriks permission per modul. Role `owner`/`super_admin` terkunci. Menu web dibangun server dari matriks `06` §8. Perubahan → event `role_changed`/`permission_changed` + bersihkan cache Spatie.
- Kriteria penerimaan:
  - [x] Jumlah permission di DB = 50 dan namanya identik dengan `06` §3 (test)
  - [x] Seeder role menghasilkan matriks identik `06` §4 (test dari `tests/Fixtures/permission_matrix.php`)
  - [x] `SuperAdminSeeder` gagal bila password env < 16 karakter. Demo seeder gagal di produksi (SEC-008)
  - [x] Role dipakai pengguna tidak bisa dihapus
  - [x] Listing `roles` lolos test kontrak
- Verifikasi: `php artisan test --filter=Role`
- Di luar lingkup: scope data (T-019)

### T-019 — DataScope, Policy dasar, harness test otorisasi
- Fase: 0C | Prioritas: P0 | Estimasi: 6 jam
- Memenuhi: FR-018, BR-032, SEC-009, SEC-033
- Dependensi: T-018
- Konteks wajib: `docs/06-AUTHORIZATION.md` §1, §5, §10, §11, `docs/10-TEST-PLAN.md` §4
- File: `app/Domain/Access/{DataScope,ScopeResolver}.php`, `Gate::before` super admin (+ pengecualian Owner), trait `ScopedRouteBinding`, `tests/Feature/Authorization/{PermissionMatrixTest,IdorTestCase}.php`, `tests/Fixtures/permission_matrix.php`
- Deskripsi: Implementasi scope `all/location/team/self` (CTE rekursif maks 5 tingkat untuk `team`) dengan kolom pegawai yang bisa dikonfigurasi per query. Hook ke `LookupRegistry` (T-011). Harness data-driven untuk matriks & IDOR yang dipakai task modul berikutnya. Tabel pegawai/penugasan belum ada, jadi gunakan migration test minimal atau tunda test integrasi scope ke T-025 (catat di Implementasi_Plan).
- Kriteria penerimaan:
  - [x] Unit test scope dengan struktur atasan 3 tingkat
  - [x] Super Admin tidak bisa mengubah role akun Owner (SEC-009)
  - [x] Resource di luar scope → 404 (bukan 403)
- Verifikasi: `php artisan test --filter=Scope`
- Di luar lingkup: —

### T-020 — Manajemen pengguna
- Fase: 0C | Prioritas: P0 | Estimasi: 6 jam
- Memenuhi: FR-007, FR-016, BR-014, SEC-006
- Dependensi: T-014, T-016, T-019
- Konteks wajib: `docs/05-API-CONTRACT.md` §4.4, `docs/07-UI-SPEC.md` §4 (Pengguna)
- File: `app/Domain/Access/Actions/{CreateUser,UpdateUser,ToggleUser,AdminResetPassword,RevokeUserSessions}.php`, `Listings/UserListing`, controller + `pages/Users/{Index,Form,Show}.tsx`, kolom ekspor
- Deskripsi: CRUD akun, tautan pegawai (lookup pegawai tanpa akun, aktif setelah T-024), role multi, nonaktif (cabut sesi & token), reset password (tampil sekali, konfirmasi password admin), reset MFA, cabut sesi, ekspor.
- Kriteria penerimaan:
  - [x] Password sementara tidak tersimpan di log/audit/flash berikutnya (hanya sekali tampil)
  - [x] HRD tidak bisa reset MFA (403). Admin tidak bisa reset password Owner
  - [x] Listing `users` lolos test kontrak + test cari/filter role/sort
- Verifikasi: `php artisan test --filter=User`
- Di luar lingkup: pembuatan akun dari halaman pegawai (T-024)

## Fase 0D — Organisasi & Pegawai

### T-021 — Lokasi
- Fase: 0D | Prioritas: P0 | Estimasi: 4 jam
- Memenuhi: FR-022, BR-041
- Dependensi: T-010, T-012, T-019
- Konteks wajib: `docs/04-DATABASE.md` §2.8, `docs/07-UI-SPEC.md` §3.4 (`locations`)
- File: migration, `app/Domain/Organization/{Models/Location,Actions/*,Policies/LocationPolicy}`, `Listings/LocationListing`, controller + `pages/Locations/{Index,Form}.tsx` (input koordinat + pratinjau peta + tombol "ambil dari tautan Google Maps" yang mem-parse koordinat dari URL yang ditempel)
- Kriteria penerimaan:
  - [x] Koordinat di luar Indonesia ditolak. Radius 20–5000
  - [x] Hapus lokasi yang dipakai → ditolak dengan arahan nonaktifkan
  - [x] Listing `locations` + ekspor lolos test kontrak. `kepala_lokasi` hanya melihat lokasinya
- Verifikasi: `php artisan test --filter=Location`
- Di luar lingkup: kepala lokasi (`head_employee_id`) dipilih setelah T-024 ada (field disiapkan, lookup aktif di T-024)

### T-022 — Departemen
- Fase: 0D | Prioritas: P0 | Estimasi: 3 jam
- Memenuhi: FR-023
- Dependensi: T-019
- Konteks wajib: `docs/04-DATABASE.md` §2.8, `docs/07-UI-SPEC.md` §3.4 (`departments`)
- File: migration, `app/Domain/Organization/...Department...`, `Listings/DepartmentListing`, `pages/Departments/{Index,Form}.tsx`
- Kriteria penerimaan:
  - [x] Induk tidak boleh membentuk siklus
  - [x] Listing + ekspor lolos test kontrak
- Verifikasi: `php artisan test --filter=Department`
- Di luar lingkup: —

### T-023 — Jabatan
- Fase: 0D | Prioritas: P0 | Estimasi: 3 jam
- Memenuhi: FR-024, BR-042
- Dependensi: T-022
- Konteks wajib: `docs/04-DATABASE.md` §2.8, `docs/07-UI-SPEC.md` §3.4 (`positions`)
- File: migration, `app/Domain/Organization/...Position...`, `Listings/PositionListing`, `pages/Positions/{Index,Form}.tsx`
- Kriteria penerimaan:
  - [x] Mode absen default tersimpan & tampil dengan penjelasan
  - [x] Atasan jabatan tidak membentuk siklus
  - [x] Listing + ekspor lolos test kontrak
- Verifikasi: `php artisan test --filter=Position`
- Di luar lingkup: —

### T-024 — Pegawai
- Fase: 0D | Prioritas: P0 | Estimasi: 8 jam
- Memenuhi: FR-025, FR-016 (buat akun dari pegawai), BR-050, BR-055, SEC-010, SEC-012, SEC-034, SEC-050 (foto)
- Dependensi: T-009, T-020, T-021, T-023
- Konteks wajib: `docs/04-DATABASE.md` §2.9, `docs/08-BUSINESS-RULES.md` BR-050, BR-055, `docs/09-SECURITY.md` SEC-010, `docs/07-UI-SPEC.md` §4 (Pegawai)
- File: migrations `employees` + FK `users.employee_id`, `app/Domain/Employee/{Models/Employee,Support/{NikCipher,PhoneNormalizer},Actions/{CreateEmployee,UpdateEmployee,DeleteEmployee,RevealNik,CreateAccountForEmployee},Policies/EmployeePolicy}`, `Listings/EmployeeListing`, controller + `pages/Employees/{Index,Form,Show}.tsx`, lookup `employees`
- Deskripsi: Pegawai baru wajib disertai penugasan pertama (form gabungan; logika penugasan memakai Action dari T-025, jadi buat dulu Action minimal `CreateAssignment` di task ini lalu dilengkapi T-025). Nomor dari `NumberGenerator`. NIK enc+hash, masked, reveal tercatat. Buat akun dari pegawai (username saran dari nama, unik). Halaman detail bertab (tab Jadwal & Absensi diisi di fase 0,5, sementara berupa placeholder bertanda T-035/T-038).
- Kriteria penerimaan:
  - [x] Dump tabel `employees` tidak memuat NIK plaintext. Audit NIK = `***`
  - [x] `reveal-nik` tanpa `employees.view_sensitive` → 403, dengan izin → event `sensitive_data_viewed`
  - [x] NIK duplikat → galat "NIK sudah terdaftar atas pegawai lain" (tanpa menyebut siapa)
  - [x] Field `employee_number` dari request diabaikan
  - [x] Listing `employees` (default status aktif) + ekspor lolos test kontrak, scope `location`/`team` teruji, IDOR show/update teruji
- Verifikasi: `php artisan test --filter=Employee`
- Di luar lingkup: mutasi (T-025), offboarding (T-026)

### T-025 — Penugasan & mutasi
- Fase: 0D | Prioritas: P0 | Estimasi: 5 jam
- Memenuhi: FR-026, BR-051, BR-052
- Dependensi: T-024
- Konteks wajib: `docs/04-DATABASE.md` §2.9 (`assignments`), `docs/08-BUSINESS-RULES.md` BR-051, BR-052, `docs/06-AUTHORIZATION.md` §11
- File: `app/Domain/Employee/Actions/{CreateAssignment,MutateEmployee,UpdateAssignment,EndAssignment}.php`, `Support/SupervisorCycleGuard.php`, `Listings/AssignmentListing`, controller + `pages/Assignments/Index.tsx`, dialog Mutasi di detail pegawai
- Deskripsi: Lengkapi logika penugasan. Mutasi atomik. Cek siklus atasan. Event `AssignmentChanged` (dipakai resolver absensi di T-037). Integrasikan test scope T-019 dengan data nyata.
- Kriteria penerimaan:
  - [x] Dua penugasan utama aktif → ditolak di DB & aplikasi
  - [x] Mutasi menutup lama (`end_date` = mulai baru − 1) dalam satu transaksi
  - [x] A atasan B, B atasan A → ditolak
  - [x] Listing `assignments` + ekspor lolos test kontrak
- Verifikasi: `php artisan test --filter=Assignment`
- Di luar lingkup: —

### T-026 — Offboarding & aktifkan kembali
- Fase: 0D | Prioritas: P0 | Estimasi: 4 jam
- Memenuhi: FR-027, BR-053, SEC-021
- Dependensi: T-025, T-031
- Konteks wajib: `docs/08-BUSINESS-RULES.md` BR-053, `docs/03-ARCHITECTURE.md` §7.2
- File: `app/Domain/Employee/Actions/{OffboardEmployee,RehireEmployee}.php`, dialog di `pages/Employees/Show.tsx`
- Deskripsi: Transaksi tunggal sesuai diagram `03` §7.2, termasuk pencabutan perangkat & token (tabel `devices` dari T-031). Action memancarkan event `EmployeeOffboarded`. Pembatalan koreksi `pending` **tidak** dikerjakan di sini karena tabelnya baru ada di T-040, yang menambahkan listener untuk event ini. Tampilkan ringkasan dampak & daftar bawahan sebelum konfirmasi.
- Kriteria penerimaan:
  - [ ] Setelah offboard: login web gagal, token API → 401, perangkat `revoked`, `head_employee_id` kosong
  - [ ] Gagal di tengah → rollback penuh (test dengan exception paksa)
  - [ ] Rehire wajib penugasan baru
- Verifikasi: `php artisan test --filter=Offboard`
- Di luar lingkup: —

### T-027 — Import pegawai: unggah, pemetaan, validasi
- Fase: 0D | Prioritas: P1 | Estimasi: 8 jam
- Memenuhi: FR-028, BR-054, SEC-050
- Dependensi: T-024, T-025
- Konteks wajib: `docs/08-BUSINESS-RULES.md` BR-054, `docs/04-DATABASE.md` §2.10, `docs/07-UI-SPEC.md` §4 (Impor pegawai)
- File: migrations import, `app/Domain/Employee/Import/{EmployeeImportReader,HeaderDetector,MappingSuggester,RowNormalizer,RowValidator,RowKey}.php`, `Listings/{ImportBatchListing,ImportRowListing,ImportProfileListing}`, controller + `pages/Imports/{Index,Create,Mapping,Show}.tsx`, berkas `resources/templates/templat_pegawai.xlsx`
- Deskripsi: Langkah 1–3 wizard (unggah, pemetaan + profil, validasi per baris dengan galat per sel). Validasi di antrean `imports` bila > 200 baris. NIK di `import_rows.data` termasking. Sinonim header sesuai BR-054.
- Kriteria penerimaan:
  - [ ] Fixture "berantakan" (header di baris 3, tanggal campur format, HP `0812-xxx`, kolom ekstra) terbaca benar
  - [ ] Berkas `.php` berganti nama `.xlsx` → ditolak
  - [ ] Profil pemetaan otomatis terpilih untuk header yang sama
  - [ ] 3 listing import lolos test kontrak
- Verifikasi: `php artisan test --filter=Import`
- Di luar lingkup: proses tulis ke DB (T-028)

### T-028 — Import pegawai: proses & laporan
- Fase: 0D | Prioritas: P1 | Estimasi: 5 jam
- Memenuhi: FR-028, BR-054
- Dependensi: T-027, T-029
- Konteks wajib: `docs/08-BUSINESS-RULES.md` BR-054
- File: `app/Domain/Employee/Import/ProcessEmployeeImport.php` (job), `Actions/ApplyImportRow.php`, unduh galat, notifikasi selesai
- Deskripsi: Proses idempoten per baris (create/update/skip), membuat penugasan untuk pegawai baru, peringatan beda penugasan, laporan akhir, berkas galat .xlsx dengan kolom pesan, audit `import.processed`.
- Kriteria penerimaan:
  - [ ] Proses berkas yang sama 2× → tidak ada duplikat (test)
  - [ ] Opsi "perbarui data" off → baris yang sudah ada `skipped`
  - [ ] Gagal di tengah → batch `failed`, baris yang sudah sukses tetap konsisten (transaksi per baris)
- Verifikasi: `php artisan test --filter=ImportProcess`
- Di luar lingkup: import jadwal/absensi

### T-029 — Notifikasi dalam aplikasi & email
- Fase: 0D | Prioritas: P1 | Estimasi: 4 jam
- Memenuhi: FR-030, BR-070 (kerangka + event Fase 0)
- Dependensi: T-006, T-013
- Konteks wajib: `docs/08-BUSINESS-RULES.md` BR-070, `docs/05-API-CONTRACT.md` §4.2
- File: tabel `notifications`, `app/Domain/Notification/{Notifications/*,NotificationRouter}.php`, `Listings/NotificationListing`, controller, komponen lonceng (polling 60 s), `pages/Notifications/Index.tsx`
- Deskripsi: Notifikasi database + email (antrean) untuk ekspor siap, impor selesai, password berubah/direset, login perangkat baru, akun terkunci. Router penerima yang dipakai event absensi nanti. **Diperluas (keputusan Mas 15 Nov 2026, `12`):** kanal Telegram & WhatsApp (driver WA menunggu OQ-004), saklar global + kanal per jenis (admin saja), kuota & jeda WA, cadangan WhatsApp → Telegram → Email, tabel `notification_deliveries` (BR-070, 08 §2.5).
- Kriteria penerimaan:
  - [x] Listing `notifications` lolos test kontrak. Tidak bisa membaca notifikasi orang lain (404)
  - [x] Email lewat antrean (test `Queue::fake`)
- Verifikasi: `php artisan test --filter=Notification`
- Di luar lingkup: notifikasi absensi (T-040, T-036)

## Fase 0E — Fondasi Mobile & API Auth

### T-030 — Scaffold Flutter Pakis Pro
- Fase: 0E | Prioritas: P0 | Estimasi: 7 jam
- Memenuhi: FR-042 (klien), NFR-008, NFR-009, ADR-005
- Dependensi: T-008
- Konteks wajib: `docs/03-ARCHITECTURE.md` §3, §5, `docs/07-UI-SPEC.md` §1, §5
- File: `mobile-pro/` (FVM `.fvmrc`, `pubspec.yaml` dengan paket `03` §5), `lib/core/{api/ApiClient,api/ApiError,config/AppConfig,theme/*,storage/SecureStore,device/DeviceId}.dart`, `lib/shared/widgets/{PagedList,FilterSheet,EmptyState,StatusBadge}.dart`, `mobile-pro/CLAUDE.md`, `.github/workflows/mobile.yml` (analyze + test + build APK debug)
- Deskripsi: Struktur fitur, tema terang/gelap dari token `07` §1.1 + warna dari config, klien API (header `X-Device-Id`, `X-App-Version`, `X-Request-Id`, pemetaan kode galat `05` §2.3 ke pesan Indonesia), penyimpanan aman, splash yang mengambil `/config` + gerbang versi (layar wajib update), komponen daftar paging + filter + cari untuk dipakai semua layar daftar mobile.
- Kriteria penerimaan:
  - [x] `flutter analyze` bersih, `flutter test` hijau, APK debug terbangun di CI
  - [x] `device_uid` = SHA-256(ANDROID_ID + install id) stabil antar restart
  - [x] Versi < `min_version` → layar update, tidak bisa lanjut
- Verifikasi: `fvm flutter test`
- Di luar lingkup: layar login (T-032)

### T-031 — API autentikasi, perangkat, middleware mobile
- Fase: 0E | Prioritas: P0 | Estimasi: 9 jam
- Memenuhi: FR-002, FR-003 (mobile), FR-004 (API), FR-005/FR-006 (API), FR-008 (API), FR-034, BR-027, BR-040, SEC-020, SEC-068
- Dependensi: T-015, T-016, T-019, T-024
- Konteks wajib: `docs/05-API-CONTRACT.md` §2, §5.1–§5.3, `docs/04-DATABASE.md` §2.12, `docs/06-AUTHORIZATION.md` §7.2, `docs/08-BUSINESS-RULES.md` BR-040
- File: migration `devices`, `app/Domain/Device/{Models/Device,Actions/{RegisterDeviceOnLogin,ApproveDevice,RejectDevice,RevokeDevice}}`, `Http/Middleware/{EnsureDeviceBound,EnsureAppVersion,EnsurePasswordChangedApi}.php`, `Api/V1/{AuthController,MeController}.php`, `Listings/DeviceListing`, `pages/Devices/Index.tsx`, `docs/openapi.yaml` (bagian auth)
- Deskripsi: Login API dengan MFA & registrasi perangkat (auto-approve pertama / pending). Token terikat perangkat (nama token = `device:{id}`, ability = permission mobile, kedaluwarsa `mobile.token_days`). Middleware perangkat, versi, wajib ganti password, rate limit. Endpoint me, ubah password, lupa password, reset OTP, cabut sesi web. Halaman web Perangkat dengan setujui/tolak/cabut. Notifikasi perangkat pending ke pemegang `devices.manage`.
- Kriteria penerimaan:
  - [ ] Token dipakai dengan `X-Device-Id` lain → 401 `DEVICE_MISMATCH` + token dicabut
  - [ ] HP kedua → 403 `DEVICE_PENDING`. Setujui → HP lama `replaced` & tokennya mati
  - [ ] Satu HP untuk dua akun aktif → ditolak (unique index)
  - [ ] `X-App-Version` < min → 426 di semua endpoint kecuali `/config`
  - [ ] Listing `devices` lolos test kontrak + scope `kepala_lokasi`
- Verifikasi: `php artisan test --filter=Api\\V1\\Auth`
- Di luar lingkup: layar mobile (T-032)

### T-032 — Layar autentikasi Pakis Pro
- Fase: 0E | Prioritas: P0 | Estimasi: 6 jam
- Memenuhi: FR-002, FR-004, FR-005, FR-006, FR-008, FR-034 (klien)
- Dependensi: T-030, T-031
- Konteks wajib: `docs/07-UI-SPEC.md` §5 (Login, Lupa password, Wajib ganti password)
- File: `lib/features/auth/*`, `lib/features/profile/change_password_*`
- Deskripsi: Login (username/email), input OTP MFA bila diminta, layar perangkat menunggu (tombol coba lagi), lupa password (kanal dari config), reset OTP, wajib ganti password, logout. Validasi password memakai panjang minimum dari config.
- Kriteria penerimaan:
  - [ ] Widget test semua cabang galat login (`INVALID_CREDENTIALS`, `MFA_REQUIRED`, `MFA_SETUP_REQUIRED`, `DEVICE_PENDING`, `ACCOUNT_LOCKED`, `APP_UPDATE_REQUIRED`)
  - [ ] Token hanya di secure storage (tidak di SharedPreferences)
- Verifikasi: `fvm flutter test test/features/auth`
- Di luar lingkup: absensi

## Fase 0,5 — Absensi

### T-033 — Shift
- Fase: 0,5 | Prioritas: P0 | Estimasi: 3 jam
- Memenuhi: FR-031, BR-060
- Dependensi: T-019
- Konteks wajib: `docs/04-DATABASE.md` §2.13 (`shifts`), `docs/07-UI-SPEC.md` §3.4 (`shifts`)
- File: migration, `app/Domain/Attendance/{Models/Shift,Actions/*,Policies/ShiftPolicy}`, `Listings/ShiftListing`, `pages/Shifts/{Index,Form}.tsx`, lookup `shifts`
- Kriteria penerimaan:
  - [x] Shift non-lintas-hari dengan jam pulang ≤ jam masuk ditolak
  - [x] Hapus shift yang dipakai → ditolak
  - [x] Listing lolos test kontrak
- Verifikasi: `php artisan test --filter=Shift`

### T-034 — Hari libur
- Fase: 0,5 | Prioritas: P0 | Estimasi: 3 jam
- Memenuhi: FR-033, BR-062
- Dependensi: T-021
- Konteks wajib: `docs/04-DATABASE.md` §2.13 (`holidays`), OQ-008
- File: migrations, `app/Domain/Attendance/...Holiday...`, `Listings/HolidayListing`, `pages/Holidays/{Index,Form}.tsx`
- Deskripsi: Satu libur bisa beberapa hari (rentang tanggal mulai–selesai, mis. Idul Fitri + cuti bersama), dengan nama dan keterangan (keputusan Mas 15 Nov 2026).
- Kriteria penerimaan:
  - [x] Libur per lokasi hanya berlaku untuk lokasi terpilih (scope `Holiday::appliesTo` diuji; resolver lengkap di T-035)
  - [x] Listing + ekspor lolos test kontrak (filter tahun default tahun ini)
  - [x] Rentang tanggal inklusif + keterangan; libur lintas tahun tampil di kedua tahun
- Verifikasi: `php artisan test --filter=Holiday`

### T-035 — Jadwal: pola, pengecualian, kalender
- Fase: 0,5 | Prioritas: P0 | Estimasi: 7 jam
- Memenuhi: FR-032, BR-061, BR-062
- Dependensi: T-025, T-033, T-034
- Konteks wajib: `docs/04-DATABASE.md` §2.13, `docs/08-BUSINESS-RULES.md` BR-061, BR-062, `docs/07-UI-SPEC.md` §3.4 (`schedules`, `schedule_overrides`), §4 (Jadwal)
- File: migrations, `app/Domain/Attendance/{ScheduleResolver,Actions/{SetShiftPattern,SetScheduleOverride,DeleteScheduleOverride}}`, `Listings/{ScheduleListing,ScheduleOverrideListing}`, controller + `pages/Schedules/{Index,Calendar}.tsx`, tab Jadwal di detail pegawai
- Deskripsi: Penetapan pola massal (pilih dari daftar ber-filter atau centang), pengecualian per tanggal (massal), kalender bulan × pegawai dengan paging/cari/filter. Resolver efektif (override > pola > libur).
- Kriteria penerimaan:
  - [ ] Resolver: unit test override vs pola vs libur lokasi vs override shift di hari libur
  - [ ] Pola dengan `effective_from` baru tidak menghapus pola lama (riwayat)
  - [ ] 2 listing lolos test kontrak. Kalender ber-paging 25 pegawai
- Verifikasi: `php artisan test --filter=Schedule`

### T-036 — API absen (inti)
- Fase: 0,5 | Prioritas: P0 | Estimasi: 9 jam
- Memenuhi: FR-035, FR-036 (server), BR-041, BR-042, BR-060, BR-063–BR-067, SEC-022, SEC-023, SEC-024, SEC-068
- Dependensi: T-031, T-035
- Konteks wajib: `docs/05-API-CONTRACT.md` §5.4, `docs/08-BUSINESS-RULES.md` §7, `docs/03-ARCHITECTURE.md` §4.5, §7.1, `docs/04-DATABASE.md` §2.13
- File: migrations `attendance_logs`, `attendance_days`, `app/Domain/Attendance/{Actions/RecordAttendance,Support/{WorkDateResolver,GeoFence,Haversine,SelfieStore,ClockGuard}}`, `Api/V1/AttendanceController@{store,today}`, `Requests/StoreAttendanceRequest`
- Deskripsi: Seluruh validasi absen (jendela, radius sesuai mode, akurasi, mock, offline, skew), idempotensi `client_uuid`, simpan selfie (validasi JPEG asli, buang EXIF, nama acak, disk privat), upsert `attendance_days` (memanggil resolver T-037 bila sudah ada, sementara hitung telat dasar), event `attendance_mock_detected` + notifikasi HRD & kepala lokasi.
- Kriteria penerimaan:
  - [ ] Test BR-063 shift lintas tengah malam (pulang 06.05 → `work_date` kemarin)
  - [ ] Test BR-064 tiga mode × dalam/luar radius, akurasi 250 m ditolak
  - [ ] Test BR-065 mock ditolak & tidak tersimpan sebagai log
  - [ ] Test BR-067 offline 70 jam diterima, 80 jam ditolak, replay `client_uuid` → 200 `replayed`
  - [ ] Unggah PNG/berkas palsu → 422
- Verifikasi: `php artisan test --filter=Attendance`
- Di luar lingkup: status harian lengkap (T-037)

### T-036A — Tugas luar kota (dinas luar)
- Fase: 0,5 | Prioritas: P1 | Estimasi: 9 jam
- Memenuhi: keputusan Mas 15 Nov 2026 (`12` Log Keputusan), BR-071 (baru)
- Dependensi: T-036, T-040 (pola ajukan–setujui & penyetuju 06 §6)
- Konteks wajib: `docs/12-OPEN-QUESTIONS.md` Log 15 Nov 2026 "Tugas luar kota", `docs/08-BUSINESS-RULES.md` BR-064, BR-069, BR-070, `docs/06-AUTHORIZATION.md` §5–§6
- Latar: sales, driver, dan kenek bisa ke luar kota dan menginap 1–2 malam. Hari biasa mereka absen di kantor/gudang (muat barang lalu berangkat); saat menginap, absen harus bisa dilakukan di lokasi tujuan.
- Rancangan (dicatat lebih dulu di 04/05/06/07/08 saat task dimulai, lalu test registri/katalog diperbarui):
  - Tabel `field_trips`: nomor (`NumberGenerator`), `start_date`–`end_date` (inklusif), tujuan (teks), keperluan, **aturan lokasi absen per tugas** `attendance_rule` = `anywhere` (di mana saja, lokasi & jarak tetap dicatat) | `destination` (radius sekitar titik tujuan; titik dari tempel tautan Google Maps seperti form Lokasi) | `any_location` (lokasi Pakis aktif mana pun), status `pending`/`approved`/`rejected`/`cancelled`, pengaju, penyetuju, catatan keputusan, [audit]. Tabel `field_trip_employees` (satu tugas banyak pegawai: sales + driver + kenek).
  - BR-071: pada `work_date` di dalam tugas `approved` milik pegawai, lokasi absen yang sah = aturan biasa BR-064 **∪** aturan tugas (absen di kantor sebelum berangkat tetap sah). Log diberi `flags.field_trip_id`; mode `anywhere` dari tugas tidak dihitung anomali. Tugas tidak mengubah jadwal/shift. Tugas `approved` yang tumpang tindih untuk pegawai yang sama ditolak. Panjang maksimal = setting baru (mis. `attendance.field_trip_max_days`).
  - Pembuat: HRD/Kepala Lokasi/Supervisor membuat langsung dalam scope-nya (langsung `approved`); pegawai mengajukan dari Pakis Pro → penyetuju & notifikasi mengikuti pola koreksi (06 §6, BR-070). Permission baru (mis. `field_trips.view`/`manage`/`request`/`approve`) → 06 §3–§4 + `PermissionCatalog`.
  - Web: daftar `field_trips` (cari nomor/tujuan/nama pegawai; filter status, tanggal DR default bulan ini, lokasi R, aturan; sort **-start_date**) + form + tab di detail pegawai (Kehadiran). Mobile (lanjutan di T-043): kartu "Tugas luar kota aktif" di beranda, ajukan tugas.
- Kriteria penerimaan:
  - [ ] Test BR-071: absen di luar radius kantor pada tanggal tugas diterima sesuai aturan tugas (3 aturan), di luar tanggal tugas ditolak; absen di kantor pada tanggal tugas tetap sah
  - [ ] Tugas tumpang tindih ditolak; tugas `pending`/`rejected` tidak membuka lokasi tambahan
  - [ ] Listing lolos test kontrak; IDOR test untuk route ber-ID
- Verifikasi: `php artisan test --filter=FieldTrip`

### T-037 — Resolver status harian & job
- Fase: 0,5 | Prioritas: P0 | Estimasi: 6 jam
- Memenuhi: FR-037, BR-052 (hitung ulang), BR-066, BR-068
- Dependensi: T-036
- Konteks wajib: `docs/08-BUSINESS-RULES.md` BR-066, BR-068, `docs/03-ARCHITECTURE.md` §4.6
- File: `app/Domain/Attendance/AttendanceStatusResolver.php`, command + schedule `attendance:resolve-day {date?}`, listener `AssignmentChanged` → hitung ulang
- Kriteria penerimaan:
  - [ ] Tabel kebenaran BR-068 (≥ 12 kasus) lulus
  - [ ] Job dijalankan 2× untuk tanggal sama → hasil identik
  - [ ] Snapshot lokasi/jabatan/departemen sesuai penugasan pada tanggal itu
- Verifikasi: `php artisan test --filter=StatusResolver`

### T-038 — Absensi harian (web)
- Fase: 0,5 | Prioritas: P0 | Estimasi: 6 jam
- Memenuhi: FR-039, SEC-013, SEC-033
- Dependensi: T-037
- Konteks wajib: `docs/07-UI-SPEC.md` §3.4 (`attendance_days`), §4 (Absensi harian), `docs/05-API-CONTRACT.md` §4.10
- File: `Listings/AttendanceDayListing`, `Admin/AttendanceController@{index,show,selfie,export}`, `pages/Attendance/{Index,Show}.tsx`, komponen `MapPin`, tab Absensi di detail pegawai
- Kriteria penerimaan:
  - [ ] Foto hanya lewat signed URL 5 menit dengan `attendance.view_photo` dalam scope (IDOR test)
  - [ ] Filter default hari ini. Filter status/lokasi/departemen/jabatan/penanda berfungsi
  - [ ] Listing + ekspor lolos test kontrak. Query p95 < 500 ms pada 200.000 baris (test performa sederhana dengan seeder besar, dijalankan manual)
- Verifikasi: `php artisan test --filter=AttendanceWeb`

### T-039 — Rekap bulanan (web)
- Fase: 0,5 | Prioritas: P1 | Estimasi: 5 jam
- Memenuhi: FR-039
- Dependensi: T-038
- Konteks wajib: `docs/07-UI-SPEC.md` §3.4 (`attendance_summary`), §4 (Rekap bulanan)
- File: `Listings/AttendanceSummaryListing` (agregasi SQL per pegawai), controller, `pages/Attendance/Summary.tsx`, kalender pegawai
- Kriteria penerimaan:
  - [ ] Angka rekap = agregasi `attendance_days` (test dengan fixture 1 bulan)
  - [ ] Sort `% kehadiran` & filter rentang % berfungsi
  - [ ] Listing + ekspor lolos test kontrak
- Verifikasi: `php artisan test --filter=AttendanceSummary`

### T-040 — Koreksi absen (backend & API)
- Fase: 0,5 | Prioritas: P0 | Estimasi: 6 jam
- Memenuhi: FR-038, BR-069, BR-070, SEC-031
- Dependensi: T-037, T-029
- Konteks wajib: `docs/06-AUTHORIZATION.md` §6, §7.1, `docs/08-BUSINESS-RULES.md` BR-069, `docs/05-API-CONTRACT.md` §5.7–§5.8, `docs/04-DATABASE.md` §2.13
- File: migration `attendance_corrections`, `app/Domain/Attendance/{Actions/{SubmitCorrection,ApproveCorrection,RejectCorrection,CancelCorrection},Policies/AttendanceCorrectionPolicy}`, `Api/V1/{AttendanceCorrectionController,ApprovalController}`, `Listings/{MyCorrectionListing,ApprovalCorrectionListing}`, listener `EmployeeOffboarded` → batalkan pending
- Kriteria penerimaan:
  - [ ] Menyetujui milik sendiri → 403 `CORRECTION_SELF_APPROVAL`
  - [ ] Pending ganda untuk tanggal sama → 409
  - [ ] Disetujui → `attendance_days` diperbarui, `is_corrected`, log asli utuh, audit lama/baru
  - [ ] Keputusan ganda paralel → satu sukses, satu 409 `STATE_CONFLICT`
  - [ ] Listing API `my_corrections` & `approval_corrections` lolos test kontrak
- Verifikasi: `php artisan test --filter=Correction`

### T-041 — Koreksi absen (web)
- Fase: 0,5 | Prioritas: P0 | Estimasi: 4 jam
- Memenuhi: FR-038
- Dependensi: T-040
- Konteks wajib: `docs/07-UI-SPEC.md` §3.4 (`attendance_corrections`), §4 (Koreksi)
- File: `Listings/AttendanceCorrectionListing`, `Admin/AttendanceCorrectionController`, `pages/AttendanceCorrections/{Index,Show}.tsx`
- Kriteria penerimaan:
  - [ ] Default filter `pending`. Scope `kepala_lokasi`/`supervisor` teruji
  - [ ] Listing + ekspor lolos test kontrak. IDOR approve teruji
- Verifikasi: `php artisan test --filter=CorrectionWeb`

### T-042 — Absen di Pakis Pro (kamera, GPS, offline)
- Fase: 0,5 | Prioritas: P0 | Estimasi: 10 jam
- Memenuhi: FR-035, FR-036, NFR-004, NFR-007, NFR-014, BR-067 (klien), SEC-022, SEC-023
- Dependensi: T-032, T-036
- Konteks wajib: `docs/07-UI-SPEC.md` §5 (Absen, Alur absen), `docs/03-ARCHITECTURE.md` §4.5, `docs/05-API-CONTRACT.md` §5.4
- File: `lib/features/attendance/{home,capture,result}/*`, `lib/core/offline/{queue_db.dart (drift + sqlcipher),sync_service.dart}`, `lib/core/location/location_service.dart`
- Deskripsi: Beranda absen, kamera depan tanpa galeri, kompresi ≤ 100 KB, GPS dengan batas waktu & pesan akurasi, `isMocked`, kirim. Antrean offline terenkripsi + sinkron otomatis (buka app, konektivitas kembali, tombol), backoff, idempoten. Simpan selisih jam dari header `Date` respons.
- Kriteria penerimaan:
  - [ ] Integration test: mode pesawat → absen → online → terkirim 1× walau dikirim ulang
  - [ ] Foto hasil ≤ 100 KB, sisi terpanjang 720 px
  - [ ] Antrean tidak bisa dibaca tanpa kunci (DB terenkripsi)
- Verifikasi: `fvm flutter test` + uji di HP Android 8 nyata

### T-043 — Riwayat, koreksi, persetujuan, notifikasi, profil (Pakis Pro)
- Fase: 0,5 | Prioritas: P0 | Estimasi: 8 jam
- Memenuhi: FR-003 (mobile), FR-010 (mobile), FR-038 (klien), FR-041, FR-030 (mobile)
- Dependensi: T-040, T-042
- Konteks wajib: `docs/07-UI-SPEC.md` §5, `docs/05-API-CONTRACT.md` §5.1, §5.5–§5.8
- File: `Api/V1/{AttendanceController@index,summary,NotificationController,MeController@update}`, `Listings/{MyAttendanceDayListing,MyNotificationListing}`, `lib/features/{history,corrections,approvals,notifications,profile}/*`
- Deskripsi: Endpoint daftar & rekap milik sendiri + notifikasi (backend), lalu layar mobile: riwayat (paging, cari, filter bulan/status, chip), rekap bulan, koreksi saya + form, persetujuan (tab hanya bila berhak), notifikasi, profil (ubah HP/foto, ubah password, keluar sesi web lain).
- Kriteria penerimaan:
  - [ ] Listing API `my_attendance_days` & `my_notifications` lolos test kontrak
  - [ ] Semua layar daftar mobile memakai `PagedList` + `FilterSheet` (paging, cari, filter)
  - [ ] Pegawai tanpa `attendance_corrections.approve` tidak melihat tab Persetujuan
- Verifikasi: `php artisan test --filter=Api\\V1` · `fvm flutter test`

### T-044 — Dasbor web
- Fase: 0,5 | Prioritas: P1 | Estimasi: 4 jam
- Memenuhi: FR-040
- Dependensi: T-038, T-041
- Konteks wajib: `docs/07-UI-SPEC.md` §4 (Dasbor)
- File: `Admin/DashboardController`, `app/Domain/Attendance/Queries/TodaySummary.php`, `pages/Dashboard.tsx`
- Kriteria penerimaan:
  - [ ] Angka mengikuti scope pengguna (test `kepala_lokasi`)
  - [ ] Kartu "pending" menaut ke daftar dengan filter yang sesuai (URL state)
- Verifikasi: `php artisan test --filter=Dashboard`

### T-045 — Job terjadwal: retensi, alert, integritas
- Fase: 0,5 | Prioritas: P1 | Estimasi: 5 jam
- Memenuhi: NFR-004, NFR-011, NFR-013, SEC-013, SEC-041 (alert), SEC-070
- Dependensi: T-038, T-007
- Konteks wajib: `docs/09-SECURITY.md` SEC-013, SEC-041, SEC-070, `docs/08-BUSINESS-RULES.md` §2.5
- File: commands `attendance:purge-selfies`, `retention:purge`, `exports:expire`, `security:scan-alerts`, `integrity:check`, `security:revoke`, `routes/console.php` (jadwal)
- Kriteria penerimaan:
  - [ ] Selfie > retensi terhapus dari disk, `selfie_purged_at` terisi, baris log tetap ada
  - [ ] 20 login gagal dari satu IP dalam 10 menit → email alert (fake)
  - [ ] Berkas `.php` baru di `storage/` terdeteksi
- Verifikasi: `php artisan test --filter=Scheduled`

## Fase H — Hardening, Rilis, Serah Terima

### T-046 — Deploy staging & produksi, backup
- Fase: H | Prioritas: P0 | Estimasi: 10 jam
- Memenuhi: NFR-005, NFR-006, SEC-011, SEC-064, SEC-065
- Dependensi: T-045 · **Terblokir OQ-002, OQ-012** (bagian produksi)
- Konteks wajib: `docs/03-ARCHITECTURE.md` §8, `docs/09-SECURITY.md` §3.7
- File: `deploy/{nginx.conf,supervisor.conf,php-fpm.conf,backup.sh,restore.md,hardening-checklist.md}`, `.github/workflows/deploy.yml`
- Kriteria penerimaan:
  - [ ] Deploy zero-downtime ke staging dari CI
  - [ ] `storage/` & `public/uploads` tidak mengeksekusi PHP (uji berkas dummy)
  - [ ] Backup terenkripsi terkirim ke lokasi luar server + **uji restore** tercatat
  - [ ] Checklist hardening SEC-065 tercentang
- Verifikasi: runbook `deploy/restore.md` dijalankan di staging

### T-047 — UAT, uji keamanan & beban, rilis APK
- Fase: H | Prioritas: P0 | Estimasi: 10 jam
- Memenuhi: NFR-001, NFR-002, NFR-003, SEC-067, `09` §7.2
- Dependensi: T-046 · **Terblokir OQ-002, OQ-006, OQ-009** (go-live)
- Konteks wajib: `docs/10-TEST-PLAN.md` §5–§8, `docs/09-SECURITY.md` §7.2
- File: `tests/load/attendance.k6.js`, `docs/uat/UAT_Fase0.xlsx` (dari `10` §7), catatan hasil
- Kriteria penerimaan:
  - [ ] k6 lulus target `10` §8
  - [ ] Checklist `09` §7.2 tercentang semua
  - [ ] APK rilis ditandatangani, obfuscated, `allowBackup=false`
  - [ ] UAT-01 s.d. UAT-20 diparaf Pakis
- Verifikasi: laporan UAT

### T-048 — Dokumentasi serah terima
- Fase: H | Prioritas: P1 | Estimasi: 6 jam
- Memenuhi: SEC-014
- Dependensi: T-047
- Konteks wajib: `docs/` seluruhnya
- File: `docs/manual/{Manual_Admin_HRD.md,Manual_Pegawai_PakisPro.md,Panduan_Instalasi.md,Prosedur_Hak_Subjek_Data.md}`, `docs/openapi.yaml` (lengkap), materi pelatihan singkat
- Kriteria penerimaan:
  - [ ] Manual pegawai ≤ 8 halaman dengan tangkapan layar, bahasa awam
  - [ ] `openapi.yaml` valid dan mencakup semua endpoint `05` §5.1
- Verifikasi: review Mas

---

## Fase HR — Riwayat Kepegawaian (produk Sintech; dikerjakan setelah Fase 0,5)

Sumber kebenaran: `docs/13-FASE-HR.md` (keputusan Mas 15 Nov 2026). Setiap task **memindahkan** bagiannya ke
`04`/`05`/`06`/`07`/`08`/`09`/`10` beserta test registri/katalog/matriks, dan modulnya mengikuti `hr.modules`
(modul mati → sub-tab tidak tampil, route 404).

### T-049 — Fondasi modul HR
- Fase: HR | Prioritas: P0 | Estimasi: 8 jam
- Memenuhi: `13` §0, §2–§4, §6; SEC-012 (revisi)
- Dependensi: T-024, T-025, T-045
- Konteks wajib: `docs/13-FASE-HR.md`, `docs/08-BUSINESS-RULES.md` §2–§3, `docs/06-AUTHORIZATION.md` §3–§4, `docs/09-SECURITY.md` SEC-012
- File: `SettingRegistry` (`hr.*`), `ReferenceGroup` (grup baru + meta `employment_status`), `PermissionCatalog`/`RoleCatalog` (permission baru), `app/Domain/Employee/Support/HrModules.php`, kerangka sub-tab dinamis di `pages/employees/show.tsx`, komponen `components/pakis/history-list.tsx`
- Kriteria penerimaan:
  - [ ] `hr.modules` + `hr.*` di registri = `08` §2 (test registri), default kelas B/C mati
  - [ ] Modul mati → sub-tab tidak dikirim ke halaman, route modulnya 404 (test)
  - [ ] Permission baru di `06` §3–§4 = katalog = seeder (test matriks)
  - [ ] SEC-012 di `09` direvisi sesuai `13` §4
- Verifikasi: `php artisan test --filter=Hr`

### T-050 — Dokumen elektronik
- Fase: HR | Prioritas: P0 | Estimasi: 8 jam
- Memenuhi: `13` §5 (`employee_documents`), SEC-050
- Dependensi: T-049, T-029
- File: migration `employee_documents`, `app/Domain/Employee/{Models/EmployeeDocument,Actions/*Document*}`, sub-tab `dokumen`, route unduh ber-scope, pengingat kedaluwarsa (`hr.document_reminder_days`)
- Kriteria penerimaan:
  - [ ] Unggah PDF/JPG/PNG saja; `.php` berganti nama → 422; disk privat, unduh hanya lewat route ber-auth & ber-scope
  - [ ] Dokumen kelas C hanya terbuka dengan `employees.view_health`, setiap buka tercatat `sensitive_data_viewed`
  - [ ] Daftar mini sub-tab: paging 10, urut tanggal terbaru, dialog tambah/ubah/hapus; ubah & hapus diaudit; IDOR (pegawai di luar scope → 404) teruji
  - [ ] Notifikasi dokumen akan kedaluwarsa terkirim sekali per dokumen
- Verifikasi: `php artisan test --filter=EmployeeDocument`

### T-051 — Riwayat status kerja (kontrak, capeg, tetap)
- Fase: HR | Prioritas: P0 | Estimasi: 8 jam
- Memenuhi: `13` §5 (`employment_histories`)
- Dependensi: T-049, T-050
- File: migration + migrasi data awal, `Actions/{AddEmploymentHistory,…}`, sub-tab `status-kerja`, listing lintas pegawai "kontrak akan berakhir" (katalog `07` §3.4), pengingat (`hr.contract_reminder_days`)
- Kriteria penerimaan:
  - [ ] Rentang tidak tumpang tindih; status ber-`requires_end_date` wajib tanggal selesai
  - [ ] `employees.employment_status_id` selalu = riwayat yang berlaku hari ini
  - [ ] Pegawai lama mendapat satu baris awal dari `join_date` (migrasi data idempoten)
  - [ ] Listing kontrak berakhir + ekspor lolos test kontrak
  - [ ] Daftar mini sub-tab: paging 10, urut tanggal terbaru, dialog tambah/ubah/hapus; ubah & hapus diaudit; IDOR (pegawai di luar scope → 404) teruji
- Verifikasi: `php artisan test --filter=EmploymentHistory`

### T-052 — Pendidikan & pengalaman kerja
- Fase: HR | Prioritas: P1 | Estimasi: 5 jam
- Memenuhi: `13` §5 (`educations`, `work_experiences`)
- Dependensi: T-049, T-050
- Kriteria penerimaan:
  - [ ] Pendidikan terakhir = jenjang tertinggi menurut urutan referensi
  - [ ] Daftar mini sub-tab: paging 10, urut tanggal terbaru, dialog tambah/ubah/hapus; ubah & hapus diaudit; IDOR (pegawai di luar scope → 404) teruji
- Verifikasi: `php artisan test --filter=Education`

### T-053 — Pangkat / golongan
- Fase: HR | Prioritas: P2 | Estimasi: 5 jam
- Memenuhi: `13` §5 (`rank_histories`)
- Dependensi: T-049, T-050
- Kriteria penerimaan:
  - [ ] Pangkat berlaku = riwayat terakhir ≤ hari ini, tampil di header detail pegawai
  - [ ] Daftar mini sub-tab: paging 10, urut tanggal terbaru, dialog tambah/ubah/hapus; ubah & hapus diaudit; IDOR (pegawai di luar scope → 404) teruji
- Verifikasi: `php artisan test --filter=Rank`

### T-054 — Diklat & penghargaan
- Fase: HR | Prioritas: P1 | Estimasi: 5 jam
- Memenuhi: `13` §5 (`trainings`, `awards`)
- Dependensi: T-049, T-050
- Kriteria penerimaan:
  - [ ] Total jam diklat per tahun tampil di sub-tab
  - [ ] Daftar mini sub-tab: paging 10, urut tanggal terbaru, dialog tambah/ubah/hapus; ubah & hapus diaudit; IDOR (pegawai di luar scope → 404) teruji
- Verifikasi: `php artisan test --filter=Training`

### T-055 — Sanksi
- Fase: HR | Prioritas: P1 | Estimasi: 6 jam
- Memenuhi: `13` §4 (kelas B), §5 (`sanctions`)
- Dependensi: T-049, T-050
- Kriteria penerimaan:
  - [ ] Tanpa `employees.view_disciplinary` → sub-tab tidak tampil & route 403/404
  - [ ] Sanksi tidak bisa dihapus, hanya dibatalkan dengan alasan; status berlaku/kedaluwarsa/dibatalkan benar
  - [ ] Daftar mini sub-tab: paging 10, urut tanggal terbaru, dialog tambah/ubah/hapus; ubah & hapus diaudit; IDOR (pegawai di luar scope → 404) teruji
- Verifikasi: `php artisan test --filter=Sanction`

### T-056 — Keluarga
- Fase: HR | Prioritas: P1 | Estimasi: 7 jam
- Memenuhi: `13` §4 (kelas B/C), §5 (`family_members`), SEC-010 (pola NIK)
- Dependensi: T-049, T-050
- Kriteria penerimaan:
  - [ ] NIK anggota keluarga tidak pernah plaintext di DB/log/audit/ekspor; reveal tercatat
  - [ ] Status nikah diturunkan dari pasangan aktif; data anak hanya dengan `employees.view_family`
  - [ ] Daftar mini sub-tab: paging 10, urut tanggal terbaru, dialog tambah/ubah/hapus; ubah & hapus diaudit; IDOR (pegawai di luar scope → 404) teruji
- Verifikasi: `php artisan test --filter=Family`

### T-057 — Kesehatan, psikotes & persetujuan pegawai
- Fase: HR | Prioritas: P1 | Estimasi: 8 jam
- Memenuhi: `13` §4 (kelas C), §5 (`health_records`, `psych_tests`, `employee_consents`)
- Dependensi: T-049, T-050
- Kriteria penerimaan:
  - [ ] Isi terenkripsi; dump tabel tidak memuat ringkasan/hasil plaintext
  - [ ] Tanpa persetujuan pegawai yang berlaku → data baru ditolak
  - [ ] Setiap pembukaan tercatat `sensitive_data_viewed`; data dihapus permanen setelah `hr.sensitive_retention_days` sejak pegawai keluar (command terjadwal, teruji)
  - [ ] Daftar mini sub-tab: paging 10, urut tanggal terbaru, dialog tambah/ubah/hapus; ubah & hapus diaudit; IDOR (pegawai di luar scope → 404) teruji
- Verifikasi: `php artisan test --filter=Health`

### T-058 — Cetak CV (PDF)
- Fase: HR | Prioritas: P1 | Estimasi: 5 jam
- Memenuhi: `13` §0 butir 6, §2
- Dependensi: T-051, T-052 · **Butuh persetujuan dependency PDF** (di luar `03` §5)
- Kriteria penerimaan:
  - [ ] PDF memakai nama, logo, dan warna perusahaan dari Pengaturan
  - [ ] Isi mengikuti modul aktif & izin pelaku (kelas B/C tidak ikut tanpa izinnya); NIK termasking
  - [ ] Tercatat audit `employee.cv_printed`
- Verifikasi: `php artisan test --filter=Cv`

### T-059 — Data administrasi pegawai
- Fase: HR | Prioritas: P0 | Estimasi: 5 jam
- Memenuhi: `13` §8 (`admin_data`)
- Dependensi: T-049
- Kriteria penerimaan:
  - [ ] NPWP & nomor rekening terenkripsi + termasking, reveal tercatat; tidak pernah plaintext di DB/log/audit/ekspor
  - [ ] Kontak darurat & status PTKP tersimpan; modul mati → tidak tampil
- Verifikasi: `php artisan test --filter=AdminData`

### T-060 — Cuti & izin
- Fase: HR | Prioritas: P0 | Estimasi: 12 jam
- Memenuhi: `13` §8 (`leave`)
- Dependensi: T-049, T-038, T-041
- File: migration jenis/hak/pengajuan cuti, Actions pengajuan & persetujuan, listing `leave_requests` (katalog `07` §3.4), layar Pakis Pro, perluasan `AttendanceStatusResolver` (status `leave`)
- Kriteria penerimaan:
  - [ ] Saldo tahunan per pegawai dari Pengaturan/Referensi; saldo tidak minus kecuali jenisnya mengizinkan
  - [ ] Persetujuan mengikuti pola BR-069 (tidak menyetujui milik sendiri, atasan/scope)
  - [ ] Hari cuti yang disetujui → status harian `leave`, bukan `absent`
  - [ ] Listing + ekspor lolos test kontrak; IDOR teruji
- Verifikasi: `php artisan test --filter=Leave`

### T-061 — Lembur
- Fase: HR | Prioritas: P1 | Estimasi: 8 jam
- Memenuhi: `13` §8 (`overtime`)
- Dependensi: T-049, T-038
- Kriteria penerimaan:
  - [ ] Jam lembur aktual dihitung dari log absensi, dibatasi jam yang disetujui
  - [ ] Rumus upah lembur dari Pengaturan (tanpa konstanta di kode); listing + ekspor lolos test kontrak
- Verifikasi: `php artisan test --filter=Overtime`

### T-062 — Kasbon
- Fase: HR | Prioritas: P2 | Estimasi: 6 jam
- Memenuhi: `13` §8 (`loan`)
- Dependensi: T-049
- Kriteria penerimaan:
  - [ ] Cicilan tidak melewati batas % gaji (setting); sisa pinjaman akurat setelah tiap periode
  - [ ] Listing + ekspor lolos test kontrak
- Verifikasi: `php artisan test --filter=Loan`

### T-063 — Komponen & struktur gaji
- Fase: HR | Prioritas: P0 | Estimasi: 8 jam
- Memenuhi: `13` §8 (`payroll`)
- Dependensi: T-049, T-059
- Kriteria penerimaan:
  - [ ] Komponen gaji = Referensi (pokok, tunjangan tetap/tidak tetap, potongan), struktur gaji per pegawai berlaku per tanggal
  - [ ] Nominal disimpan integer rupiah; hanya `payroll.view`/`payroll.process` yang melihat
- Verifikasi: `php artisan test --filter=SalaryStructure`

### T-064 — Proses payroll, BPJS & PPh 21
- Fase: HR | Prioritas: P0 | Estimasi: 14 jam
- Memenuhi: `13` §8 (`payroll`)
- Dependensi: T-063, T-060, T-061, T-062
- Kriteria penerimaan:
  - [ ] Hitung per periode: gaji, tunjangan, lembur, cuti tidak dibayar, kasbon, BPJS (Kes/JHT/JP/JKK/JKM), PPh 21
  - [ ] Tarif BPJS, batas upah, dan tabel PPh 21 dari Pengaturan/tabel yang bisa diperbarui (bukan konstanta); contoh perhitungan resmi lolos test
  - [ ] Periode terkunci tidak bisa diubah; proses ulang idempoten; diaudit
- Verifikasi: `php artisan test --filter=Payroll`

### T-065 — Slip gaji & laporan payroll
- Fase: HR | Prioritas: P0 | Estimasi: 6 jam
- Memenuhi: `13` §8 (slip gaji)
- Dependensi: T-064, T-058
- Kriteria penerimaan:
  - [ ] Slip PDF per pegawai per periode; pegawai hanya melihat slipnya (web & Pakis Pro); buka slip orang lain tercatat
  - [ ] Rekap periode + ekspor transfer bank lolos test kontrak listing
- Verifikasi: `php artisan test --filter=Payslip`

### T-066 — Penilaian kinerja
- Fase: HR | Prioritas: P2 | Estimasi: 8 jam
- Memenuhi: `13` §8 (`appraisal`)
- Dependensi: T-049
- Kriteria penerimaan:
  - [ ] Periode, kriteria & bobot dari Referensi; penilai = atasan langsung/HRD (scope teruji)
  - [ ] Nilai akhir terhitung dari bobot; listing + ekspor lolos test kontrak
- Verifikasi: `php artisan test --filter=Appraisal`
