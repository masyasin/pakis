# 10 — Rencana Pengujian (Fase 0 + 0,5)

## 1. Lapisan Test

| Lapisan | Alat | Cakupan wajib |
|---|---|---|
| Unit | Pest | Actions, `ListingQuery`, `NumberGenerator`, `SettingRegistry`, `AttendanceStatusResolver`, `ScheduleResolver`, kalkulasi jarak/radius, normalisasi HP/NIK/tanggal, parser import |
| Feature (HTTP) | Pest + `RefreshDatabase` (MariaDB 11.8 di lokal & CI) | Semua route web & API: validasi, Policy, scope, audit, respons |
| Contract | Pest | `ListingContractTest` (§3), `ApiErrorFormatTest`, `PublicConfigTest` (SEC-062) |
| Arsitektur | Pest `arch()` | Controller tidak memakai `DB::` mentah. Tidak ada `dd/dump/ray`. Actions tidak bergantung pada `Request`. Model di `Domain/*/Models` |
| Otorisasi | Pest dataset | Matriks role × permission dari `06` §4 + IDOR (§4) |
| Frontend | Vitest (komponen `DataTable`, `FilterBar`) + ESLint + `tsc` | Sinkron URL, chip filter, empty state |
| E2E web | Playwright | Skenario §5 |
| Mobile | `flutter test` (unit + widget), integration test dengan mock server | Antrean offline, idempotensi, alur absen, cek versi, wajib ganti password |
| Beban | k6 | `POST /api/v1/attendance`: 150 pengguna dalam 10 menit (puncak 07.20–07.30) + 20 pengguna web membuka `/absensi` |
| Keamanan | gitleaks, `composer audit`, `npm audit`, Larastan, uji manual OWASP ASVS L2 bagian autentikasi/sesi/akses | Sebelum UAT |

Nama test yang membuktikan aturan memuat ID-nya: `it('BR-067 menolak absen offline lebih tua dari batas')`, `it('SEC-031 menolak menyetujui koreksi sendiri')`.

## 2. Data Uji

- Factory untuk semua model. `DemoSeeder` hanya untuk tampilan manual.
- Fixture absensi: shift `PAGI` 08:00–16:00 toleransi 10, shift malam `MLM` 22:00–06:00 (`crosses_midnight`), lokasi `GDG-01` di (-6.8402, 110.8551) radius 100 m, titik uji: 42 m (dalam), 180 m (luar), akurasi 30 m & 250 m.
- Waktu dibekukan dengan `Carbon::setTestNow()` / `$this->travelTo()`.
- **Tidak ada data asli Pakis** di fixture (SEC, `CLAUDE.md` §6).

## 3. Test Kontrak Listing (penjaga "paging/cari/filter jangan terlewat")

`tests/Contract/ListingContractTest.php`:

1. Kumpulkan semua route bernama `/^(admin|api\.v1)\..+\.index$/`.
2. Untuk setiap route:
   - Method controller wajib punya atribut `#[UsesListing(XxxListing::class)]`. Kalau tidak ada → **gagal** dengan pesan "Route X belum memakai standar listing (lihat 07 §3)".
   - `XxxListing::spec()` wajib punya: ≥ 1 kolom pencarian, ≥ 1 filter, ≥ 1 sort, `default_sort`, `per_page_options` tidak kosong.
   - Kunci spec wajib terdaftar di katalog `07` §3.4. Daftar kunci disalin ke `tests/Fixtures/listing_catalog.php` dan dicocokkan dua arah: kunci di katalog tanpa route juga gagal.
   - Login sebagai `super_admin` (web) atau pengguna `pegawai`/`supervisor` dengan perangkat aktif (API), lalu GET dengan `q=zz`, `per_page=<opsi terkecil>`, `sort=<sort pertama>`, `filter[<filter pertama>]=<nilai contoh valid dari spec>` → 200, dan respons memuat `pagination.per_page`, `state.q = 'zz'`, `state.sort`, `state.filters` sesuai.
   - Parameter jahat: `sort=password`, `sort=;drop`, `filter[tidak_ada]=1`, `per_page=100000`, `q` 500 karakter → 200, sort kembali default, filter diabaikan, per_page default, q terpotong 100 (SEC-032).
3. Route berparameter (mis. `admin.imports.rows.index`) mendapat parameter dari `tests/Contract/ListingRouteFixtures.php`.

Selain itu, **setiap modul** wajib punya test fitur yang membuktikan pencarian menemukan baris yang benar, minimal satu filter menyaring dengan benar, sort mengubah urutan, dan scope data membatasi hasil.

## 4. Test Otorisasi & IDOR

- `tests/Fixtures/permission_matrix.php` = salinan matriks `06` §4. Dataset role × permission → panggil satu route perwakilan per permission → harapkan 200/302 (✅/🔸) atau 403 (❌).
- **IDOR wajib** (data milik pegawai lain di luar scope → 404):
  - `admin.employees.show/update/reveal-nik/offboard`, `admin.assignments.update/end`, `admin.attendance.show`, `admin.attendance.selfie`, `admin.attendance-corrections.show/approve/reject`, `admin.devices.approve/revoke`, `admin.users.show/update/reset-password`.
  - API: `attendance-corrections.cancel` (milik orang lain), `approvals.attendance-corrections.approve/reject` (di luar scope), `notifications.read` (milik orang lain).
- Scope: `kepala_lokasi` lokasi A tidak melihat pegawai lokasi B di daftar pegawai/absensi/koreksi/perangkat. `supervisor` hanya melihat bawahan (termasuk tidak langsung, maks 5 tingkat).

## 5. Skenario E2E (Playwright, web)

| ID | Skenario |
|---|---|
| E2E-01 | Login Owner + MFA → dasbor → logout |
| E2E-02 | Lupa password via email (mail fake) → reset → login → sesi lama mati |
| E2E-03 | Admin reset password pegawai → pegawai login → dipaksa ganti password |
| E2E-04 | Ubah setting `attendance.default_radius_m` → tersimpan, tercatat audit, tampil di riwayat |
| E2E-05 | Buat role "Kepala Toko Khusus" → centang permission → tetapkan ke pengguna → menu berubah |
| E2E-06 | Tambah pegawai + penugasan + akun → nomor pegawai sesuai pola |
| E2E-07 | Daftar pegawai: cari, filter lokasi + status kerja, sort, ganti per halaman, reload → state URL tetap |
| E2E-08 | Import 30 baris (5 galat) → perbaiki pemetaan → proses → 25 dibuat. Import ulang berkas sama → 0 dibuat, 25 dilewati/diperbarui |
| E2E-09 | Mutasi pegawai → riwayat penugasan 2 baris, atasan baru tampil |
| E2E-10 | Nonaktifkan pegawai → akunnya langsung tidak bisa login (web) |
| E2E-11 | Tetapkan pola shift massal 10 pegawai → kalender menampilkan shift |
| E2E-12 | Absensi harian: filter telat + lokasi → ekspor → berkas berisi baris yang sama |
| E2E-13 | Koreksi: kepala lokasi menyetujui → status hari berubah, `is_corrected` |

## 6. Skenario Mobile (integration test + uji lapangan)

| ID | Skenario | Harapan |
|---|---|---|
| MOB-01 | Login pertama di HP baru | Perangkat aktif (auto-approve) |
| MOB-02 | Login akun yang sama di HP kedua | Layar "menunggu persetujuan". Admin setujui → HP lama keluar |
| MOB-03 | Absen masuk di dalam radius | 201, status Hadir/Telat sesuai jam |
| MOB-04 | Absen di luar radius (mode `location`) | Pesan jarak & nama lokasi |
| MOB-05 | Aplikasi fake GPS aktif | Ditolak, HRD menerima notifikasi |
| MOB-06 | Mode pesawat → absen → aktifkan data | Tersimpan lokal → terkirim otomatis ≤ 1 menit, `is_offline` |
| MOB-07 | Kirim ulang antrean yang sama 3× (simulasi jaringan putus setelah server menerima) | Hanya 1 log di server (`replayed`) |
| MOB-08 | Versi app < minimum | Layar wajib update |
| MOB-09 | Ajukan koreksi → atasan setujui dari Pakis Pro | Status berubah di HP pengaju |
| MOB-10 | HP Android 8 RAM 2–3 GB (uji lapangan di Undaan) | Absen online ≤ 10 detik dari ketuk sampai hasil, pada sinyal 3G/4G lemah |

## 7. Skenario UAT (diisi bersama Pakis)

| ID | Modul | Skenario | Langkah | Data Uji | Hasil Diharapkan | Hasil Aktual | Status | Paraf Penguji |
|---|---|---|---|---|---|---|---|---|
| UAT-01 | Akun | Login web Owner + MFA | Buka web → login → masukkan kode authenticator | Akun Owner | Masuk dasbor | | | |
| UAT-02 | Akun | Lupa password via email | Klik Lupa password → buka email → reset | Akun HRD | Bisa login dengan password baru | | | |
| UAT-03 | Akun | Ubah password di HP | Profil → Ubah password | Akun pegawai | Berhasil, sesi web lain keluar | | | |
| UAT-04 | Pengaturan | Ubah logo & warna | Pengaturan → Branding | Logo Pakis | Logo tampil di login web & HP | | | |
| UAT-05 | Pengaturan | Ubah radius & toleransi absen | Pengaturan → Absensi | 150 m | Berlaku untuk absen berikutnya | | | |
| UAT-06 | Penomoran | Ubah pola nomor pegawai | Penomoran → ubah → pratinjau | `PJA-{YY}{SEQ:4}` | Pegawai baru bernomor sesuai | | | |
| UAT-07 | Role | Buat role & matriks | Role → Tambah → centang | — | Pengguna melihat menu sesuai | | | |
| UAT-08 | Pegawai | Impor daftar pegawai asli Pakis | Impor → pemetaan → proses | Excel Pakis | Semua pegawai masuk, galat jelas per baris | | | |
| UAT-09 | Pegawai | Cari & filter daftar pegawai | Ketik nama, filter lokasi | — | Hasil benar, paging jalan | | | |
| UAT-10 | Pegawai | Mutasi pegawai | Detail → Mutasi | Staf gudang → toko | Riwayat 2 penugasan | | | |
| UAT-11 | Pegawai | Nonaktifkan pegawai | Detail → Nonaktifkan | Pegawai uji | Tidak bisa login web & HP | | | |
| UAT-12 | Jadwal | Tetapkan shift massal | Jadwal → pilih → tetapkan | 1 lokasi | Kalender terisi | | | |
| UAT-13 | Absen | Absen masuk & pulang di gudang | Pakis Pro | Pegawai gudang | Tercatat, status sesuai | | | |
| UAT-14 | Absen | Absen di luar radius | Pakis Pro dari luar | — | Ditolak dengan jarak | | | |
| UAT-15 | Absen | Absen tanpa sinyal | Mode pesawat → absen → online | — | Terkirim otomatis, bertanda offline | | | |
| UAT-16 | Perangkat | Ganti HP | Login di HP baru → admin setujui | — | HP baru aktif, HP lama keluar | | | |
| UAT-17 | Koreksi | Ajukan & setujui koreksi | HP pegawai → HP atasan | Lupa absen pulang | Status hari terkoreksi | | | |
| UAT-18 | Rekap | Rekap bulanan & ekspor | Absensi → Rekap → Ekspor | Bulan uji | Excel sesuai tampilan | | | |
| UAT-19 | Audit | Lihat riwayat perubahan | Audit → filter pegawai | — | Perubahan tercatat lengkap | | | |
| UAT-20 | Keamanan | Akun terkunci setelah salah password | Salah 5× | Akun uji | Terkunci 15 menit, email terkirim | | | |

## 8. Exit Criteria Pengujian

- Semua test otomatis hijau di CI (PHP, JS, Flutter). Cakupan Actions & `Support/Listing` ≥ 80%.
- 0 temuan High/Critical terbuka dari pengujian keamanan.
- UAT-01 s.d. UAT-20 lulus dan diparaf Pakis.
- Uji beban k6: p95 `POST /api/v1/attendance` < 2 s, 0% galat 5xx.
