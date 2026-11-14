# Implementasi_Plan — PAKIS ONE Fase 0 + 0,5

Dokumen kendali utama. **Setiap sesi AI agent dimulai dengan membaca `CLAUDE.md` lalu §6 dokumen ini**, kemudian melanjutkan dari status terakhir.

## 1. Tujuan

Membangun fondasi PAKIS ONE untuk CV Pakis Jaya Abadi, yaitu:
- core organisasi & pegawai,
- akun & hak akses dinamis,
- site setting,
- autentikasi lengkap,
- audit,
- absensi lewat Pakis Pro.

Semua daftar memakai standar paging, pencarian, filter, dan sort yang ditegakkan test.

## 2. Fase & Exit Gate

| Fase | Tujuan | Task | Deliverable yang bisa didemokan | Exit gate (semua harus terpenuhi) |
|---|---|---|---|---|
| **0A Fondasi teknis** | Kerangka, CI, standar listing, ekspor, audit | T-001–T-007 | Halaman demo listing, audit log tampil | CI hijau (gitleaks, audit, Larastan, Pest). `ListingContractTest` aktif dan menolak route tanpa standar. Trigger audit append-only teruji |
| **0B Setting & master** | Semua dinamis | T-008–T-012 | Halaman Pengaturan (semua grup), penomoran, referensi, wilayah | Registri = `08` §2 (test). `/api/v1/config` hanya kunci publik. Test konkurensi penomoran lulus |
| **0C Autentikasi & akses** | Login aman, password, MFA, role, scope, pengguna | T-013–T-020 | Login + lupa/ubah password + MFA + kelola role & pengguna | SEC-001–SEC-009 teruji. Matriks permission identik `06` §4. Harness IDOR siap |
| **0D Organisasi & pegawai** | Data pegawai rapi | T-021–T-029 | Lokasi, departemen, jabatan, pegawai, mutasi, offboarding, impor Excel, notifikasi | Semua listing lolos test kontrak. NIK tidak pernah plaintext. Offboarding mencabut akses (test). Impor idempoten |
| **0E Mobile & API auth** | Pakis Pro bisa login dengan perangkat terikat | T-030–T-032 | APK debug: login, MFA, perangkat menunggu, lupa/ubah password | Token terikat perangkat teruji. Gerbang versi jalan. `flutter analyze` bersih |
| **0,5 Absensi** | Absen selfie+GPS, offline, koreksi, rekap | T-033–T-045 | Absen dari HP (online & offline), rekap, koreksi, dasbor | BR-060–BR-070 teruji. MOB-01–MOB-09 lulus. Retensi selfie jalan |
| **H Hardening & rilis** | Siap dipakai Pakis | T-046–T-048 | Staging + produksi, UAT, manual | Checklist `09` §7.2 lengkap. Uji restore tercatat. UAT diparaf. OQ-002/006/009/012 terjawab |

## 3. Urutan Eksekusi (mengikuti dependensi)

1. **0A:** T-001 → T-002 → T-003 → T-004 → T-005 → T-006 → T-007
2. **0B:** T-008 → T-009 → T-010 → T-011 → T-012
3. **0C:** T-013 → T-014 → T-015 → T-017 → T-018 → T-016 → T-019 → T-020
4. **0D:** T-021 → T-022 → T-023 → T-024 → T-025 → T-029 → T-027 → T-028
5. **0E (+ sisa 0D):** T-030 → T-031 → **T-026** → T-032
6. **0,5:** T-033 → T-034 → T-035 → T-036 → T-037 → T-038 → T-039 → T-040 → T-041 → T-042 → T-043 → T-044 → T-045
7. **H:** T-046 → T-047 → T-048

Task yang boleh paralel bila ada dua sesi agent: T-030 (Flutter) bisa mulai setelah T-008 berjalan bersamaan dengan Fase 0C–0D. T-033/T-034 bisa paralel dengan T-030–T-032.

```mermaid
gantt
  title Rencana Fase 0 + 0,5 (asumsi mulai Senin 9 Nov 2026, 1 developer + Claude Code)
  dateFormat YYYY-MM-DD
  axisFormat %d %b
  section Fondasi
  0A Fondasi teknis (37 jam)          :a1, 2026-11-09, 6d
  0B Setting dan master (24 jam)      :a2, after a1, 4d
  section Akses dan Pegawai
  0C Autentikasi dan akses (41 jam)   :a3, after a2, 7d
  0D Organisasi dan pegawai (44 jam)  :a4, after a3, 7d
  section Mobile
  0E Mobile dan API auth (22 jam)     :a5, after a4, 4d
  section Absensi
  0,5 Absensi (76 jam)                :a6, after a5, 12d
  section Rilis
  H Hardening, UAT, serah terima (26 jam) :a7, after a6, 5d
```

Total ±270 jam efektif ≈ **8–9 minggu kerja** (±6,5 jam efektif/hari). Belum termasuk menunggu jawaban OQ dan jadwal UAT di pihak Pakis.

## 4. Risiko Pelaksanaan

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Dependency belum kompatibel PHP 8.5 | Tertunda di T-001 | Fallback PHP 8.4 (ADR-003) |
| HP pegawai sangat lama / GPS buruk di area sawah | Absen gagal, keluhan | Uji lapangan MOB-10 di awal Fase 0,5, sesuaikan `max_accuracy_m` & radius lewat setting |
| Pegawai belum punya email | Lupa password tidak bisa mandiri | Reset oleh admin (FR-007). Kanal WA setelah OQ-004 |
| Data Excel pegawai sangat berantakan | Impor lama | Pemetaan kolom + profil + berkas galat. Uji dengan sampel asli yang disamarkan (OQ-001) di staging |
| Resistensi pegawai terhadap selfie/GPS | Adopsi rendah | Pemberitahuan tertulis (OQ-009), lokasi hanya saat absen, sosialisasi oleh Owner |
| Lingkup bertambah di tengah jalan | Molor | Perubahan masuk `12-OPEN-QUESTIONS.md` dan task baru, tidak disisipkan diam-diam |

## 5. Kebutuhan dari Pakis Sebelum/Selama Pelaksanaan

- Jawaban OQ-001, OQ-002, OQ-006, OQ-008, OQ-009, OQ-011, OQ-012 (lihat `docs/12-OPEN-QUESTIONS.md`).
- Contoh Excel daftar pegawai (data disamarkan) untuk menyetel impor.
- Titik koordinat setiap kantor/gudang/toko (bisa dari tautan Google Maps).
- Satu orang HRD dan satu kepala lokasi sebagai penguji UAT.

## 6. Status Progres (diperbarui agent setiap task selesai)

### 6.1 Ringkasan

| Fase | Total task | Selesai | Sedang | Blocked |
|---|---|---|---|---|
| 0A | 7 | 7 | 0 | 0 |
| 0B | 5 | 5 | 0 | 0 |
| 0C | 8 | 3 | 0 | 0 |
| 0D | 9 | 0 | 0 | 0 |
| 0E | 3 | 1 | 0 | 0 |
| 0,5 | 13 | 0 | 0 | 0 |
| H | 3 | 0 | 0 | 0 |
| **Total** | **48** | **16** | **0** | **0** |

### 6.2 Sedang dikerjakan
- (kosong) — berikutnya T-017 (profil & sesi; termasuk tombol "Hubungkan Telegram"), lalu T-018, T-016 (urutan §3). **T-030 (Flutter) selesai** (d0dcfa1, agen paralel). Mobile berikutnya T-032 (butuh T-031).

### 6.3 Blocker aktif
- (kosong)

### 6.4 Log

| Tanggal | Task | Status | Commit | Catatan |
|---|---|---|---|---|
| 2026-11-03 | — | Paket dokumen v1.0 dibuat | — | Siap mulai T-001 |
| 2026-11-14 | — | Persiapan lingkungan | d41aac6 | Root repo dipindah ke `D:\program\pakis` + `git init`. Keputusan: MariaDB 11.8 (ADR-012 direvisi, OQ-014 baru), driver `database` lokal, OQ-003 terjawab (`konsep/region.sql`) |
| 2026-11-14 | T-001 | Selesai | 263cf01 (PR #1) | Laravel 13.42 + React starter kit (dev-main) di `backend/`. Spatie 8.5, Sanctum 4.4, OpenSpout 5.13, Pest 5.3, Larastan 3.15. OQ-013 terjawab (PHP 8.5 OK). Registrasi mandiri & passkeys dimatikan, "ingat saya" dihapus (SEC-005). 49 test hijau, Pint/Larastan/lint/tsc/build bersih |

| 2026-11-14 | T-002 | Selesai | e2cc0da, ebf9ca1, da56ed8 | CI `backend.yml` (Pint, Larastan 7, Pest di `mariadb:11.8.9`, lint/tsc/build, audit), `secrets.yml` (gitleaks 8.30.1, riwayat penuh), `mobile.yml` kerangka. Pre-commit gitleaks + Pint. OQ-014 terjawab (MariaDB di semua lingkungan). Kanari PR #2 merah di gitleaks lalu ditutup. Perbaikan: Pest serial (bug `--parallel`), `withoutVite()` di `TestCase` |

| 2026-11-14 | T-003 | Selesai | 4be1152 | Request ID ULID (header, body galat, log via Context), log JSON harian, `ErrorCode` (33 kode, sinkron dua arah dengan `05` §2.3 lewat test) + `ApiError` + `ApiExceptionRenderer`, halaman galat web Inertia, `SecurityHeaders` + CSP nonce (`CSP_MODE`). 4 kode galat baru disetujui Mas. CSP diuji di Chromium mode enforce: style runtime library terblokir → style `'unsafe-inline'`. 89 test hijau |

| 2026-11-14 | T-004 | Selesai | 0e299a9 | `app/Support/Listing`: `ListingSpec`, `ListingQuery`, `ListingResult` (`toInertia`/`toApi` sesuai `05` §3.2–3.3, `query()` untuk ekspor), 6 tipe filter + `TextSearch` + `Sort`, `HasListing`, `#[UsesListing]`. `ui.default_per_page`/`ui.max_per_page` sementara di `ListingDefaults` (`TODO(T-008)`). 63 test listing di MariaDB (fixture tabel `users`), uji mutasi escape & tie-breaker. 152 test hijau |

| 2026-11-14 | T-005 | Selesai | 5581930, 5acc76a | `DataTable` + `FilterBar`/`FilterSheet`/`FilterField`/`ActiveFilterChips`/`Pagination`/`PerPageSelect`/`EmptyState`/`TableSkeleton`/`StatusBadge`, `hooks/use-listing.ts`, `lib/listing-query.ts`, `types/listing.ts`, `ui/table.tsx`. Token `--status-*` AA. `ListingContractTest` (+ meta-test route patuh/tidak patuh, katalog dua arah dengan 07 §3.4). Demo `/dev/listing-demo` (local). Vitest via vite-plus + happy-dom + RTL (disetujui Mas). Dicek di Chromium (CSP enforce): cari, sort, Back, filter, chip default, empty state, HP 375 px, gelap — 0 error konsol. 159 Pest + 22 Vitest hijau |

| 2026-11-14 | T-006 | Selesai | c476229 | Migration `export_files`, `app/Support/Export/*` (`ExportableListing`, `ExportColumns<TModel>` dengan guard model, `FormulaEscaper`, `XlsxExporter` streaming `lazy(1000)`, `ExportRunner` dipakai sinkron & job, `StartExport`, `ExportRegistry`, event `ExportCreated`/`ExportReady`), job `RunExport`, `ExportController` (Unduhan milik sendiri + signed download, bukan pemilik → 404), halaman `/unduhan`, `ExportButton`, menu Unduhan. Temuan: OpenSpout otomatis menjadikan string `=…` formula → escaping wajib sebelum OpenSpout. Angka `export.*` di `config/pakis.php` (`TODO(T-008)`). Dicek di browser: ekspor demo 16 baris = tampilan, Unduhan, unduh signed. 191 Pest + 22 Vitest |

| 2026-11-14 | T-007 | Selesai | 3b95b2f | Migration `audit_logs`/`security_events` + 4 trigger append-only (`@audit_purge`), `app/Domain/Audit` (`AuditLogger`, `SecurityLogger`, `Redactor`, enum, model dengan guard, 2 listing exportable, listener `ExportCreated` → `export.created` + `mass_export`), command `audit:purge` (dijadwal 02.30 WIB), halaman `/audit`, `/audit/{log}` (diff), `/log-keamanan`, ekspor dengan `audit.export` + konfirmasi password. Izin `audit.*` sementara deny-by-default (lokal saja). Uji mutasi trigger & reset flag. 230 Pest + 22 Vitest. **Fase 0A selesai.** |

| 2026-11-14 | T-008 (bag. 1) | Sebagian | (commit T-008a) | Migration `settings`, `app/Domain/Settings` (registri 55 kunci = `08` §2, `SettingStore` scoped + cache per grup, helper `setting()`, `Semver`, `ContrastRatio` + varian gelap, `ImageProcessor` re-encode GD, `UpdateSettingGroup` + audit per kunci, `TestMail`), halaman `/pengaturan/{group}` (12 tab, form otomatis, kembalikan default, grup security/auth minta konfirmasi password), `/api/v1/config` (peta eksplisit = kunci publik). Semua `TODO(T-008)` diganti `setting()`. 283 Pest. Dicek di browser (Chrome DevTools, profil terpisah karena Playwright dipakai agen desain) |

| 2026-11-14 | UI (desain) | Selesai | c723b0e, merge 61e5994 | Rombak tampilan di branch `feat/ui-desain`: token 07 §1.1 terang/gelap di `app.css` (kontras terukur; amber 07 = `--amber` karena `--accent` shadcn = hover; batas input ≥ 3:1; `--status-*` tetap), layout auth split (panel hijau + SVG alur tanam, pita header di HP), copy Indonesia semua auth & Pengaturan akun, `lang/id/{auth,passwords}.php` + `lang/id.json` (galat login generik), logo placeholder satu komponen, header: Unduhan + toggle tema ikon + menu profil kanan atas, sidebar drawer < 1024 px (`useIsSidebarDrawer`), dasbor kerangka tanpa data palsu, paging DataTable rapi di semua lebar (container query; gaya saja, API/test tetap). Dicek CSP enforce 360–1440 px terang/gelap, 0 error CSP. 283 Pest + 22 Vitest setelah merge |

| 2026-11-14 | T-008 (perbaikan) | Selesai | 6282f69 | Temuan review keamanan: berkas ke field non-gambar ditolak 422 (sebelumnya path gambar → `retention.audit_years` = 0 → purge menghapus seluruh audit) + `audit:purge` dijepit ke batas minimal registri; konfirmasi password memakai `security.password_confirm_minutes` (`PasswordConfirmation`, `RequirePassword` di-bind ulang) |

| 2026-11-14 | T-009 | Selesai | ff5cdbb | Migration `document_sequences` + counters, `app/Domain/Numbering` (`PatternParser` BR-025, `PeriodKey` tanggal lokal, `NumberGenerator::next/preview/lastNumber`, `UpdateSequence` + audit `sequence.updated`, `SequenceListing`), `SequenceSeeder` (employee, attendance_correction), halaman `/pengaturan/penomoran` (DataTable + dialog ubah pola: token klik-sisip, pratinjau langsung 3 nomor, galat per baris). Param `{group}` Pengaturan dibatasi ke grup registri. Izin `sequences.*` deny-by-default (`TODO(T-018)`). Suite baru `tests/Concurrency` (50 proses PHP sungguhan, tanpa RefreshDatabase): awalnya **deadlock 1213** (INSERT IGNORE → shared lock, lalu FOR UPDATE saling tunggu) → diperbaiki dengan mengunci baris induk `document_sequences` dulu (sesuai `03` §4.4). Label sr-only dialog/sheet "Close" → "Tutup". Dicek CSP enforce 360/768/1440 px terang & gelap. 334 Pest + 22 Vitest |

| 2026-11-15 | T-008 (bag. 2) | Selesai | 3bd3423 | `BrandTheme`: warna kustom → token terang/gelap (`--primary`, `--ring`, `--chart-1`, `--sidebar-primary/-ring`, `--primary-strong`, `--logo-from`, `--brand-panel-*`) di `<style nonce>` setelah `@vite` (selector `html:root`/`html.dark`), dicetak dengan `{{ }}`; warna default = **tanpa override** (token desain dipakai apa adanya). Varian gelap dinaikkan lagi bila teks gelap di atas tombol < 4.5:1 (ditemukan test: ungu `#5B2C83` = 4.48). Panel login digelapkan bertahap sampai teks redup ≥ 4.5:1. `name`/`branding` dibagikan ke Inertia (URL logo relatif `/storage/...` → lolos `img-src 'self'`), judul tab & favicon dari setting, logo unggahan menggantikan lencana daun, nama perusahaan & tagline login dari setting. Simpan grup `general`/`branding` → `Inertia::location` (muat ulang penuh agar CSS/judul ikut). 356 Pest + 25 Vitest. Dicek CSP enforce: biru `#1F4E79` + logo dummy di login 1440 gelap / 360 terang, dasbor 768 (drawer), 0 galat konsol; data dev dikembalikan default |

| 2026-11-15 | T-010 | Selesai | 6bf3fc7 | Migration `reference_items` (04 §2.6), `app/Domain/Reference` (`ReferenceGroup` + `usages()` dari 08 §3, model, `ReferenceUsage`, Actions tambah/ubah/hapus/urutkan + audit `reference.*`, `ReferenceListing`), `ReferenceSeeder` (19 item sistem, idempoten `firstOrCreate`), `ReferenceItemRequest`, halaman `/referensi` (tab grup ≥ sm / select di HP, DataTable, dialog tambah/ubah/hapus, dialog **Atur Urutan**: seret HTML5 + tombol naik/turun, tanpa dependency). Mesin listing: `Filter::required()` (kosong/tidak sah → default, chip tanpa tombol hapus, select tanpa "Semua"). Test "sudah dipakai" memakai `CREATE TEMPORARY TABLE` + `ReferenceUsage` eksplisit (DDL biasa memutus transaksi RefreshDatabase). Larastan menangkap `ids` bukan `list` → aturan `list`. 387 Pest + 26 Vitest. Dicek CSP enforce 1440/768 gelap, 360 terang; data dev dikembalikan |

| 2026-11-15 | T-011 | Selesai | d5df715 | `app/Support/Lookup` (`LookupSource` abstrak: authorize/query aktif/`TextSearch`/order/option/rules + hook `scope()` `TODO(T-019)`; `LookupRegistry` didaftarkan di `AppServiceProvider`), `LookupController` (`GET /lookup/{source}` `admin.lookup.show`, 404/403/422, `LIMIT` ≤ 20 di SQL, `ids[]` untuk label terpilih, `throttle:120,1`). Sumber `references` (grup wajib, aktif) & **`users`** (keputusan Mas: hanya `audit.view`/`users.view`, akun nonaktif ikut; 05 §4.11 + 12 diperbarui). Frontend: `AsyncSelect` (combobox ARIA tanpa dependency, tunggal/multi, debounce 250 ms), cache label `lib/lookup.ts` + `useLookupLabels` → chip "Pelaku: Demo Admin" juga dari URL yang dibagikan; isian ID di `filter-fields.tsx` diganti. Larastan: generik `LookupSource<*>` tidak bisa dipakai dengan `get()` → `Builder<covariant Model>` + guard `instanceof`. 403 Pest + 32 Vitest. Dicek CSP enforce di `/audit` 1440 gelap & 360 terang, 0 galat konsol |

| 2026-11-15 | T-012 | Selesai | 94cc31a | Migration 4 tabel wilayah (04 §2.7, kode bertitik CHAR, FK berjenjang), `app/Domain/Region` (`RegionLevel`, model, `RegionCode` sisip titik 2/4/6, `SintechRegionParser` **membaca teks, tidak mengeksekusi SQL** — tokenizer sendiri, `RegionImporter` kumpulkan per level lalu upsert provinsi→desa per 1.000 baris, baris tidak sah dilewati + dilaporkan), command `regions:import {path} {--format=sintech}`, lookup `regions` (level wajib, parent, kunci string), `RegionListing` (filter berjenjang lewat **awalan kode desa**, tanpa join), halaman `/wilayah`. Mesin listing: `RelationFilter::keyPattern/params/dependsOn` (+ spec `options_params`, `depends_on`); UI: anak nonaktif sampai induk dipilih, ganti/hapus induk mengosongkan anak (panel & chip). Fixture `tests/Fixtures/regions_kudus.sql` dari `konsep/region.sql`. Impor penuh di DB dev: 38/514/7.265/83.202 dalam 1,5 dtk, ulang = jumlah sama. 443 Pest + 36 Vitest. Dicek CSP enforce 1440 gelap & 360 terang. **Fase 0B selesai.** |

| 2026-11-15 | T-013 | Selesai | d934d7a | Migration kolom akun `users` (04 §2.2: username unik + CHECK, email nullable, is_active, locked_until, jejak login, must_change_password, soft delete; username akun lama diisi dari email). `app/Domain/Access`: `AttemptLogin` (`Fortify::authenticateUsing`; username/email huruf kecil, hash dummy bcrypt cost 12 bila akun tidak ada, 4 kasus gagal = pesan identik), `RecordLoginFailure` (penghitung identifier+IP, lockout `security.*`, event `lockout`, email `AccountLocked`), listener `RecordSuccessfulLogin` (event `Login`: jejak login, `login_success`, sidik jari perangkat UA + IP /24 → `new_device_login` + email `NewDeviceLogin`) & `RecordLogout`, `WebAccess` (BR-017), `EmployeeStatus` (fail-closed). Middleware `EnsureUserActive`, `EnforceSessionLifetime` (idle/absolut dari setting), `RequireWebMenuAccess` (`web.access`). Limiter `login` 30/menit per IP. Field form `login`. 469 Pest. Dicek CSP enforce: login `  DEMO ` → dasbor, 5× salah → terkunci + pesan "Terlalu banyak percobaan…", log keamanan lengkap |

| 2026-11-15 | T-013 (perbaikan) | Selesai | 96c561e | Temuan review keamanan: (1) penghitung gagal kini per AKUN yang ditemukan (`user:<id>`), bukan teks identifier — collation tidak membedakan huruf/aksen, sehingga `sití.aminah` dulu mendapat jatah baru; (2) IPv6 dihitung per /64 (`ClientNetwork`) di penghitung gagal & limiter 30/menit; (3) User-Agent di email perangkat baru dibersihkan dari Markdown/HTML (anti tautan palsu). Penghitung per akun lintas IP **tidak** ditambah: BR-016 menetapkan per identifier+IP, batas baru = kunci setting baru → ditanyakan ke Mas. 472 Pest |

| 2026-11-15 | T-013 (perbaikan 2) | Selesai | 395ddeb | Review lanjutan: identifier tak dikenal dilipat (huruf kecil + tanpa aksen) agar varian akun yang tidak ada berperilaku sama dengan akun yang ada (anti enumerasi); IPv4 dalam format IPv6 (`::ffff:`, `::a.b.c.d`, NAT64) dihitung per IPv4, bukan satu kelompok /64 bersama (anti DoS). Keputusan Mas: setting baru `security.account_max_failures` (20, 10–100) — batas gagal per akun lintas IP (08 §2, BR-016, registri). 475 Pest |

| 2026-11-15 | T-014 | Selesai | a623ed7 | `PasswordPolicy` tunggal (BR-011: min dari setting, maks 128, tanpa komposisi, tidak memuat username/nomor pegawai, tidak sama password lama, `NotBreached` k-anonymity Pwned Passwords fail-open + log); `ChangePassword` (flag & `password_changed_at`, `password_changed`, email `PasswordChanged`); `AuthenticateSession` di grup web → sesi lain keluar saat password diganti (driver-agnostik); `RequirePasswordChange` (`password.changed`) + `/ganti-password` (`admin.password.force*`, halaman dengan indikator aturan langsung). Form ubah password & reset Fortify memakai kebijakan; `Password::defaults` diselaraskan. `TestCase`: `Http::preventStrayRequests()`. 498 Pest. Dicek CSP enforce: `/wilayah` → `/ganti-password`, username ditolak, password sah → dasbor |

| 2026-11-15 | T-015 | Selesai | 4f37a63, c0dc0d2, (fix3) | Lupa password 3 kanal (keputusan Mas): **Email** (tautan, broker token hash sekali pakai, masa berlaku dari setting, URL dari `APP_URL`, notifikasi diantrikan), **WhatsApp** & **Telegram** (OTP: HMAC di cache, salah 5× hangus, jeda 60 dtk, panjang/umur dari setting). Respons selalu generik; pembatas 3/identifier(dilipat)/jam & 10/jaringan/jam; token/OTP diperiksa SEBELUM kebijakan password (cegah tebak password lama). `CompletePasswordReset` (BR-012). `config/otp.php` driver per kanal (`none`/`log`/`bot`), `log` di produksi → boot exception; `TelegramBot` (Bot API via Http, token tak pernah dicatat), penautan `/start <kode>` via webhook `/telegram/webhook` (secret header) atau `telegram:poll`; kolom `users.telegram_chat_id`/`telegram_linked_at`, event `telegram_linked`. Route reset sendiri (fitur reset Fortify dimatikan, `ResetUserPassword` & test lama dihapus). 529 Pest. Dicek CSP enforce: OTP Telegram (driver log) & tautan email end-to-end, tautan 2× ditolak, 360 px. Perbaikan review keamanan: konfirmasi password (Fortify `login`) → `confirmPasswordsUsing`; jatah OTP dihitung atomik (`Cache::increment`) dan penghitung tidak di-reset sampai kode baru terbit (cegah balapan); batas verifikasi OTP per identifier+jaringan (bukan per identifier saja, agar pemilik akun tidak bisa ditahan dari jaringan lain). 534 Pest |

| 2026-11-15 | T-030 | Selesai | d0dcfa1, 8c110f1 | `mobile-pro/` Flutter **3.50.0** (FVM `.fvmrc`, FVM 4.3.2 di `D:	ools`), Android saja, minSdk 26, `com.pakisjayaabadi.pakispro`, `allowBackup=false` + `data_extraction_rules`, cleartext hanya debug. `ApiClient` (header 05 §2.4, ULID), `ApiError` (semua kode 05 §2.3 → pesan Indonesia, test dua arah membaca tabel dokumen), 426 di endpoint mana pun → layar wajib update. `DeviceId` = SHA-256(ANDROID_ID + install id) lewat **kanal native** `MainActivity.kt` (keputusan Mas: tanpa `crypto`/`url_launcher`; device_info_plus 13 tidak punya ANDROID_ID). `/config` + cache offline, `Semver` cermin backend, gerbang versi di `redirect` go_router. Tema token 07 §1.1 + port `BrandTheme.php` (nilai identik, diuji dengan keluaran PHP). `PagedList`/`FilterSheet`/`EmptyState`/`StatusBadge`. Keputusan Mas: **`material_ui` ditambah** (`flutter/material.dart` deprecated sejak 3.47), drift ditunda (sqlcipher_flutter_libs EOL) — 03 §5 diperbarui. CI `mobile.yml` hijau (format, analyze, test, pub outdated, APK debug artefak). Pratinjau `lib/dev/previews.dart` (Widget Preview). 77 test |

### 6.5 Catatan untuk sesi berikutnya
- **Mobile (T-030 selesai).** Baca `mobile-pro/CLAUDE.md`. Flutter lewat `fvm flutter` (FVM di `D:	oolsvm-binvm`, belum di PATH). Developer Mode Windows mati → jangan tambah platform desktop; `kotlin.incremental=false` wajib (pub cache C: vs proyek D:). **Sebelum T-042:** putuskan pengganti `sqlcipher_flutter_libs` (EOL; kemungkinan `sqlite3` 3.x + SQLCipher) bersama Mas; camera/geolocator/image_compress/connectivity dipasang di T-042, `device_info_plus` di T-032. `applicationId` dikonfirmasi Mas sebelum rilis (T-047). Keputusan paket T-030 tercatat di `12` (Log Keputusan 15 Nov 2026). Acuan visual prototipe (peran Pegawai) + aturan bila berbeda dari 07: `mobile-pro/CLAUDE.md`. Workflow `mobile` ikut jalan saat `docs/05` berubah (test kontrak kode galat) dan `cancel-in-progress` bisa membatalkan run sebelumnya: lihat run terbaru.
- **Fase 0A selesai (T-001–T-007).**
- **T-008 selesai penuh (bag. 1 + 2).** Branding: `app/Domain/Settings/Branding/BrandTheme.php`. Warna netral (latar, kartu, `--sidebar-accent`, `--muted`) sengaja tetap palet hijau-abu desain; hanya token yang terkait warna utama yang mengikuti warna kustom. Bila Pakis ingin seluruh palet ikut, itu perubahan desain (tanyakan dulu). Pratinjau tema di `appearance-tabs.tsx` masih literal (ilustrasi tema bawaan).
- Teks panel login "Satu tempat untuk data…" dan "Kudus, Jawa Tengah" masih teks tetap (tidak ada kunci setting di `08` §2).
- Menu sidebar baru berisi **Dasbor** saja (`components/app-sidebar.tsx`). Halaman `/audit`, `/log-keamanan`, `/pengaturan/*` belum ada di menu → **dikerjakan di T-018** (keputusan Mas 14 Nov 2026): menu lengkap per grup `06` §8 + bagikan permission pengguna lewat Inertia. Jangan tambah menu sebelum itu.
- **T-015 selesai.** Telegram nyata: buat bot di @BotFather → isi `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`, `OTP_TELEGRAM_DRIVER=bot` di `.env`; server: `setWebhook` ke `https://<domain>/telegram/webhook` dengan `secret_token`; lokal: `php artisan telegram:poll`. Tombol "Hubungkan Telegram" (pakai `TelegramLink::create`) dibuat di T-017. WhatsApp tetap `none`/`log` sampai OQ-004. Email reset diantrikan → di lokal jalankan `php artisan queue:work`. DB dev: `auth.forgot_channels` = email, whatsapp, telegram (driver log); demo01 punya phone & chat_id dummy.
- **T-014 selesai.** Berikutnya T-015 (pakai `PasswordPolicy` + `ChangePassword`-style efek: reset sukses mencabut semua sesi; `ResetUserPassword` sudah memakai kebijakan, efek lengkap menyusul). Form ubah password masih di route starter kit `settings/password` (`user-password.update`) → dipindah ke `/profil/password` di T-017. Pencabutan token mobile saat ganti password: `TODO(T-031)` di `ChangePassword`. Cek password bocor menghubungi `api.pwnedpasswords.com` (hanya 5 karakter awal SHA-1); di test diblok `Http::preventStrayRequests()`.
- **T-013 selesai.** Catatan: (1) `TestCase` memberi `dashboard.view` ke semua pengguna uji (`TODO(T-018)`) — hapus saat role/permission nyata ada, lalu beri role di test yang butuh. (2) `ActiveEmployeeStatus` membaca tabel `employees` mentah (`TODO(T-024)`). (3) Route aplikasi baru wajib di grup ber-`web.access`. (4) Di test, guard menyimpan objek user antar request: pakai `app('auth')->forgetGuards()` untuk meniru request baru. (5) Klik login beruntun di browser < 1 detik bisa tertelan (tombol masih memproses) — bukan bug.
- **Fase 0B selesai (T-008–T-012).** DB dev sudah berisi wilayah lengkap (`php artisan regions:import ../konsep/region.sql`, idempoten). Staging/produksi: jalankan command yang sama setelah migrate (bukan seeder). Filter relasi berjenjang: `RelationFilter::dependsOn()` + `params()`; contoh di `RegionListing`. Sumber lookup baru: buat kelas turunan `App\Support\Lookup\LookupSource`, isi `scope()` untuk data ber-scope (T-019), daftarkan di `AppServiceProvider::register`. `RelationFilter` dengan `options_source` otomatis memakai `AsyncSelect`; sumber yang butuh parameter (mis. `references?group=`) belum bisa dipakai filter relasi — tambahkan dukungan parameter bila dibutuhkan. Saat T-021/T-024/T-040 membuat tabel pemakai referensi: tambahkan FK `RESTRICT` ke `reference_items` (cek `ReferenceUsage` otomatis aktif begitu tabelnya ada). Halaman Referensi belum di menu (T-018). Pemakai `NumberGenerator` (T-024 pegawai, T-040 koreksi) wajib memanggil `next()` di dalam transaksi yang sama dengan penyimpanan dokumen + `isTaken`. Halaman Penomoran belum di menu (T-018).
- `tests/Concurrency` menjalankan `migrate` di DB test tanpa RefreshDatabase dan membersihkan datanya sendiri; ikut jalan di `php artisan test` (± 5–10 detik).
- `DocumentSequence` dikunci `FOR UPDATE` oleh generator **dan** `UpdateSequence`: jangan menambah query lain yang mengunci counter sebelum baris induk (urutan kunci = induk → counter, agar tidak deadlock).
- Lokal: `php artisan storage:link` sudah dijalankan (logo di disk `public`). Nilai demo yang diubah saat cek browser: `attendance.default_radius_m` = 150 (bisa dikembalikan di Pengaturan → Absensi).
- **Desain UI sudah digabung** (`feat/ui-desain`, worktree `..\pakis-ui` boleh dihapus: `git worktree remove ..\pakis-ui`). Preferensi Mas: toggle tema satu ikon, profil di kanan atas, cek 360 & 768 px sebelum lapor; komponen yang lebarnya bergantung sidebar pakai container query (`@container`). Panel judul kartu bagian Pengaturan: `components/settings-section.tsx`; banner galat/sukses form: `components/form-status.tsx`.
- Purge berkas ekspor kedaluwarsa (status `expired` + hapus berkas) belum ada → T-045 (job retensi). Saat ini berkas lewat `expires_at` sudah tidak bisa diunduh (404).
- **Logo Pakis** (dari Mas, 15 Nov 2026): https://katalog.pakisjayaabadi.com/aset/img/pakis2.png (kop; logo = lingkaran hijau kiri). Sudah dipotong (512×512, luar lingkaran transparan, goresan dalam putih — di berkas asli goresan itu transparan) dan diunggah ke **DB dev** lewat Pengaturan → Branding sebagai `branding.logo_light` (tercatat audit). Unggahan ada di `storage/app/public` (tidak di git): staging/produksi perlu unggah ulang lewat layar yang sama, atau jadikan aset default di repo bila Mas setuju. Favicon belum diisi.
- Vitest belum terpasang; `vitest` termasuk kebutuhan T-005 (`10` §1). Periksa apakah ada di dependency starter kit sebelum menambah (aturan berhenti `CLAUDE.md` §5 bila belum ada di `03` §5).
- Saat menambah endpoint API: lempar `ApiError` untuk galat domain; jangan membuat JSON galat manual (lihat `backend/CLAUDE.md`).
- CI aktif di setiap push ke `main` (commit langsung ke `main`, tanpa PR — keputusan Mas 14 Nov 2026). Pantau dengan `gh run list` / `gh run watch <id> --exit-status`.
- **Diketahui rusak:** `php artisan test --parallel` (Pest 5.3.5 + paratest 7.28.0 + PHPUnit 13.4.5, `TypeError` di `WrapperRunner::getTestFiles()`). CI & lokal serial. Cek rilis Pest baru sesekali, lalu aktifkan kembali di `backend.yml`.
- Test HTTP memakai `withoutVite()` (di `tests/TestCase.php`). Jangan dihapus: job Pest di CI tidak membangun aset.
- Pre-commit terpasang di clone ini (`.git/hooks/pre-commit`). `pre-commit` (pip --user) dan `gitleaks` (`go install`, di `%GOPATH%\bin`) belum ada di PATH PowerShell. Hook tetap jalan tanpa PATH.
- Script npm: `lint` = `vp check`, `types` = `tsc --noEmit`. Belum ada `npm run test` (Vitest datang di T-005, tambahkan ke job `frontend`).
- Lingkungan lokal: PHP 8.5.12 XAMPP (tanpa ekstensi `redis`, `sodium`), MariaDB 11.8.9 `127.0.0.1:3307` root tanpa password, DB `pakis_one` + `pakis_one_test`. Node 22.21.1, Composer 2.9.5, installer `laravel` 5.33 global. Flutter/FVM belum terpasang (perlu di T-030).
- Shell PowerShell 5.1: here-string tidak bisa dipipe ke `git commit -F -`; tulis pesan ke file lalu `git commit -F <file>`.
- Dibiarkan untuk task nanti: halaman settings starter kit masih punya **hapus akun sendiri** (`profile.destroy`) → tangani di T-017 (pegawai dinonaktifkan lewat offboarding, bukan dihapus). Teks UI auth sudah Indonesia; sisa: `lang/id/validation.php` belum ada (pesan validasi bawaan masih Inggris), field lupa password masih "Email" + pesan `passwords.user` membocorkan keberadaan akun → T-014; halaman `/` (welcome) masih template Laravel dan jadi tujuan setelah Keluar → T-013.
- `config/pakis.php` `display_timezone` adalah penyangga sementara (`TODO(T-008)`), dihapus saat `setting('general.timezone')` tersedia.
