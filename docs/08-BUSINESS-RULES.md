# 08 — Aturan Bisnis (Fase 0 + 0,5)

Setiap aturan punya ID BR-xxx. Test yang membuktikan aturan memuat ID itu di namanya, mis. `it('BR-064 menolak absen di luar radius')`.

## 1. Standar Daftar (berlaku untuk SEMUA CRUD)

| ID | Aturan |
|---|---|
| BR-001 | Setiap route `*.index` (web & API) memakai kelas `*Listing` yang mengimplementasikan `HasListing`, dan controller-nya memanggil `ListingQuery::apply()`. Paging server-side selalu aktif. Opsi per halaman web `[10, 25, 50, 100]`, default `ui.default_per_page`. Opsi mobile `[20, 50]`. Nilai `per_page` di luar opsi → memakai default. Batas keras `ui.max_per_page`. |
| BR-002 | **Pencarian `q`**: di-trim, spasi ganda dirapikan, maksimal 100 karakter, kurang dari 2 karakter diabaikan, case-insensitive. Karakter `%`, `_`, dan `\` di-escape. Kolom ber-index memakai pencarian awalan (`kata%`) per kata. Tabel < 50.000 baris boleh memakai `%kata%`. Pencarian di relasi (mis. nama pegawai di absensi) memakai `whereHas`/join yang sudah dioptimasi. Kata ganda = AND antar kata. |
| BR-003 | **Filter**: hanya key yang ada di spec yang diproses. Nilai divalidasi sesuai tipe (`select`/`multi_select` harus ada di opsi atau ID valid dalam scope; `date_range` format `YYYY-MM-DD`, `from ≤ to`, rentang maks 366 hari; `number_range` numerik). Nilai tidak valid tidak dipakai dan dilaporkan di `listing.state.invalid_filters` (UI menampilkan peringatan kecil). Filter default (mis. status pegawai = aktif) tampil sebagai chip yang bisa dihapus. |
| BR-004 | **Sort**: hanya key di spec, satu kolom, arah `asc`/`desc`. Tie-breaker `id desc` selalu ditambahkan supaya paging stabil. Sort kolom relasi memakai join atau subquery yang sudah ditentukan di spec, bukan input mentah. |
| BR-005 | **Ekspor** memakai spec, scope, pencarian, filter, dan sort yang sama dengan tampilan. Jumlah baris ≤ `export.sync_max_rows` → unduh langsung. Bila lebih → antrean + notifikasi. Di atas `export.max_rows` ditolak dengan arahan mempersempit filter. Ekspor > `export.mass_export_alert_rows` baris → `security_events.mass_export`. Nilai sel diawali `=`, `+`, `-`, `@`, tab, atau CR diberi awalan `'` (SEC-051). Kolom NIK di ekspor selalu termasking. |
| BR-006 | **Lookup** (`admin.lookup.*`): wajib parameter pencarian, hasil maks 20, hanya entri aktif, dan menerapkan scope data. Tidak ada endpoint yang mengembalikan seluruh isi tabel tanpa batas. |

## 2. Registri Site Setting (sumber kebenaran)

Semua kunci di bawah bisa diubah dari layar **Pengaturan** oleh pemegang `settings.update`. Kolom **Publik** = ikut dikirim di `GET /api/v1/config`. Secret (SMTP, token WA, kunci) **tidak** ada di sini, karena tetap di `.env`.

**Tipe & penanda Paket A (PA-19, T-067).**
- `decimal`: angka desimal disimpan & dibaca sebagai **string** (mis. `"0.8"`), tanpa float; maks 15 digit bulat & 6 digit pecahan. Batas min–max dibandingkan sebagai string.
- `money`: rupiah `DECIMAL(18,2)` sebagai string berskala 2 (default `50000000` dibaca `"50000000.00"`). Form menampilkan format id-ID (`Rp 50.000.000`).
- `int_list`: daftar bilangan bulat unik (beda dari `multi enum` yang berisi string). Batas min–max = jumlah butir; aturan per butir (rentang, urutan) memakai validasi khusus kunci.
- Penanda **`locked`**: hanya `super_admin` yang bisa mengubah (selain itu `403`; field tampil terkunci), wajib konfirmasi password, perubahan tercatat audit `setting.updated`. Berbeda dari grup `security`/`auth` yang hanya butuh konfirmasi password.
- Penanda **`one_way`** (khusus `bool`): hanya boleh `true → false`; `false → true` (termasuk lewat "Kembalikan default") ditolak `422` pada field itu.
- Penanda dicatat di kolom Validasi kunci yang memakainya (mis. `inventory.opening_balance_open`, T-086).

### 2.1 Umum & Branding

| Kunci | Tipe | Default | Validasi | Publik |
|---|---|---|---|---|
| `general.company_name` | string | `CV Pakis Jaya Abadi` | 3–150 | ✅ |
| `general.app_name` | string | `PAKIS ONE` | 2–40 | ✅ |
| `general.address` | text | kosong | ≤ 500 | ❌ |
| `general.phone` | string | kosong | format HP/telepon Indonesia | ❌ |
| `general.email` | email | kosong | email valid | ❌ |
| `general.website` | url | kosong | https URL | ❌ |
| `general.timezone` | enum | `Asia/Jakarta` | `Asia/Jakarta`, `Asia/Makassar`, `Asia/Jayapura` | ✅ |
| `branding.logo_light` | image | logo bawaan | PNG/JPG/WebP ≤ 1 MB, maks 1024 px | ✅ |
| `branding.logo_dark` | image | = logo terang | sama | ✅ |
| `branding.favicon` | image | bawaan | PNG persegi 32–512 px | ✅ |
| `branding.primary_color` | color | `#2F6B3B` | hex 6 digit, kontras ≥ 4.5:1 dengan putih (BR-021) | ✅ |
| `branding.login_tagline` | string | `Distribusi pestisida & alat pertanian` | ≤ 120 | ✅ |

### 2.2 Keamanan & Autentikasi

| Kunci | Tipe | Default | Validasi | Publik |
|---|---|---|---|---|
| `security.password_min_length` | int | 10 | 8–64 | ✅ |
| `security.password_check_breached` | bool | true | — (butuh internet ke layanan cek password bocor) | ❌ |
| `security.login_max_attempts` | int | 5 | 3–20 | ❌ |
| `security.lockout_minutes` | int | 15 | 1–1440 | ❌ |
| `security.account_max_failures` | int | 20 | 10–100 | ❌ |
| `security.session_idle_minutes` | int | 120 | 15–720 | ❌ |
| `security.session_absolute_hours` | int | 12 | 1–72 | ❌ |
| `security.password_confirm_minutes` | int | 15 | 5–120 | ❌ |
| `security.reset_token_minutes` | int | 60 | 15–1440 | ❌ |
| `security.notify_new_device_login` | bool | true | — | ❌ |
| `auth.forgot_channels` | multi enum | `["email"]` | subset `email`, `whatsapp`, `telegram`, minimal 1. `whatsapp`/`telegram` hanya bisa dipilih bila driver OTP kanal itu tersedia (bukan `none`, dan bukan `log` di produksi) | ✅ |
| `auth.otp_length` | int | 6 | 6–8 | ❌ |
| `auth.otp_minutes` | int | 10 | 3–30 | ❌ |

### 2.3 Mobile

| Kunci | Tipe | Default | Validasi | Publik |
|---|---|---|---|---|
| `mobile.min_version` | string | `1.0.0` | semver | ✅ |
| `mobile.latest_version` | string | `1.0.0` | semver ≥ min | ✅ |
| `mobile.download_url` | url | kosong | https URL | ✅ |
| `mobile.update_message` | string | `Versi baru Pakis Pro tersedia.` | ≤ 200 | ✅ |
| `mobile.auto_approve_first_device` | bool | true | — | ❌ |
| `mobile.token_days` | int | 90 | 7–365 | ❌ |

### 2.4 Presensi

| Kunci | Tipe | Default | Validasi | Publik |
|---|---|---|---|---|
| `attendance.require_selfie` | bool | true | — | ✅ |
| `attendance.default_radius_m` | int | 100 | 20–5000 | ❌ |
| `attendance.max_accuracy_m` | int | 100 | 10–500 | ✅ |
| `attendance.offline_max_hours` | int | 72 | 1–168 | ✅ |
| `attendance.max_clock_skew_minutes` | int | 10 | 1–120 | ❌ |
| `attendance.allow_unscheduled` | bool | false | — | ❌ |
| `attendance.allow_checkout_without_checkin` | bool | false | — | ❌ |
| `attendance.min_minutes_between_in_out` | int | 5 | 0–240 | ❌ |
| `attendance.early_leave_tolerance_min` | int | 0 | 0–120 | ❌ |
| `attendance.daily_close_time` | time | `23:30` | HH:mm | ❌ |
| `attendance.correction_max_days` | int | 7 | 1–60 | ✅ |
| `attendance.correction_attachment_max_kb` | int | 2048 | 256–5120 | ✅ |
| `attendance.selfie_retention_days` | int | 365 | 30–1825 | ❌ |
| `attendance.field_trip_max_days` | int | 7 | 1–31 | ❌ |

### 2.5 Tampilan, Ekspor, Impor, Notifikasi, Monitoring, Retensi

| Kunci | Tipe | Default | Validasi | Publik |
|---|---|---|---|---|
| `ui.default_per_page` | enum | 25 | 10, 25, 50, 100 | ❌ |
| `ui.max_per_page` | int | 100 | 25–100 | ❌ |
| `ui.dashboard_refresh_seconds` | int | 60 | 0–600; 0 = muat ulang otomatis dasbor mati, selain 0 minimal 15 | ❌ |
| `ui.dashboard_max_range_days` | int | 92 | 7–366; rentang grafik dasbor yang lebih panjang dipotong (tanggal "sampai" dipertahankan) | ❌ |
| `export.sync_max_rows` | int | 2000 | 100–10000 | ❌ |
| `export.max_rows` | int | 100000 | 1000–500000 | ❌ |
| `export.mass_export_alert_rows` | int | 1000 | 100–100000 | ❌ |
| `import.max_file_mb` | int | 5 | 1–20 | ❌ |
| `import.max_rows` | int | 2000 | 50–10000 | ❌ |
| `notification.mail_from_name` | string | `PAKIS ONE` | ≤ 60 | ❌ |
| `notification.channels` | multi enum | `["email"]` | subset `email`, `telegram`, `whatsapp`, boleh kosong (notifikasi aplikasi selalu aktif). `telegram`/`whatsapp` hanya bisa dipilih bila driver kanal itu tersedia | ❌ |
| `notification.on_export_ready` | multi enum | `[]` | subset `email`, `telegram`, `whatsapp` | ❌ |
| `notification.on_import_done` | multi enum | `[]` | subset `email`, `telegram`, `whatsapp` | ❌ |
| `notification.on_password_changed` | multi enum | `["email"]` | subset `email`, `telegram`, `whatsapp` | ❌ |
| `notification.on_new_device_login` | multi enum | `["email"]` | subset `email`, `telegram`, `whatsapp` | ❌ |
| `notification.on_account_locked` | multi enum | `["email"]` | subset `email`, `telegram`, `whatsapp` | ❌ |
| `notification.on_device_pending` | multi enum | `[]` | subset `email`, `telegram`, `whatsapp` | ❌ |
| `notification.on_attendance_mock` | multi enum | `[]` | subset `email`, `telegram`, `whatsapp` | ❌ |
| `notification.on_correction_submitted` | multi enum | `[]` | subset `email`, `telegram`, `whatsapp` | ❌ |
| `notification.on_correction_decided` | multi enum | `["email"]` | subset `email`, `telegram`, `whatsapp` | ❌ |
| `notification.on_field_trip_submitted` | multi enum | `[]` | subset `email`, `telegram`, `whatsapp` | ❌ |
| `notification.on_field_trip_decided` | multi enum | `["email"]` | subset `email`, `telegram`, `whatsapp` | ❌ |
| `notification.on_approval_requested` | multi enum | `[]` | subset `email`, `telegram`, `whatsapp` (T-067, `15` §9.1) | ❌ |
| `notification.on_approval_decided` | multi enum | `[]` | subset `email`, `telegram`, `whatsapp` (T-067) | ❌ |
| `notification.on_approval_escalated` | multi enum | `[]` | subset `email`, `telegram`, `whatsapp` (T-067) | ❌ |
| `notification.whatsapp_daily_limit` | int | 300 | 0–10000 | ❌ |
| `notification.whatsapp_hourly_per_user` | int | 5 | 1–50 | ❌ |
| `notification.circuit_failures` | int | 5 | 3–20 | ❌ |
| `notification.circuit_minutes` | int | 15 | 5–120 | ❌ |
| `monitoring.alert_emails` | email_list | `[]` | maks 5 email | ❌ |
| `monitoring.login_burst_count` | int | 20 | 5–1000 | ❌ |
| `monitoring.login_burst_minutes` | int | 10 | 1–120 | ❌ |
| `monitoring.alert_mute_minutes` | int | 60 | 5–1440 | ❌ |
| `monitoring.work_hour_start` | int | 6 | 0–23 | ❌ |
| `monitoring.work_hour_end` | int | 21 | 1–24 | ❌ |
| `retention.audit_years` | int | 5 | 1–10 | ❌ |
| `retention.security_days` | int | 365 | 90–1825 | ❌ |
| `retention.import_days` | int | 90 | 7–365 | ❌ |
| `retention.export_days` | int | 7 | 1–30 | ❌ |

### 2.6 Persetujuan

Grup `approval` (tab "Persetujuan"), dipakai mesin approval generik (BR-153, `15` §9).

| Kunci | Tipe | Default | Validasi | Publik |
|---|---|---|---|---|
| `approval.auto_approve_when_no_rule` | bool | false | — (`false` = dokumen tanpa aturan cocok ditolak `422 APPROVAL_RULE_MISSING`) | ❌ |
| `approval.default_escalation_hours` | int | 24 | 1–168 | ❌ |
| `approval.reauth_amount` | money | `50000000` | ≥ 0 | ❌ |

### 2.7 Produk

Grup `product` (tab "Produk"), dipakai master produk (T-070, `15` §9).

| Kunci | Tipe | Default | Validasi | Publik |
|---|---|---|---|---|
| `product.sku_mode` | enum | `manual` | `manual`, `auto` | ❌ |

Catatan: mode otomatis memakai `NumberGenerator` pola `product`; mode manual menerima huruf besar, angka, dan strip (3–30 karakter, unik).

### 2.8 Unggahan berkas

Grup `upload` (tab "Unggahan berkas"), dipakai komponen unggahan bersama `app/Support/Upload/` untuk semua unggahan Paket A (SEC-109, T-070).

| Kunci | Tipe | Default | Validasi | Publik |
|---|---|---|---|---|
| `upload.max_image_kb` | int | 5120 | 512–10240 (foto JPG/PNG/WebP) | ❌ |
| `upload.max_pdf_kb` | int | 10240 | 512–20480 (dokumen PDF) | ❌ |

## 3. Grup Referensi

| `group_code` | Dipakai di | Item sistem (kode dipakai logika) |
|---|---|---|
| `location_type` | `locations.location_type_id` | `office`, `warehouse`, `store`, `other` |
| `employment_status` | `employees.employment_status_id` | `permanent`, `contract`, `probation`, `daily` |
| `correction_reason` | `attendance_corrections.reason_id` | `forgot`, `gps_error`, `field_duty`, `system_error`, `phone_broken`, `phone_lost`, `phone_left`, `other` |
| `offboarding_reason` | `employees.exit_reason_id` | `resign`, `contract_end`, `terminated`, `retired`, `deceased`, `other` |

## 4. Autentikasi & Akun

| ID | Aturan |
|---|---|
| BR-010 | Input `login` di-trim dan diubah ke huruf kecil, lalu dicocokkan ke `username` **atau** `email`. Login sah bila password cocok, `users.is_active = true`, `deleted_at` kosong, dan (tidak punya pegawai **atau** pegawainya `active`). Semua kegagalan mengembalikan pesan yang sama (SEC-001). |
| BR-011 | **Kebijakan password**: panjang minimal `security.password_min_length`, maksimal 128. Tidak ada aturan komposisi wajib. Tidak boleh sama dengan password saat ini, tidak boleh memuat username atau nomor pegawai. Bila `security.password_check_breached` aktif → ditolak bila tercatat bocor. Kalau layanan pengecekan tidak bisa dihubungi, pengecekan dilewati dan dicatat (fail-open terdokumentasi, karena ketersediaan login lebih penting). |
| BR-012 | **Lupa password via email**: hanya untuk akun yang punya email **terverifikasi** (email yang baru diganti dan belum diverifikasi tidak dikirimi tautan; respons tetap generik). Token sekali pakai, berlaku `security.reset_token_minutes`. Setelah reset: `must_change_password = false`, `password_changed_at` diisi, semua sesi web & token API dicabut, `security_events.password_reset_completed`, email pemberitahuan ke pemilik akun. Pengecualian: tautan **atur password** akun baru yang dibuat admin (T-020) dikirim ke email yang diisi admin walau belum terverifikasi; email ditandai terverifikasi saat tautan itu berhasil dipakai (keputusan Mas 15 Nov 2026). |
| BR-013 | **Lupa password via OTP WhatsApp atau Telegram**: kanal harus ada di `auth.forgot_channels` dan akun harus punya `phone` (WhatsApp) atau `telegram_chat_id` (Telegram, ditautkan pengguna lewat bot dari Profil dengan kode sekali pakai 10 menit). OTP numerik `auth.otp_length` digit, berlaku `auth.otp_minutes`, disimpan sebagai hash di cache, maksimal 5 kali salah per OTP, jeda kirim ulang 60 detik. Respons permintaan selalu generik. Setelah reset: sama dengan BR-012. |
| BR-014 | **Reset password oleh admin**: password sementara 12 karakter acak (tanpa karakter ambigu `0 O 1 l I`) ditampilkan **sekali** ke admin, `must_change_password = true`, semua sesi & token pengguna dicabut, `security_events.password_reset_by_admin`. Admin tidak bisa mereset dirinya sendiri lewat menu ini. Hanya Owner yang bisa mereset Owner lain, dan Super Admin tidak bisa mereset Owner. Pelaku hanya bisa mereset akun yang seluruh hak aksesnya (permission & scope) juga ia miliki (T-020). Password sementara dikembalikan sebagai respons JSON `no-store` dan tidak pernah masuk sesi/flash/log/audit; admin mengonfirmasi dengan password-nya sendiri di request yang sama. Panjang = maks(12, `security.password_min_length`). |
| BR-015 | **MFA**: pengguna yang salah satu rolenya `mfa_required` dan belum mengaktifkan MFA → setelah login web langsung diarahkan ke halaman aktivasi MFA, menu lain terkunci. Di API login mendapat `403 MFA_SETUP_REQUIRED`. Kode pemulihan sekali pakai, dan **tampil sekali** tepat setelah dibuat (aktivasi / buat ulang); sesudahnya hanya jumlah sisa kode yang terlihat. MFA tidak bisa dinonaktifkan pemiliknya selama role-nya mewajibkan. Reset MFA oleh admin → `two_factor_*` dikosongkan, sesi dicabut. |
| BR-016 | **Pembatasan percobaan**: per (identifier + IP) maksimal `security.login_max_attempts` gagal dalam `security.lockout_minutes`, **dan** per akun dari IP mana pun maksimal `security.account_max_failures` gagal dalam rentang yang sama → akun `locked_until` = sekarang + `security.lockout_minutes`, `security_events.lockout`, email ke pemilik bila ada. "Identifier" = akun yang ditemukan (varian huruf/aksen dihitung sama); IP = alamat IPv4 (termasuk IPv4 di dalam format IPv6) atau blok /64 untuk IPv6. Per IP maksimal 30 percobaan login/menit. Lupa password: 3 per identifier per jam dan 10 per IP per jam. OTP MFA salah 5 kali → sesi tantangan dibatalkan. |
| BR-017 | Login **web** ditolak dengan pesan "Akun Anda hanya untuk aplikasi Pakis Pro." bila pengguna tidak punya satu pun permission yang membuka menu web (`06` §8). |
| BR-018 | **Sesi**: idle timeout `security.session_idle_minutes`, absolute timeout `security.session_absolute_hours`. Login berhasil → regenerasi session ID. Login dari perangkat/browser baru (sidik jari UA + IP /24 belum pernah dalam 90 hari) → `security_events.new_device_login` + email bila `security.notify_new_device_login`. Token mobile berlaku `mobile.token_days` sejak dibuat. |

### 4.1 Role & Scope

| ID | Aturan |
|---|---|
| BR-030 | Permission hanya dibuat oleh `PermissionSeeder` dari `06` §3. UI hanya bisa menetapkan permission yang ada ke role. Kode role regex `^[a-z0-9_]{3,40}$`, unik. Nama role 3–60 karakter. |
| BR-031 | Role `is_system` tidak bisa dihapus atau diganti kodenya. Permission role `owner` dan `super_admin` terkunci penuh. Role yang masih dipegang pengguna tidak bisa dihapus. Pengguna tidak bisa mengubah role miliknya sendiri: role yang sedang ia pegang tampil baca-saja (permission, scope, MFA tidak bisa ia ubah) dan ia tidak bisa mengubah daftar role akunnya sendiri (T-020) — mencegah menaikkan hak sendiri (keputusan Mas 15 Nov 2026). **Batas delegasi:** pengguna hanya bisa MENAMBAHKAN ke role permission yang ia miliki sendiri, dan tidak bisa memberi `data_scope` lebih luas dari scope terluas role-nya (Super Admin & Owner memegang semuanya). Permission yang sudah ada di role boleh dipertahankan atau dilepas. **Penetapan role ke akun (T-020)** memakai batas yang sama: role yang ditambahkan/dilepas harus seluruh permission-nya dimiliki pelaku dan scope-nya tidak lebih luas; role `owner`/`super_admin` hanya oleh Owner. Setiap perubahan role/permission/penetapan role mencatat `security_events` dan audit, lalu membersihkan cache permission. |
| BR-032 | Scope data efektif pengguna = scope terluas dari semua role-nya (`all` > `location` > `team` > `self`), dihitung sesuai definisi `06` §5 dan diterapkan di semua listing, lookup, Policy, dan route-model binding resource ber-scope. |

## 5. Setting, Penomoran, Referensi

| ID | Aturan |
|---|---|
| BR-020 | Hanya kunci yang terdaftar di registri yang bisa ditulis. Kunci tak dikenal → 422. Nilai divalidasi sesuai tipe & aturan di §2. Penyimpanan per grup bersifat atomik. |
| BR-021 | `branding.primary_color` harus punya rasio kontras ≥ 4.5:1 terhadap `#FFFFFF`. Varian gelap dibuat otomatis (lightness dinaikkan) dan dicek ≥ 4.5:1 terhadap `#121814`. Gambar diunggah hanya PNG/JPG/WebP (**SVG ditolak**, SEC-052), metadata dibuang, disimpan dengan nama acak. |
| BR-022 | Nilai setting di-cache. Setiap penyimpanan menghapus cache grup itu. Pembacaan selalu mengembalikan tipe yang benar (int, bool, array). |
| BR-023 | Setiap perubahan setting dicatat di audit (`setting.updated`) dengan nilai lama & baru per kunci. |
| BR-024 | "Kembalikan default" = menghapus baris `settings` kunci itu, sehingga nilai kembali ke default registri. |
| BR-025 | **Pola penomoran**: token yang dikenal `{PREFIX}` (tidak dipakai, teks literal ditulis langsung), `{YYYY}`, `{YY}`, `{MM}`, `{DD}`, `{LOC}` (kode lokasi), `{SEQ:n}` (n = 3–8, wajib tepat satu). Karakter literal hanya `A–Z 0–9 - / . _`. Hasil maksimal 30 karakter. Konsistensi periode: reset `yearly` wajib memuat `{YY}`/`{YYYY}`, `monthly` wajib tahun + `{MM}`, `daily` wajib tahun + `{MM}` + `{DD}`. `scope_key` = periode (`2026`, `2026-11`, `2026-11-03`, atau `-`) + `@{LOC}` bila ada. Counter dinaikkan dengan `SELECT … FOR UPDATE` di transaksi pemanggil. Bila nomor hasil ternyata sudah dipakai (setelah pola diubah), generator menaikkan counter lagi, maksimal 50 kali, lalu gagal dengan galat konfigurasi. Nomor yang sudah terbit tidak pernah berubah. |
| BR-026 | **Referensi**: `code` regex `^[a-z0-9_]{2,50}$`, unik per grup. Item `is_system`: kode tidak bisa diubah dan item tidak bisa dihapus, tetapi nama bisa diubah dan item bisa dinonaktifkan. Item yang sudah dipakai data mana pun tidak bisa dihapus, hanya dinonaktifkan. Item nonaktif tidak muncul di pilihan baru tetapi tetap tampil di data lama. |
| BR-027 | **Versi mobile**: perbandingan semver. `X-App-Version` < `mobile.min_version` → semua endpoint API kecuali `GET /config` mengembalikan `426 APP_UPDATE_REQUIRED`. |

## 6. Organisasi, Pegawai, Perangkat

| ID | Aturan |
|---|---|
| BR-040 | **Perangkat**: satu pengguna maksimal satu perangkat `active`, dan satu HP (`device_uid`) hanya untuk satu pengguna aktif (dijaga unique index). Login dari HP baru: bila pengguna belum punya perangkat aktif dan `mobile.auto_approve_first_device` → `active`, selain itu `pending` (tanpa token). Persetujuan → perangkat lama `replaced` + token lama dicabut. Setiap request API: `X-Device-Id` harus sama dengan perangkat pemilik token dan berstatus `active`. Kalau tidak → token dicabut + `401 DEVICE_MISMATCH`. `last_seen_at` diperbarui maksimal sekali per 5 menit. |
| BR-041 | **Lokasi**: lokasi tanpa koordinat tidak bisa dipakai validasi radius. Halaman lokasi & dasbor menandainya "Belum ada koordinat". Absen pegawai dengan mode `location` di lokasi tanpa koordinat ditolak dengan `ATTENDANCE_OUT_OF_RADIUS` dan pesan "Koordinat lokasi belum diatur. Hubungi HRD." Lokasi tidak bisa dihapus bila pernah dipakai penugasan, hanya dinonaktifkan. Lokasi nonaktif tidak bisa dipilih di penugasan baru. |
| BR-042 | **Mode absen efektif** = `assignments.attendance_mode` ?? `positions.attendance_mode`. |
| BR-050 | **Pegawai**: `employee_number` dibuat `NumberGenerator('employee')` saat simpan dan tidak bisa diubah. NIK opsional, tepat 16 digit angka, disimpan `nik_enc` + `nik_hash` (unik). HP dinormalisasi ke format `62…` (menerima `08…`, `+62…`, `62…`, spasi/tanda hubung dibuang), 10–15 digit. Email huruf kecil. Umur pada `join_date` 15–80 tahun bila `birth_date` diisi. Nama di-trim dan spasi ganda dirapikan. |
| BR-051 | **Penugasan**: maksimal satu penugasan **utama** aktif per pegawai (dijaga index). Penugasan tambahan (`is_primary = false`) boleh lebih dari satu dan hanya memengaruhi scope `location`, bukan snapshot absensi. Atasan harus pegawai aktif, bukan diri sendiri, dan tidak membentuk siklus (dicek sampai 10 tingkat). Jabatan dan lokasi harus aktif. Penugasan tambahan berlaku **dua arah** untuk scope `location` (keputusan Mas 15 Nov 2026): pegawai yang merangkap di lokasi X terlihat oleh scope X, dan pemegang scope `location` yang merangkap di X ikut melihat pegawai X. Satu pegawai tidak boleh punya dua penugasan aktif di lokasi yang sama. Penugasan **utama** tidak bisa diakhiri (pindah lewat Mutasi BR-052, keluar lewat Nonaktifkan BR-053); "Akhiri" hanya untuk penugasan tambahan, tanggal selesai ≥ mulai dan ≤ 31 hari ke belakang. Penugasan tambahan hanya untuk pegawai aktif, mulai ≥ tanggal masuk dan ≤ 31 hari ke belakang (sama dengan mutasi). Ubah/akhiri penugasan mensyaratkan pegawai **dan** lokasi penugasan itu dalam scope pelaku. Setiap perubahan penugasan memancarkan event `AssignmentChanged` (setelah commit) untuk resolver absensi. |
| BR-052 | **Mutasi**: penugasan utama baru dengan `start_date` > `start_date` penugasan lama. Penugasan lama otomatis `end_date = start_date baru − 1 hari`, dalam satu transaksi. `start_date` tidak boleh lebih dari 31 hari ke belakang. Snapshot `attendance_days` pada tanggal ≥ `start_date` yang terdampak dihitung ulang oleh resolver. |
| BR-053 | **Offboarding**: `exit_date` ≥ `join_date` dan ≤ hari ini. Dalam satu transaksi: penugasan aktif ditutup (`end_date = exit_date`), `employees.status = inactive`, akun nonaktif, semua sesi web dihapus, semua token dicabut, perangkat `revoked`, koreksi `pending` milik pegawai → `cancelled`, `locations.head_employee_id` yang menunjuk pegawai ini dikosongkan. Sebelum konfirmasi, UI menampilkan bawahan yang masih menunjuk pegawai ini sebagai atasan supaya HRD bisa memindahkannya. Penugasan terjadwal yang mulai setelah `exit_date` dihapus (belum pernah berlaku, tercatat di audit); HP Pakis Pro yang masih `pending` → `rejected`. Konfirmasi: ketik nama pegawai + password pelaku. **Aktifkan kembali** (keputusan Mas 15 Nov 2026): tanggal aktif kembali > `exit_date` dan ≤ hari ini (≤ 31 hari ke belakang) menjadi `join_date` baru (masa kerja dihitung ulang; nilai lama di audit `employee.rehired`), `exit_*` dikosongkan, penugasan utama baru wajib, akun ikut aktif bila dicentang dan pelaku berwenang atas akun itu (`users.deactivate` + batas UserPolicy). Tanggal keluar juga tidak boleh sebelum penugasan yang sudah mulai atau akhir penugasan yang sudah selesai (supaya penugasan yang pernah berlaku tidak terhapus sebagai "terjadwal"). Menonaktifkan pegawai yang punya akun tunduk pada batas UserPolicy (`users.manage-account`: bukan diri sendiri, Owner hanya oleh Owner, tidak lebih berkuasa dari pelaku). |
| BR-054 | **Import pegawai**: berkas `.xlsx`/`.csv` ≤ `import.max_file_mb`, ≤ `import.max_rows` baris data. Header = di 10 baris teratas, baris yang judul kolomnya paling banyak dikenali sinonim (minimal 2); bila tidak ada, baris tidak kosong pertama (keputusan Mas 15 Nov 2026: judul laporan di atas tabel tidak boleh terbaca sebagai header). Saran pemetaan dari profil yang `header_signature`-nya sama, lalu dari sinonim (mis. "Nama", "Nama Lengkap", "NAMA PEGAWAI" → `full_name`; "No HP", "WA", "Telepon" → `phone`). Field wajib: `full_name`, `gender`, `join_date`, `employment_status` (kode/nama), `location` (kode/nama), `position` (kode/nama). Opsional: `employee_number`, `nik`, `birth_date`, `phone`, `email`, `address`, `supervisor_number`. Tanggal diterima: `dd/mm/yyyy`, `dd-mm-yyyy`, `yyyy-mm-dd`, `3 November 2026`, `3 Nov 2026`, serial Excel. Jenis kelamin: `L/P`, `Laki-laki/Perempuan`, `Pria/Wanita`. **Kunci baris** = `nik_hash` bila NIK ada, lalu `employee_number`, lalu hash(nama huruf kecil + tanggal lahir). Duplikat kunci dalam satu berkas → baris kedua dan seterusnya `invalid`. Kunci yang sudah ada di DB → `updated` bila opsi "Perbarui data yang sudah ada" dicentang (hanya field terpetakan yang tidak kosong), selain itu `skipped`. Pegawai baru dibuat beserta penugasan utama. Untuk pegawai yang sudah ada, perbedaan lokasi/jabatan **tidak** memutasi otomatis, hanya diberi peringatan. Memproses ulang berkas yang sama tidak menggandakan data. Sel formula dibaca nilai tersimpannya saja dan tidak pernah dievaluasi. NIK harus diketik sebagai teks: sel NIK bertipe angka ditolak (Excel membulatkan angka 16 digit); `import_rows.data` hanya memuat NIK termasking. Lokasi & atasan harus dalam scope pengunggah; atasan boleh pegawai aktif atau nomor pegawai lain di berkas yang sama. Kunci baris tanpa NIK/nomor/tanggal lahir = nama saja (nama kembar → baris kedua galat). Validasi > 200 baris berjalan di antrean `imports`. |
| BR-055 | **Hapus pegawai** (soft delete) hanya bila belum punya `attendance_logs`, akunnya belum pernah login, dan tidak dirujuk sebagai atasan/kepala lokasi. Selain itu tombol Hapus disembunyikan dan server menolak dengan arahan "Gunakan Nonaktifkan". |

## 7. Presensi

| ID | Aturan |
|---|---|
| BR-060 | **Jendela shift**: absen masuk sah dari `start − checkin_open_min` sampai `end`. Absen pulang sah dari `check_in + attendance.min_minutes_between_in_out` sampai `end + checkout_close_min`. Shift `crosses_midnight`: `end` jatuh di hari berikutnya. |
| BR-061 | **Jadwal efektif** untuk (pegawai, tanggal): `schedule_overrides` bila ada (shift atau libur) → pola `employee_shift_patterns` dengan `effective_from` terbaru ≤ tanggal (kolom hari terkait) → tidak dijadwalkan. |
| BR-062 | **Hari libur** berlaku pada tanggal `start_date` ≤ tanggal ≤ `end_date` (inklusif) bila `applies_to_all` atau lokasi penugasan utama pegawai ada di `holiday_locations`. Pada hari libur status `holiday`, **kecuali** ada override yang menetapkan shift (masuk di hari libur), yang diperlakukan sebagai hari kerja biasa. |
| BR-063 | **Penentuan `work_date`** saat absen: kandidat = shift efektif hari ini dan shift efektif kemarin bila `crosses_midnight`. Dipilih kandidat yang jendelanya memuat waktu absen. Kalau tidak ada kandidat → `ATTENDANCE_OUTSIDE_WINDOW`, atau `ATTENDANCE_NO_SCHEDULE` bila tidak ada jadwal sama sekali. Pengecualian: `attendance.allow_unscheduled = true` → `work_date` = tanggal lokal, tanpa shift. |
| BR-064 | **Validasi lokasi**: akurasi > `attendance.max_accuracy_m` → `ATTENDANCE_LOW_ACCURACY`. Jarak dihitung dengan haversine. Toleransi = radius lokasi (`attendance_radius_m` ?? `attendance.default_radius_m`) + min(akurasi, 50 m). Mode `location`: lokasi penugasan utama harus dalam toleransi. Mode `any_location`: lokasi aktif ber-koordinat terdekat harus dalam toleransi. Mode `anywhere`: tanpa batas radius, tetapi lokasi terdekat & jarak tetap dicatat. |
| BR-065 | **Lokasi palsu**: `is_mock = true` → ditolak `ATTENDANCE_MOCK_LOCATION`, tidak disimpan sebagai log, `security_events.attendance_mock_detected` (dengan koordinat **dibulatkan 3 desimal ≈ 110 m** & perangkat berupa ID + model, tanpa `device_uid`; keputusan Mas 15 Nov 2026), dan notifikasi ke HRD + kepala lokasi pegawai. |
| BR-066 | **Waktu & perhitungan**: absen online memakai waktu server. Telat = menit (`check_in − start`) bila `check_in > start + late_tolerance_min`, selain itu 0. Pulang cepat = menit (`end − check_out`) bila `check_out < end − attendance.early_leave_tolerance_min`, selain itu 0. `work_minutes` = `check_out − check_in`. Absen masuk kedua di `work_date` yang sama → `409 ATTENDANCE_ALREADY_CHECKED_IN`. Absen pulang berulang diperbolehkan dan yang **terakhir** dipakai (semua log tetap tersimpan). |
| BR-067 | **Offline**: `is_offline = true` → `recorded_at = client_time`. Diterima bila `server_received_at − client_time ≤ attendance.offline_max_hours` dan `client_time` tidak lebih dari `attendance.max_clock_skew_minutes` di masa depan. Selain itu `ATTENDANCE_OFFLINE_TOO_OLD`. Jika \|`device_clock_offset_s`\| > `attendance.max_clock_skew_minutes` → tetap diterima, `flags.clock_skew_s` diisi, hari itu `has_anomaly = true`. Untuk absen online, selisih jam perangkat yang besar hanya dicatat sebagai anomali (waktu tetap waktu server). `client_uuid` yang sudah ada → kembalikan data lama (`replayed`), payload baru diabaikan. Validasi radius & lokasi palsu tetap berlaku untuk absen offline. |
| BR-068 | **Status harian** (`AttendanceStatusResolver`, idempoten per pegawai-tanggal), urutan: (1) libur tanpa override shift → `holiday`. (2) Tidak dijadwalkan & tanpa log → `off`. (3) Tanpa absen masuk dan hari sudah ditutup (`attendance.daily_close_time` atau lewat `end + checkout_close_min` untuk shift lintas hari) → `absent`. (4) Ada masuk tanpa pulang dan jendela pulang sudah lewat → `no_checkout`. (5) `late_minutes > 0` → `late`. (6) `early_leave_minutes > 0` → `early_leave`. (7) Selain itu `present`. Snapshot `location_id`, `position_id`, `department_id` dari penugasan utama pada tanggal itu. `has_anomaly` = ada log dengan `flags` atau `within_radius = false` (mode `anywhere`). Job harian memproses hari ini dan kemarin. |
| BR-069 | **Koreksi**: `work_date` ≤ hari ini dan ≥ hari ini − `attendance.correction_max_days`. Maksimal satu `pending` per pegawai-tanggal. Jam usulan harus berada di dalam jendela shift (BR-060) untuk tanggal itu, atau bebas bila tidak ada shift. `approver_employee_id` = atasan langsung pada penugasan utama saat diajukan. Siapa yang boleh memutuskan diatur `06` §6. Disetujui → `attendance_days.check_in_at/check_out_at` diganti nilai usulan (log asli tetap ada), `is_corrected = true`, status dihitung ulang, audit lama/baru. Tolak wajib catatan ≥ 5 karakter. Batal hanya oleh pengaju saat `pending`. Jam usulan `HH:mm` lokal pada `work_date`; untuk shift lintas tengah malam, jam yang jatuh sebelum jendela masuk dibuka (`start − checkin_open_min`) dibaca keesokan harinya. Pulang ≥ masuk (usulan, atau jam masuk hari itu) + `attendance.min_minutes_between_in_out`. Lampiran opsional JPEG/PNG/PDF ≤ `attendance.correction_attachment_max_kb`, isi berkas diperiksa (bukan hanya ekstensi), metadata foto dibuang, disimpan privat. **Presensi manual** (keputusan Mas 16 Nov 2026): pengguna yang memenuhi syarat memutuskan `06` §6 untuk pegawai itu mengisi jam + alasan atas namanya (HP rusak/hilang/ketinggalan) → koreksi langsung `approved` (`submitted_by` = `decided_by` = pengisi), lalu diterapkan seperti persetujuan; batas tanggal & jam sama; tidak untuk diri sendiri. |
| BR-070 | **Notifikasi**: koreksi diajukan → penyetuju (atau kepala lokasi + HRD bila tidak ada atasan aktif). Koreksi diputuskan → pengaju (+ email bila ada). Presensi manual → pegawai yang bersangkutan (bila punya akun). Perangkat `pending` → pemegang `devices.manage` dalam scope. Lokasi palsu → HRD + kepala lokasi. Ekspor/impor selesai → peminta. Password berubah/direset, login perangkat baru, akun terkunci → pemilik akun. **Kanal**: notifikasi aplikasi (lonceng, tabel `notifications`) selalu dibuat dan tidak bisa dimatikan. Kanal luar (`email`, `telegram`, `whatsapp`) = kanal jenis notifikasi (`notification.on_<jenis>`) ∩ kanal global (`notification.channels`) ∩ alamat yang dimiliki penerima (email, `telegram_chat_id`, `phone`). Diatur admin saja, tanpa preferensi per pengguna. **Pengaman**: WhatsApp maksimal `notification.whatsapp_daily_limit` pesan per hari (0 = WhatsApp tidak dipakai untuk notifikasi) dan `notification.whatsapp_hourly_per_user` per penerima per jam; kanal yang gagal `notification.circuit_failures` kali berturut-turut dijeda `notification.circuit_minutes` menit. Kanal yang dilewati (kuota, jeda, gagal) dialihkan ke cadangan berikutnya yang aktif dan dimiliki penerima, urutan WhatsApp → Telegram → Email. Kuota habis atau kanal dijeda → log + email ke `monitoring.alert_emails` (sekali per kejadian). Kuota OTP lupa password terpisah. Setiap upaya kirim kanal luar dicatat di `notification_deliveries` (tanpa isi pesan). Semua kanal luar lewat antrean. |
| BR-071 | **Tugas luar kota** (T-036A): pada `work_date` di dalam tugas `approved` milik pegawai (`start_date` ≤ tanggal ≤ `end_date`), lokasi absen yang sah = aturan biasa BR-064 **∪** aturan tugas: `destination` = radius salah satu titik tujuan (1–10 titik per tugas, `radius_m` ?? `attendance.default_radius_m`, + min(akurasi, 50 m); keputusan Mas 16 Nov 2026); `any_location` = lokasi Pakis aktif ber-koordinat mana pun; `anywhere` = di mana saja (lokasi terdekat & jarak tetap dicatat). Absen di lokasi biasa saat tanggal tugas tetap sah dan tidak diberi penanda. Absen yang diterima **karena** tugas diberi `flags.field_trip_id` + `flags.field_trip_rule` (+ `flags.field_trip_place` = nama titik tujuan yang dipakai, bukan koordinat); penanda ini informasi, **bukan** anomali, dan `within_radius = false` dari tugas `anywhere` juga bukan anomali (BR-068). Tugas tidak mengubah jadwal/shift. Tugas `approved` yang tumpang tindih tanggalnya untuk pegawai yang sama ditolak. Panjang maksimal `attendance.field_trip_max_days` hari. Tugas `pending`/`rejected`/`cancelled` tidak membuka lokasi tambahan. Membuat atau mengubah tugas hanya bila belum selesai (`end_date` ≥ hari ini). Pembuat tidak boleh termasuk pegawai tugasnya sendiri (maker-checker, SEC-031) |
| BR-072 | **Rekap bulanan presensi** (T-039, keputusan Mas 16 Nov 2026): agregasi `attendance_days` per pegawai pada bulan terpilih, hanya baris dalam scope pelaku (06 §5, scope lokasi = snapshot). **Hadir** = hari berstatus `present`/`late`/`early_leave`/`no_checkout` (ada presensi masuk). **Hari kerja** = hadir + `absent`. **% kehadiran** = hadir ÷ hari kerja × 100, dibulatkan 1 desimal; tanpa hari kerja → kosong. **Libur** = `holiday` + `off` (tidak dihitung di % kehadiran). **Telat** = jumlah hari `late_minutes > 0` (total menit = jumlah `late_minutes`); **pulang cepat** = jumlah hari `early_leave_minutes > 0`. Lokasi yang ditampilkan = snapshot hari terakhir di bulan itu. Bulan berjalan hanya menghitung hari yang sudah punya status (hari kerja yang belum ditutup belum dihitung). |
