# 09 — Keamanan & Perlindungan Data (Fase 0 + 0,5)

## 1. Aset yang Dilindungi

| Aset | Sensitivitas | Alasan |
|---|---|---|
| Akun & hak akses (terutama Owner, Administrator, HRD) | Tinggi | Pintu ke semua data, dan kelak ke data uang (Fase 1) |
| NIK, tanggal lahir, alamat, HP pegawai | Tinggi | Data pribadi (UU 27/2022 tentang PDP) |
| Foto selfie absensi + koordinat GPS | Tinggi | Wajah + jejak lokasi. Foto wajah yang dipakai untuk identifikasi berpotensi tergolong data pribadi spesifik. Perlakukan dengan standar tertinggi, dan konfirmasi klasifikasinya dengan penasihat hukum Pakis (OQ-009) |
| Rekap absensi | Sedang | Dasar gaji & sanksi (Fase 3) |
| Audit log & log keamanan | Tinggi | Bukti saat terjadi sengketa atau penggelapan |
| Setting keamanan & role | Tinggi | Bisa melemahkan semua kontrol |

## 2. Threat Model (STRIDE ringkas)

| ID | Ancaman | STRIDE | Aktor | Mitigasi (SEC) |
|---|---|---|---|---|
| T-01 | Titip absen: pegawai A absen untuk B memakai HP/akun B | Spoofing | Pegawai | Satu akun satu HP, satu HP satu akun (SEC-020), selfie kamera langsung (SEC-022), audit foto oleh atasan |
| T-02 | Lokasi palsu (aplikasi fake GPS) | Spoofing/Tampering | Pegawai | Deteksi `isMocked`, tolak + catat + notifikasi (SEC-023), radius + akurasi |
| T-03 | Ubah jam HP agar tidak telat | Tampering | Pegawai | Waktu server untuk online, batas & penanda untuk offline (SEC-024) |
| T-04 | Credential stuffing / tebak password akun admin | Spoofing | Penyerang eksternal | Rate limit + lockout (SEC-003), MFA admin (SEC-007), cek password bocor (SEC-002) |
| T-05 | Penyalahgunaan orang dalam (admin lihat/ekspor data pegawai, ubah role diam-diam) | Info disclosure / Elevation | Admin nakal | Pemisahan administrator vs HRD (`06` §4), scope data, masking NIK, audit append-only (SEC-040), alert ekspor massal (SEC-051), konfirmasi password untuk aksi sensitif |
| T-06 | Mantan pegawai masih bisa masuk | Elevation | Mantan pegawai | Offboarding mencabut sesi, token, dan perangkat seketika (SEC-021) |
| T-07 | IDOR: ganti ID di URL/API untuk lihat data orang lain | Info disclosure | Pengguna login | Policy + DataScope + 404 (SEC-033), test IDOR wajib |
| T-08 | Unggahan berbahaya (web shell, SVG ber-skrip, Excel formula) | Tampering/Elevation | Pengguna login | Validasi MIME isi, storage privat, larangan eksekusi, tolak SVG, tidak mengevaluasi formula (SEC-050, SEC-052) |
| T-09 | Defacement / sisipan judi online di halaman login | Tampering | Penyerang eksternal | Hardening server, CSP, integritas berkas (SEC-070) |
| T-10 | Bocornya backup / database | Info disclosure | Penyerang / vendor | Backup terenkripsi, NIK terenkripsi kolom, kunci terpisah (SEC-011, SEC-064) |
| T-11 | Kebocoran secret di repo/log | Info disclosure | Siapa pun | gitleaks, secret hanya di env, log tanpa data pribadi (SEC-060, SEC-061) |
| T-12 | Penyalahgunaan akun Super Admin (vendor) | Elevation | Vendor | Akun bisa dinonaktifkan Owner, semua aksi teraudit, tidak bisa ubah Owner (SEC-009) |

## 3. Kontrol Keamanan (SEC-xxx)

### 3.1 Autentikasi & Sesi

| ID | Kontrol | Verifikasi |
|---|---|---|
| SEC-001 | Pesan & kode galat login identik untuk user tidak ada / password salah / akun nonaktif / pegawai nonaktif. Waktu respons disamakan (hash dummy bila user tidak ada). | Test fitur 4 kasus → respons identik |
| SEC-002 | Password di-hash bcrypt (cost default framework) atau argon2id. Kebijakan BR-011, termasuk cek password bocor. Password tidak pernah masuk log/audit. | Test kebijakan, grep log |
| SEC-003 | Rate limit & lockout BR-016. Respons 423/429 tidak membocorkan keberadaan akun. | Test throttling |
| SEC-004 | Lupa password: respons generik, token sekali pakai berumur pendek (disimpan hash), tautan hanya ke `APP_URL`. Reset sukses mencabut semua sesi. | Test token dipakai 2× → gagal |
| SEC-005 | Cookie sesi `HttpOnly`, `Secure`, `SameSite=Lax`. Regenerasi ID saat login. Idle & absolute timeout (BR-018). Tanpa "ingat saya". Daftar & pencabutan sesi. Driver sesi `redis` (produksi) atau `database`. | Test header cookie, test timeout |
| SEC-006 | Reset admin (BR-014): password sementara ditampilkan sekali, tidak dikirim lewat email/WA, `must_change_password` dipaksa middleware di web & API. | Test akses route lain → redirect/403 |
| SEC-007 | MFA TOTP wajib untuk role `mfa_required` (BR-015). Secret & kode pemulihan terenkripsi. Rate limit verifikasi OTP. | Test akses tanpa MFA |
| SEC-008 | Tidak ada akun default. Super Admin dibuat dari env dengan password ≥ 16 karakter dan wajib ganti saat login pertama. Seeder demo ditolak di `production`. | Test seeder di env production |
| SEC-009 | Super Admin tidak bisa mengubah Owner. Owner bisa menonaktifkan Super Admin. Semua aksi Super Admin ditandai di audit (`actor_label = 'vendor'`). | Test policy |

### 3.2 Data Pribadi

| ID | Kontrol | Verifikasi |
|---|---|---|
| SEC-010 | NIK: `nik_enc` (Laravel `Crypt`) + `nik_hash` (HMAC-SHA256 dengan `NIK_HASH_KEY` terpisah dari `APP_KEY`). Tidak pernah plaintext di DB, log, audit, ekspor, `import_rows`, atau respons, kecuali endpoint `reveal-nik` (`employees.view_sensitive`) yang tercatat `sensitive_data_viewed`. | Test: dump tabel tidak memuat 16 digit; audit `old/new_values` NIK = `***` |
| SEC-011 | Kunci enkripsi (`APP_KEY`, `NIK_HASH_KEY`) disimpan terpisah dari backup DB. Prosedur rotasi `APP_KEY` memakai `APP_PREVIOUS_KEYS`. | Checklist rilis |
| SEC-012 | Minimisasi: tidak mengumpulkan agama, status nikah, golongan darah, atau data lain yang tidak dipakai fitur Fase 0–0,5. | Review skema |
| SEC-013 | Selfie disimpan di disk privat, nama acak, diakses hanya lewat signed URL 5 menit dengan `attendance.view_photo` dalam scope. Dihapus otomatis setelah `attendance.selfie_retention_days` (job harian). | Test akses tanpa izin → 404, test purge |
| SEC-014 | Hak subjek data: pegawai bisa melihat data dirinya (Profil). Koreksi data lewat HRD. Ekspor data pribadi satu pegawai atas permintaan dilakukan HRD dari halaman detail (tercatat). Penghapusan mengikuti retensi dan kewajiban hukum ketenagakerjaan (OQ-009). | Prosedur tertulis di manual admin |

### 3.3 Perangkat & Absensi

| ID | Kontrol | Verifikasi |
|---|---|---|
| SEC-020 | Token Sanctum terikat perangkat (BR-040), dengan `abilities` = permission mobile. Token disimpan di HP via `flutter_secure_storage` (Android Keystore). Tidak ada token di log. | Test token + device lain → 401 |
| SEC-021 | Offboarding/nonaktif akun mencabut sesi, token, dan perangkat dalam satu transaksi (BR-053). | Test: setelah offboard, request API & web → 401/redirect |
| SEC-022 | Selfie hanya dari kamera (paket `camera`, tanpa image picker). Server memvalidasi JPEG asli (MIME isi), ≤ 300 KB, dan membuang EXIF. | Test unggah PNG/berkas palsu → 422 |
| SEC-023 | Lokasi palsu ditolak & dicatat (BR-065). Akurasi buruk ditolak (BR-064). Aplikasi menolak jalan di emulator pada build produksi (best effort). Deteksi root dicatat sebagai anomali (OQ-010). | Test API `is_mock=true` |
| SEC-024 | Waktu server untuk online, batas offline & penanda skew (BR-067). `client_uuid` unik mencegah duplikasi saat replay. | Test replay, test offline lewat batas |

### 3.4 Otorisasi & Integritas

| ID | Kontrol | Verifikasi |
|---|---|---|
| SEC-030 | Nomor dokumen hanya dari server (BR-025, ADR-009). Field `number`/`employee_number` tidak diterima dari request (tidak ada di `$fillable` input). | Test mass assignment |
| SEC-031 | Maker-checker koreksi: tidak bisa menyetujui milik sendiri (`06` §6). Tugas luar kota (langsung berlaku): tidak bisa membuat/mengubah/membatalkan tugas yang memuat diri sendiri (`06` §7.2a). | Test `CORRECTION_SELF_APPROVAL`; `FieldTripTest` SEC-031 |
| SEC-032 | Listing hanya memproses filter/sort di allowlist spec. Tidak ada `orderBy`/`where` dari input mentah. Query builder/Eloquent saja, tanpa interpolasi string SQL. | Test kontrak listing dengan parameter jahat (`sort=password`, `filter[role]=1 OR 1=1`) |
| SEC-033 | Semua resource ber-scope di-resolve lewat DataScope. Di luar scope → 404. **Test IDOR wajib** untuk setiap route `show/update/destroy/approve` yang menyentuh data pegawai. | Matriks test otorisasi (`10` §4) |
| SEC-034 | Mass assignment: Form Request + `$request->validated()` + DTO/Action. Kolom `is_system`, `status`, `must_change_password`, `employee_number`, `nik_hash`, `created_by` tidak bisa diisi dari request. | Test |
| SEC-035 | CSRF/forgery protection aktif (`PreventRequestForgery` Laravel 13) untuk semua route web yang mengubah state. API memakai token Bearer, tanpa cookie. | Test |

### 3.5 Audit & Log

| ID | Kontrol | Verifikasi |
|---|---|---|
| SEC-040 | `audit_logs` append-only (trigger DB). Dicatat untuk setiap create/update/delete pada data penting (§7.1) dengan nilai lama/baru. Field sensitif (`password`, `two_factor_*`, `nik_*`, token) diganti `***`. Retensi `retention.audit_years`. | Test UPDATE langsung → gagal; test redaksi |
| SEC-041 | `security_events` untuk semua event di `04` §2.11. Alert: ≥ `monitoring.login_burst_count` (default 20) `login_failed` dari satu IP dalam `monitoring.login_burst_minutes` (default 10) menit — diredam `monitoring.alert_mute_minutes` per IP —, `lockout` akun berperan admin (Super Admin, Owner, Administrator), `attendance_mock_detected`, `mass_export` di luar jam kerja `monitoring.work_hour_start`–`monitoring.work_hour_end` (default 06.00–21.00, jam lokal) → email `monitoring.alert_emails`. Ambang dari setting (keputusan Mas 16 Nov 2026, `12`). | Test job alert |
| SEC-042 | Log aplikasi JSON berisi `request_id`, `user_id`, route, durasi. **Tanpa** password, token, NIK, koordinat presisi, isi selfie. Log channel produksi `daily` retensi 30 hari. | Grep test log |

### 3.6 Unggahan & Ekspor

| ID | Kontrol | Verifikasi |
|---|---|---|
| SEC-050 | Unggahan (import, lampiran koreksi, foto pegawai, avatar): allowlist ekstensi + validasi MIME isi, batas ukuran, nama acak, disk privat (kecuali branding di disk publik), dan Nginx menolak eksekusi `.php` di `storage/`. Excel dibaca dengan OpenSpout (nilai saja, tanpa makro/formula). | Test unggah `.php` berganti nama → 422 |
| SEC-051 | Ekspor: escaping formula (BR-005), NIK termasking, dicatat di audit (`export.created`) + `mass_export` bila besar. Berkas ekspor di disk privat, unduh via signed URL milik pembuat, kedaluwarsa `retention.export_days`. | Test berkas ekspor berisi `=HYPERLINK(...)` → teks |
| SEC-052 | SVG dan HTML ditolak di semua unggahan gambar. | Test |

### 3.7 Konfigurasi, Secret, Infrastruktur

| ID | Kontrol | Verifikasi |
|---|---|---|
| SEC-060 | Secret hanya di `.env`/secret CI: `APP_KEY`, `NIK_HASH_KEY`, `DB_*`, `REDIS_PASSWORD`, `MAIL_*`, `OTP_PROVIDER_*`, `SUPERADMIN_*`, keystore Android. Tidak pernah di `settings`, repo, log, atau dokumen. gitleaks di pre-commit & CI. Kredensial berbeda per lingkungan. | CI gitleaks hijau |
| SEC-061 | Produksi: `APP_DEBUG=false`, `APP_ENV=production`, halaman galat generik (web) dan format §2.2 (API) tanpa stack trace. Telescope/Debugbar tidak terpasang di produksi (`require-dev`). | Checklist rilis + test |
| SEC-062 | `GET /api/v1/config` hanya mengeluarkan kunci `is_public` (test membandingkan dengan registri). | Test |
| SEC-063 | Security headers: `Content-Security-Policy` (mulai report-only di staging, enforce di produksi, nonce untuk script; style memakai `'unsafe-inline'` karena library menyisipkan `<style>` saat runtime tanpa nonce, keputusan T-003), `Strict-Transport-Security: max-age=31536000; includeSubDomains`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), geolocation=(self), microphone=()` (kamera & mikrofon tidak dibutuhkan web admin; geolocation hanya origin sendiri untuk tombol "Ambil lokasi HP" titik kios, T-073 `15` §7.2). Header `X-Powered-By`/versi server disembunyikan. | Test middleware + cek securityheaders |
| SEC-064 | Backup harian terenkripsi, salinan di luar server, uji restore bulanan (NFR-006). Kunci backup tidak disimpan di server yang sama. | Log uji restore |
| SEC-065 | Server: SSH key-only, root login mati, UFW, fail2ban, unattended-upgrades. MariaDB & Redis hanya localhost. User DB aplikasi tanpa `DROP`/`GRANT`/`TRIGGER`, dan user migrasi terpisah. PHP-FPM & worker berjalan sebagai user non-root. | Checklist hardening |
| SEC-066 | Dependency: `composer audit`, `npm audit --audit-level=high`, `flutter pub outdated` di CI. High/Critical memblokir rilis kecuali ada justifikasi tertulis di `12-OPEN-QUESTIONS.md`. | CI |
| SEC-067 | APK ditandatangani keystore rilis (disimpan sebagai secret CI, cadangan offline di tempat aman milik Owner). `android:allowBackup=false`. Cleartext traffic dimatikan. Obfuscation (`--obfuscate --split-debug-info`) aktif. | Checklist rilis mobile |
| SEC-068 | Rate limit API umum: 120 request/menit per token. Unggah absen: 10/menit per pengguna. | Test |

### 3.8 Deteksi Kompromi

| ID | Kontrol | Verifikasi |
|---|---|---|
| SEC-070 | Integritas berkas: cron harian membandingkan hash `public/` dan `app/` terhadap manifest rilis. Berkas `.php` baru di `public/` atau `storage/` memicu alert. Prosedur cek cepat sisipan judi online: grep kata kunci & tautan asing di `public/` dan `resources/views`. | Uji dengan berkas dummy di staging |

## 4. Kepatuhan

- **UU 27/2022 (PDP):** dasar pemrosesan data pegawai = hubungan kerja (perjanjian kerja) dan kewajiban hukum ketenagakerjaan. Pegawai diberi pemberitahuan tertulis tentang data yang dikumpulkan Pakis Pro (selfie, lokasi saat absen saja, info perangkat) beserta tujuan dan masa simpannya, sebelum aplikasi dipakai. **Lokasi hanya diambil saat menekan tombol absen, tidak dilacak di latar belakang** (pelacakan armada adalah fitur Fase 2 dengan pemberitahuan terpisah). Ketentuan detail dan pasal-pasalnya diverifikasi oleh penasihat hukum Pakis (OQ-009).
- **Kebocoran data:** ikuti §6 dan kewajiban pemberitahuan menurut UU PDP. Batas waktu dan pihak yang wajib diberi tahu diverifikasi saat menyusun SOP.
- **Lokasi data:** server di data center Indonesia (ADR / `03` §8). Tidak ada data pribadi yang dikirim ke layanan AI/LLM di fase ini.

## 5. Keamanan Saat Bekerja dengan AI Coding Agent

- Agent hanya memakai data dummy dan kredensial development. Data pegawai asli Pakis **tidak pernah** masuk repo, staging, atau prompt.
- Agent dilarang menonaktifkan validasi, Policy, DataScope, CSRF, rate limit, MFA, trigger audit, atau test untuk membuat kode berjalan. Bila terhalang → berhenti dan lapor (`CLAUDE.md` §5).
- Kode hasil agent melewati: test (termasuk test otorisasi & kontrak listing), Larastan, gitleaks, `composer audit`, serta review manusia untuk berkas di `app/Domain/Access`, `Support/Listing`, middleware, dan migration.

## 6. Playbook Insiden (ringkas)

1. **Deteksi:** alert SEC-041/SEC-070, laporan pengguna.
2. **Isolasi:** aktifkan mode pemeliharaan, cabut sesi & token yang dicurigai (`php artisan security:revoke --user=` / `--all`), ganti password DB/Redis bila perlu.
3. **Penilaian dampak:** audit log, security events, log Nginx. Tentukan data apa yang terdampak.
4. **Pemulihan:** dari backup bersih terakhir sebelum kompromi. Deploy ulang dari repo (bukan dari server).
5. **Investigasi & perbaikan:** akar masalah, patch, rotasi secret (`APP_KEY` via `APP_PREVIOUS_KEYS`, `NIK_HASH_KEY` butuh re-hash terencana).
6. **Pelaporan:** ke Owner Pakis dalam 24 jam. Bila menyangkut data pribadi, ikuti kewajiban UU PDP (OQ-009).

Kontak darurat & pembagian tanggung jawab Sintech ↔ Pakis: diisi di manual admin (OQ-002).

## 7. Event Audit & Checklist Rilis

### 7.1 Event audit minimum
`setting.updated`, `sequence.updated`, `reference.*`, `user.created|updated|deactivated|activated|password_reset|mfa_reset`, `role.created|updated|deleted`, `location.*`, `department.*`, `position.*`, `employee.created|updated|deleted|offboarded|rehired|nik_viewed`, `assignment.created|updated|ended`, `import.processed`, `export.created`, `device.approved|rejected|revoked`, `shift.*`, `schedule.pattern_set|override_*`, `holiday.*`, `field_trip.created|updated|cancelled|requested|approved|rejected`, `attendance.recorded` (ringkas: tanpa foto/koordinat presisi), `attendance_correction.submitted|approved|rejected|cancelled`, `approval_rule.created|updated|deactivated|deleted`, `approval_delegation.created|revoked`, `approval_request.requested|approved|rejected|cancelled|escalated|auto_approved` (T-067, SEC-110; `approved`/`rejected` per keputusan langkah dengan `decided_step` & `acting_for_user_id`), `stock_location.created|updated` (T-068; termasuk lokasi stok kendaraan yang disinkronkan kendaraan & nonaktif lewat `is_active`), `stock_bin.created|updated` (T-068; `Q-01` otomatis ikut tercatat), `vehicle.created|updated` (T-068), `sales_area.created|updated` (T-073; termasuk daftar kecamatan), `customer.created|updated|reassigned` (T-073; `reassigned` per kios dengan area/sales lama-baru, tanggal efektif, alasan; HP pemilik di audit ikut tersimpan karena data kios, bukan data pribadi pegawai), `customer.otp_sent` (T-074; tujuan + HP **termasking**, kode tidak pernah dicatat), `customer.verification_submitted|office_call_recorded|activated|verification_withdrawn` (T-074; `office_call_recorded` dengan `called_by`), `customer.duplicate_override` (kode kios kandidat + alasan), `customer.phone_change_requested` (HP termasking + alasan) dan `customer.phone_changed` (nilai lama/baru `owner_phone` utuh, SEC-110/TA-04), `customer.credit_limit_requested|credit_limit_changed` (limit lama/baru), `customer.merge_requested|merged` (kios tujuan, jumlah kontak & verifikasi yang dipindah), `customer.approval_withdrawn` (permintaan limit/gabung ditarik saat kios digabung/dinonaktifkan), `customer.status_changed` (status lama/baru, aksi, alasan), `customer.contact_added|contact_updated|contact_removed` (T-074).

### 7.2 Checklist keamanan sebelum rilis
- [ ] `APP_DEBUG=false`, galat generik, Telescope/Debugbar tidak ada di produksi
- [ ] HTTPS + HSTS, security headers (SEC-063) terpasang, CSP enforce (`CSP_MODE=enforce` di `.env` produksi; cek konsol browser halaman login & dasbor tanpa pelanggaran CSP)
- [ ] `expose_php = Off` di php.ini produksi dan header `Server` Nginx tanpa versi (`server_tokens off`)
- [ ] `.env`, `.git`, `vendor`, `storage` tidak bisa diakses dari web. `storage/` tidak mengeksekusi PHP
- [ ] `ListingContractTest` + matriks test otorisasi/IDOR hijau
- [ ] MFA aktif untuk semua Owner/Administrator/HRD. Akun Super Admin sudah ganti password. Tidak ada akun demo
- [ ] `composer audit`, `npm audit`, gitleaks bersih (atau ada justifikasi tertulis)
- [ ] Rate limit login, lupa password, OTP, API, unggah absen aktif
- [ ] Backup terenkripsi berjalan + uji restore berhasil (tercatat)
- [ ] Alert SEC-041 terkirim ke `monitoring.alert_emails` (uji)
- [ ] APK rilis ditandatangani, `allowBackup=false`, obfuscation aktif, versi minimum di setting sudah benar
- [ ] Pemberitahuan pemrosesan data sudah dibagikan ke pegawai (OQ-009)
- [ ] Akses vendor (Super Admin, SSH) terdokumentasi dan bisa dicabut Owner
