# CLAUDE.md — Instruksi Kerja AI Coding Agent untuk PAKIS ONE

Berlaku untuk Claude Code dan agent lain (`AGENTS.md` merujuk file ini). Subfolder `backend/CLAUDE.md` dan `mobile-pro/CLAUDE.md` menambah aturan khusus, tidak mengganti aturan ini.

## 1. Proyek

PAKIS ONE adalah sistem internal **CV Pakis Jaya Abadi** (distributor pestisida & alat pertanian, Kudus), dikembangkan PT Sintech Berkah Abadi. Paket saat ini: **Fase 0** (core organisasi & pegawai, akun, role/permission dinamis, site setting, autentikasi lengkap, audit) + **Fase 0,5** (absensi lewat aplikasi Flutter **Pakis Pro**). **Single-tenant**, tanpa `tenant_id` (ADR-001).

**Selalu baca sebelum bekerja:** `Implementasi_Plan.md` §6 (status) → task di `TASKS.md` → dokumen di "Konteks wajib" task itu.

| Butuh tahu tentang | Sumber kebenaran |
|---|---|
| Tabel, kolom, enum, index, seed | `docs/04-DATABASE.md` |
| Route web, API, kode galat, format listing | `docs/05-API-CONTRACT.md` |
| Role, permission, scope, menu, transisi | `docs/06-AUTHORIZATION.md` |
| Kolom/cari/filter/sort tiap daftar, tampilan, copy | `docs/07-UI-SPEC.md` (katalog listing di §3.4) |
| Aturan bisnis (BR) & **registri setting** | `docs/08-BUSINESS-RULES.md` |
| Kontrol keamanan (SEC) | `docs/09-SECURITY.md` |
| Test yang diharapkan | `docs/10-TEST-PLAN.md` |
| Stack, versi, struktur, ADR | `docs/03-ARCHITECTURE.md` |
| Keputusan yang belum final | `docs/12-OPEN-QUESTIONS.md` |
| Kontrak lintas modul Paket A–G (ADR-101+, event akuntansi, penomoran, role, state machine) | `docs/14-ROADMAP-LENGKAP.md` |
| Paket A — Master & Gudang | `docs/15-PAKET-A-MASTER-GUDANG.md` + `TASKS_PAKET_A.md` |
| Paket B — Penjualan & Uang Aman | `docs/16-PAKET-B-PENJUALAN-UANG.md` + `TASKS_PAKET_B.md` |
| Paket C — Lapangan & Mitra | `docs/17-PAKET-C-LAPANGAN-MITRA.md` + `TASKS_PAKET_C.md` |
| Paket D — Armada & BBM | `docs/18-PAKET-D-ARMADA-BBM.md` + `TASKS_PAKET_D.md` |
| Paket E — SDM Lanjut & Payroll (pelengkap; HR inti tetap `13`) | `docs/19-PAKET-E-SDM-PAYROLL.md` + `TASKS_PAKET_E.md` |
| Paket F — Keuangan & Prinsipal | `docs/20-PAKET-F-KEUANGAN-PRINSIPAL.md` + `TASKS_PAKET_F.md` |
| Paket G — Cerdas & Kinerja | `docs/21-PAKET-G-CERDAS-KINERJA.md` + `TASKS_PAKET_G.md` |

`docs/reference/` (konsep v1.4) hanya bacaan latar. **Jangan membangun fitur dari sana tanpa task.**

## 2. Stack & Perintah

Laravel 13 · PHP 8.5 · Inertia + React + TypeScript · Tailwind + shadcn/ui (starter kit resmi) · MariaDB 11.8 · Redis · Spatie Permission ^8 · Sanctum · OpenSpout · Pest · Larastan · Playwright. Mobile: Flutter stable (FVM). Versi terkunci: `docs/03-ARCHITECTURE.md` §5.

```bash
# root repo — sekali per clone (T-002): hook gitleaks + Pint sebelum commit
pip install --user pre-commit==4.7.0 && pre-commit install
pre-commit run --all-files

# backend/
composer install && npm ci
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed              # dev + data demo (DITOLAK di production)
composer run dev                              # server + vite + queue
php artisan test --parallel --processes=4     # paralel (Pest 5.3.6+); tiap proses DB <DB_DATABASE>_test_N
php artisan test tests/Contract               # test kontrak listing & format API
./vendor/bin/pint --test && ./vendor/bin/phpstan analyse
npm run lint && npm run types && npm run test
composer audit && npm audit --audit-level=high
npx playwright test

# mobile-pro/
fvm flutter pub get
fvm flutter analyze && fvm flutter test
fvm flutter build apk --debug
```

## 3. Struktur & Konvensi

- Kode domain di `backend/app/Domain/{Access,Settings,Numbering,Reference,Region,Organization,Employee,Device,Attendance,Notification,Audit}`. Tiap domain punya `Models/`, `Actions/`, `Policies/`, `Enums/`, `Events/`.
- **Controller tipis:** Form Request → `authorize` (Policy) → Action (`handle()`, transaksi DB) → Inertia/Resource. Logika bisnis tidak boleh ada di controller, observer, atau komponen React.
- **Setiap daftar (`*.index`) WAJIB:**
  1. kelas `app/Domain/<Domain>/Listings/*Listing.php` (listing lintas modul seperti ekspor di `app/Support/Export/`) yang mengimplementasikan `HasListing`,
  2. atribut `#[UsesListing(...)]` di method controller,
  3. halaman memakai `DataTable` (web) atau `PagedList` + `FilterSheet` (mobile),
  4. kunci listing terdaftar di `docs/07-UI-SPEC.md` §3.4 **dan** `tests/Fixtures/listing_catalog.php`,
  5. test fitur untuk pencarian, minimal satu filter, sort, dan scope.

  Tidak ada daftar tanpa paging, pencarian, filter, dan sort. `ListingContractTest` akan merah.
- **Semua parameter dibaca dari `setting('grup.kunci')`.** Dilarang menulis angka bisnis (radius, toleransi, batas, timeout) sebagai konstanta. Kunci baru = tambah ke `SettingRegistry` + `08` §2 + test registri.
- **Nomor dokumen hanya dari `NumberGenerator`** di dalam transaksi.
- Opsi pilihan yang bisa berubah memakai `reference_items` (grup di `ReferenceGroup`), bukan enum hardcode. Enum PHP hanya untuk status sistem (nilai identik dengan ENUM DB).
- Waktu disimpan UTC. Tampilan memakai `general.timezone`. `work_date` adalah tanggal lokal.
- Route web: nama `admin.<modul>.<aksi>`, path Bahasa Indonesia. API: `/api/v1`, nama `api.v1.<modul>.<aksi>`. Sesuai `05`.
- Halaman React: `resources/js/pages/<Modul>/<Halaman>.tsx`. Komponen bersama: `resources/js/components/pakis/`.
- Teks UI Bahasa Indonesia sesuai `07` §6–§7. Komentar kode menjelaskan **mengapa**.
- Commit: `feat(employee): offboarding atomik [T-026]`, selalu dengan ID task.

## 4. Alur Kerja per Task

1. Baca task + konteks wajib. Pastikan dependensi berstatus selesai di `Implementasi_Plan.md` §6 dan urutan §3.
2. Tulis rencana singkat: berkas yang disentuh dan test yang akan dibuat. Gunakan plan mode.
3. Tulis test lebih dulu untuk BR/SEC yang disebut task (nama test memuat ID-nya).
4. Implementasi.
5. Jalankan test, Pint, Larastan, ESLint, tsc (dan `flutter analyze/test` untuk mobile, Playwright untuk task UI besar).
6. Bila task membuat daftar, centang checklist `07` §3.3 di deskripsi PR.
7. Perbarui `Implementasi_Plan.md` §6 (ringkasan, log, catatan sesi berikutnya).
8. Commit dengan ID task.

## 5. Aturan Berhenti (WAJIB)

Berhenti dan tanyakan ke manusia, **jangan menebak**, bila:
- Spesifikasi antar-dokumen bertentangan atau tidak cukup untuk task ini.
- Perlu menambah dependency (composer/npm/pub) di luar `03` §5.
- Perlu mengubah skema DB, route/API, permission, kunci setting, atau transisi status yang tidak disebut task. Perubahan dicatat dulu di dokumen sumber kebenaran + `12-OPEN-QUESTIONS.md`.
- Task terblokir OQ (kolom "Task terblokir" di `12`).
- Test keamanan/otorisasi/kontrak listing gagal dan perbaikannya menuntut melonggarkan kontrol.
- Diminta mengerjakan task dari `TASKS_ROADMAP.md`. Itu outline, **bukan** task siap eksekusi; hanya task di `TASKS.md` / `TASKS_PAKET_*.md` yang boleh dikerjakan.
- Akan memulai sebuah paket sebelum gerbangnya terbuka: Paket A setelah exit gate Fase 0 + 0,5 dan checklist `15` §0; Paket B setelah exit gate A dan `16` §0; Paket C setelah exit gate B (termasuk pilot) dan `17` §0; Paket D setelah exit gate C dan `18` §0; Paket E bagian E1 bersama Fase HR setelah A, bagian E2 setelah D (`12` PE-1); Paket F dan G sesuai kepala `TASKS_PAKET_F.md`/`TASKS_PAKET_G.md` dan §0 dokumennya. Temuan penyelarasan yang masih terbuka ada di `12` bagian "Penyelarasan Paket A–B" (PA-n), "C–D" (PC-n, PD-n), "E" (PE-n), "F" (PF-n), dan "G" (PG-n).

Catat pertanyaan di `Implementasi_Plan.md` §6.3.

## 6. Aturan Keamanan Agent

- **Dilarang** menonaktifkan atau melewati validasi, Policy, DataScope, CSRF, rate limit, MFA, trigger audit, `ListingContractTest`, atau test lain untuk "membuat kode berjalan".
- **Dilarang** `dangerouslySetInnerHTML`, `{!! !!}` untuk data pengguna, raw SQL dengan interpolasi string, `orderBy`/`where` dari input mentah, image picker galeri untuk selfie.
- Resource di luar scope → 404. Setiap route baru yang menyentuh data pegawai wajib masuk test IDOR.
- Jangan pernah menulis secret, password, token, NIK, `two_factor_secret`, atau koordinat presisi ke kode, log, audit, fixture, atau komentar.
- Hanya data dummy. **Tidak ada data pegawai asli Pakis** di repo, staging, test, atau prompt. Seeder demo ditolak di production.
- Setiap aksi yang mengubah data penting memanggil `AuditLogger` (event `09` §7.1). Event keamanan memanggil `SecurityLogger`.

## 7. Definisi Selesai (Definition of Done)

- [ ] Semua kriteria penerimaan task tercentang
- [ ] Test baru + seluruh test lama hijau (termasuk `tests/Contract`)
- [ ] Pint, Larastan, ESLint, tsc / `flutter analyze` bersih
- [ ] Tidak ada TODO di logika inti tanpa ID task/OQ
- [ ] `Implementasi_Plan.md` §6 diperbarui

## 8. Aturan Sesi

Sebelum konteks hampir penuh atau sesi berakhir: perbarui `Implementasi_Plan.md` §6.2 (sedang dikerjakan), §6.4 (log), dan §6.5 (berkas terakhir disentuh, langkah berikutnya, test yang masih merah).
