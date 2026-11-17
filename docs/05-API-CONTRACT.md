# 05 — Kontrak Route & API (Fase 0 + 0,5)

## 1. Konvensi

- **Web admin:** route Inertia, nama `admin.<modul>.<aksi>`, path Bahasa Indonesia, guard `web`, middleware `auth`, `active`, `password.changed`, `mfa.enforced`.
- **API mobile:** prefix `/api/v1`, nama `api.v1.<modul>.<aksi>`, guard `sanctum`, middleware `device.bound`, `app.version`, `active`, `password.changed`, `throttle:api`.
- Route autentikasi web memakai path bawaan Fortify/starter kit (`/login`, `/forgot-password`, `/reset-password/{token}`, `/two-factor-challenge`).
- Setiap route yang mengubah data memanggil Policy, menulis audit (`09` §7), dan memakai CSRF (web).
- **Setiap route bernama `*.index` adalah daftar** dan wajib mematuhi §3 (ditegakkan `ListingContractTest`).

## 2. Format Respons API

### 2.1 Sukses
```json
{ "data": { "...": "..." }, "meta": { "request_id": "01JBX8Q6V7M3T2K9R4N5P6W8YZ" } }
```

### 2.2 Galat
```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Data yang dikirim belum benar.",
    "details": { "proposed_check_in": ["Jam masuk wajib diisi."] },
    "request_id": "01JBX8Q6V7M3T2K9R4N5P6W8YZ"
  }
}
```
Pesan untuk pengguna akhir berbahasa Indonesia. Stack trace, nama tabel, dan SQL tidak pernah muncul (SEC-061).

### 2.3 Kode Galat

| Kode | HTTP | Arti |
|---|---|---|
| `VALIDATION_FAILED` | 422 | Validasi field gagal (`details` per field) |
| `UNAUTHENTICATED` | 401 | Token tidak ada / kedaluwarsa |
| `INVALID_CREDENTIALS` | 401 | Login gagal. **Sama** untuk user tidak ada, password salah, akun/pegawai nonaktif (SEC-001) |
| `MFA_REQUIRED` | 401 | Password benar, perlu kode OTP authenticator (`details.mfa=true`) |
| `MFA_INVALID` | 401 | Kode OTP/recovery salah |
| `MFA_SETUP_REQUIRED` | 403 | Role wajib MFA tetapi MFA belum diaktifkan. Aktivasi dilakukan di web (Profil → Keamanan) |
| `ACCOUNT_LOCKED` | 423 | Terkunci sementara. `details.retry_after_s` |
| `PASSWORD_CHANGE_REQUIRED` | 403 | Wajib ganti password. Hanya `PUT /me/password`, `GET /me`, `GET /config`, `POST /auth/logout` yang boleh |
| `DEVICE_PENDING` | 403 | Perangkat baru menunggu persetujuan admin |
| `DEVICE_MISMATCH` | 401 | Token dipakai dari perangkat lain atau perangkat dicabut |
| `APP_UPDATE_REQUIRED` | 426 | Versi app < `mobile.min_version`. `details.download_url` |
| `FORBIDDEN` | 403 | Tidak punya permission |
| `NOT_FOUND` | 404 | Tidak ada, **atau di luar scope data** (SEC-033) |
| `RATE_LIMITED` | 429 | `details.retry_after_s` |
| `STATE_CONFLICT` | 409 | Status objek sudah berubah (mis. koreksi sudah diputuskan) |
| `ATTENDANCE_NO_SCHEDULE` | 422 | Tidak ada shift hari ini (libur/off) dan `attendance.allow_unscheduled = false` |
| `ATTENDANCE_OUTSIDE_WINDOW` | 422 | Di luar jendela absen shift |
| `ATTENDANCE_ALREADY_CHECKED_IN` | 409 | Sudah absen masuk hari kerja ini |
| `ATTENDANCE_NOT_CHECKED_IN` | 409 | Absen pulang tanpa absen masuk, dan `attendance.allow_checkout_without_checkin = false` |
| `ATTENDANCE_OUT_OF_RADIUS` | 422 | Di luar radius. `details.distance_m`, `details.radius_m`, `details.location_name` |
| `ATTENDANCE_MOCK_LOCATION` | 422 | Lokasi palsu terdeteksi |
| `ATTENDANCE_LOW_ACCURACY` | 422 | Akurasi GPS > `attendance.max_accuracy_m` |
| `ATTENDANCE_OFFLINE_TOO_OLD` | 422 | Absen offline lebih tua dari `attendance.offline_max_hours` |
| `CORRECTION_DUPLICATE_PENDING` | 409 | Sudah ada koreksi menunggu untuk tanggal itu |
| `CORRECTION_NOT_ALLOWED` | 422 | Tanggal di masa depan atau lebih tua dari `attendance.correction_max_days` |
| `CORRECTION_SELF_APPROVAL` | 403 | Menyetujui koreksi milik sendiri (SEC-031) |
| `CHANNEL_UNAVAILABLE` | 503 | Kanal OTP/WA tidak aktif |
| `BAD_REQUEST` | 400 | Request rusak (mis. JSON tidak valid). Ditambah 14 Nov 2026 (T-003) |
| `METHOD_NOT_ALLOWED` | 405 | Method HTTP tidak didukung route ini. Ditambah 14 Nov 2026 (T-003) |
| `PAYLOAD_TOO_LARGE` | 413 | Unggahan/body melebihi batas server (selfie, berkas impor). Ditambah 14 Nov 2026 (T-003) |
| `SERVICE_UNAVAILABLE` | 503 | Server sedang maintenance (deploy). Klien mencoba lagi nanti. Ditambah 14 Nov 2026 (T-003) |
| `SERVER_ERROR` | 500 | Galat tak terduga (dicatat dengan `request_id`) |

Tabel ini dan tabel §2.4a bersama-sama dicocokkan dua arah dengan enum `App\Support\Api\ErrorCode` oleh `tests/Contract/ApiErrorFormatTest.php`. Menambah kode berarti mengubah keduanya. Kode di tabel ini wajib juga ada di Pakis Pro (`mobile-pro/lib/core/api/api_error.dart`, dicek `api_error_contract_test.dart`).

### 2.4 Header

| Header | Arah | Wajib | Keterangan |
|---|---|---|---|
| `Authorization: Bearer <token>` | → | Ya (kecuali login/forgot/config) | Token Sanctum |
| `X-Device-Id` | → | Ya (API) | `device_uid` (hash) yang terdaftar |
| `X-App-Version` | → | Ya (API) | Semver Pakis Pro, mis. `1.0.3` |
| `X-Request-Id` | ↔ | Tidak | Dibuat server bila kosong (ULID), dikembalikan di respons |
| `Accept: application/json` | → | Ya | |

### 2.4a Kode Galat Khusus Web

Kode untuk rute **web** (Paket A dan seterusnya, yang belum punya API mobile). Format galatnya sama dengan §2.2 bila request meminta JSON; pada request Inertia biasa kode ini dikembalikan sebagai galat form + toast berisi pesan kode (PA-16, `15` §11.3). Sengaja ditaruh di luar §2.3 sehingga **tidak** wajib ada di `api_error.dart` dan test kontrak Dart tidak membacanya (PA-32). Bila suatu kode kelak dikirim lewat API, pindahkan barisnya ke §2.3 dan tambahkan ke Pakis Pro. Tiap task menambah kodenya sendiri di sini + enum `ErrorCode` saat kode itu pertama dipakai.

| Kode | HTTP | Arti |
|---|---|---|
| `APPROVAL_RULE_MISSING` | 422 | Tidak ada aturan approval yang cocok untuk dokumen (BR-153). Ditambah 16 Nov 2026 (T-067) |
| `APPROVAL_SELF_DECISION` | 403 | Pengaju/pembuat dokumen mencoba memutuskan persetujuannya sendiri (SEC-101). Ditambah 16 Nov 2026 (T-067) |
| `APPROVAL_ALREADY_PENDING` | 409 | Dokumen sudah punya permintaan persetujuan aktif. Ditambah 16 Nov 2026 (T-067) |
| `QTY_NOT_INTEGER` | 422 | Qty pecahan untuk satuan yang hanya menerima bilangan bulat (meta `integer_only`, BR-150). Ditambah 16 Nov 2026 (T-069); pindah ke §2.3 bila dipakai API mobile (B/C) |
| `UNIT_FACTOR_LOCKED` | 409 | Faktor satuan produk sudah dipakai dokumen (harga, PO, mutasi, …) sehingga tidak bisa diubah; buat satuan baru (BR-150). Ditambah 16 Nov 2026 (T-070) |
| `PRICE_VERSION_LOCKED` | 409 | Ubah item versi daftar harga yang bukan `draft` (SEC-106, `15` §12). Ditambah 17 Nov 2026 (T-075) |
| `PRICE_NOT_FOUND` | 422 | Tidak ada harga berlaku di daftar harga mana pun untuk produk/satuan/qty itu (BR-168). Ditambah 17 Nov 2026 (T-075) |
| `CUSTOMER_PHONE_DUPLICATE` | 422 | HP pemilik sudah dipakai kios lain yang belum nonaktif/digabung (BR-166(2)). `details.owner_phone` = pesan field. Ditambah 17 Nov 2026 (T-074) |
| `PHONE_BELONGS_TO_EMPLOYEE` | 422 | HP kios = HP pegawai aktif atau HP akun pengguna aktif setelah normalisasi (BR-166(3), SEC-105). Pesan tidak menyebut pemilik nomor. Ditambah 17 Nov 2026 (T-074) |
| `CUSTOMER_NOT_VERIFIED` | 422 | Limit kredit diminta untuk kios yang belum terverifikasi (BR-125). Ditambah 17 Nov 2026 (T-074) |
| `OTP_INVALID` | 422 | Kode OTP kios salah (bukan `MFA_INVALID` 401 yang berarti login). Percobaan melebihi `customer.otp_max_attempts` → `RATE_LIMITED` 429. Ditambah 17 Nov 2026 (T-074) |
| `OTP_EXPIRED` | 422 | Kode OTP kios lewat `customer.otp_minutes`, sudah dipakai, atau belum pernah dikirim; kirim ulang. Ditambah 17 Nov 2026 (T-074) |

## 3. Standar Daftar (Listing) — Web & API

### 3.1 Parameter query (sama untuk web dan API)

| Parameter | Contoh | Aturan |
|---|---|---|
| `q` | `q=agus` | Pencarian teks pada kolom `search` di spec. Dipotong 100 karakter. |
| `filter[<key>]` | `filter[status]=active` · `filter[status][]=late&filter[status][]=absent` · `filter[work_date][from]=2026-11-01&filter[work_date][to]=2026-11-30` | Hanya key di spec. Nilai divalidasi sesuai tipe filter. Key tak dikenal diabaikan. |
| `sort` | `sort=-work_date` | Satu kolom, `-` = menurun. Hanya key di spec, selain itu memakai default. |
| `page` | `page=2` | ≥ 1 |
| `per_page` | `per_page=50` | Harus salah satu dari `per_page_options`, maksimum 100 (setting `ui.max_per_page`) |

### 3.2 Bentuk prop Inertia (web)
```json
{
  "rows": [ { "id": 12, "...": "..." } ],
  "listing": {
    "pagination": { "page": 1, "per_page": 25, "total": 132, "last_page": 6, "from": 1, "to": 25 },
    "state": { "q": "agus", "sort": "-work_date", "filters": { "status": ["late"] } },
    "spec": {
      "key": "attendance_days",
      "search_placeholder": "Cari nama atau nomor pegawai",
      "per_page_options": [10, 25, 50, 100],
      "default_sort": "-work_date",
      "sorts": [ { "key": "work_date", "label": "Tanggal" }, { "key": "employee_name", "label": "Nama" } ],
      "filters": [
        { "key": "work_date", "type": "date_range", "label": "Tanggal", "default": { "from": "2026-11-03", "to": "2026-11-03" } },
        { "key": "status", "type": "multi_select", "label": "Status",
          "options": [ { "value": "late", "label": "Telat" }, { "value": "absent", "label": "Tidak hadir" } ] },
        { "key": "location_id", "type": "select", "label": "Lokasi", "options_source": "locations" }
      ],
      "exportable": true
    }
  }
}
```
`options_source` = opsi diambil dari endpoint lookup (§4.13) karena terlalu banyak untuk dikirim langsung.

### 3.3 Bentuk respons API daftar
```json
{
  "data": [ { "...": "..." } ],
  "meta": {
    "request_id": "01JBX...",
    "pagination": { "page": 1, "per_page": 20, "total": 23, "last_page": 2 },
    "listing": { "state": { "...": "..." }, "spec": { "...": "..." } }
  }
}
```

## 4. Route Web Admin

Kolom **Daftar** = harus mematuhi §3. Rincian kolom/cari/filter/sort tiap daftar ada di `07-UI-SPEC.md` §3.4.

### 4.1 Autentikasi & Profil

| Nama | Metode | Path | Permission | Catatan |
|---|---|---|---|---|
| `login` | GET/POST | `/login` | tamu | Field `login` (username/email) + `password` (FR-001) |
| `logout` | POST | `/logout` | auth | |
| `password.request` / `password.email` | GET/POST | `/forgot-password` | tamu | Field `login` + `channel` (`email`/`whatsapp`/`telegram`, hanya kanal aktif). Respons selalu generik (FR-005, FR-006) |
| `password.reset` / `password.update` | GET/POST | `/reset-password/{token}` | tamu | |
| `password.otp` / `password.otp.update` | GET/POST | `/reset-password-otp` | tamu | Reset dengan OTP WhatsApp/Telegram (FR-006) |
| `telegram.webhook` | POST | `/telegram/webhook` | header `X-Telegram-Bot-Api-Secret-Token` | Update bot Telegram: `/start <kode>` menautkan akun (kode dibuat dari Profil, T-017). Tanpa CSRF, throttle |
| `two-factor.login` | GET/POST | `/two-factor-challenge` | tamu | |
| `admin.password.force` / `admin.password.force.update` | GET/PUT | `/ganti-password` | auth | Wajib ganti password (FR-007) |
| `admin.mfa.setup` | GET | `/aktivasi-mfa` | auth + konfirmasi password | Wajib aktivasi MFA (BR-015). Middleware `mfa.enforced` mengalihkan ke sini selama role wajib MFA dan MFA belum aktif; aktivasi lewat endpoint Fortify `user/two-factor-*` |
| `admin.profile.edit` / `admin.profile.update` | GET/PUT | `/profil` | auth | FR-010: `name`, `phone`, `email`, `avatar` (berkas) / `remove_avatar`. Mengubah `email` atau `phone` (kanal pemulihan) wajib `current_password`. Email berubah → `email_verified_at` null + email verifikasi ke alamat baru + pemberitahuan ke alamat lama |
| `admin.profile.avatar` | GET | `/profil/foto` | auth | Stream avatar milik sendiri dari disk privat (404 bila tidak ada) |
| `admin.profile.password` | PUT | `/profil/password` | auth | FR-004, throttle 6/menit |
| `admin.profile.security` | GET | `/profil/keamanan` | auth + konfirmasi password | MFA (endpoint Fortify `user/two-factor-*`), daftar sesi web, perangkat mobile, Telegram |
| `admin.profile.sessions.destroy` | DELETE | `/profil/sesi/{session}` | auth | Wajib `current_password`. `{session}` = kunci buram (HMAC ID sesi), bukan ID sesi asli. Bukan sesi milik sendiri atau sesi yang sedang dipakai → 404 |
| `admin.profile.sessions.destroy-others` | DELETE | `/profil/sesi` | auth | Wajib `current_password` |
| `admin.profile.telegram.link` | POST | `/profil/telegram` | auth | Wajib `current_password`. Balikan tautan `t.me/<bot>?start=<kode>` (sekali pakai, 10 menit). Hanya bila driver Telegram `bot` aktif |
| `admin.profile.telegram.unlink` | DELETE | `/profil/telegram` | auth | Putuskan tautan Telegram |

### 4.2 Dasbor & Notifikasi

| Nama | Metode | Path | Permission | Daftar |
|---|---|---|---|---|
| `admin.dashboard` | GET | `/dasbor` | `dashboard.view` | |
| `admin.notifications.index` | GET | `/notifikasi` | auth (milik sendiri) | ✅ |
| `admin.notifications.read` | POST | `/notifikasi/{id}/baca` | auth | |
| `admin.notifications.read-all` | POST | `/notifikasi/baca-semua` | auth | |
| `admin.notifications.unread-count` | GET | `/notifikasi/jumlah` | auth | JSON, dipolling 60 s |

### 4.3 Pengaturan, Penomoran, Referensi

| Nama | Metode | Path | Permission | Daftar |
|---|---|---|---|---|
| `admin.settings.edit` | GET | `/pengaturan/{group?}` | `settings.view` | |
| `admin.settings.update` | PUT | `/pengaturan/{group}` | `settings.update` | Hanya kunci grup itu |
| `admin.settings.test-mail` | POST | `/pengaturan/uji-email` | `settings.update` | Kirim email uji ke pengguna sendiri |
| `admin.sequences.index` | GET | `/pengaturan/penomoran` | `sequences.view` | ✅ |
| `admin.sequences.update` | PUT | `/pengaturan/penomoran/{sequence}` | `sequences.update` | Pola + periode reset |
| `admin.sequences.preview` | POST | `/pengaturan/penomoran/pratinjau` | `sequences.view` | Pratinjau 3 nomor berikut tanpa menaikkan counter |
| `admin.references.index` | GET | `/referensi` | `references.view` | ✅ (`filter[group]` wajib, default grup pertama) |
| `admin.references.store` | POST | `/referensi` | `references.manage` | |
| `admin.references.update` | PUT | `/referensi/{item}` | `references.manage` | `code` terkunci bila `is_system` |
| `admin.references.destroy` | DELETE | `/referensi/{item}` | `references.manage` | Ditolak bila `is_system` atau sudah dipakai (BR-026) |
| `admin.references.reorder` | POST | `/referensi/urutan` | `references.manage` | |

### 4.4 Pengguna, Role, Audit

| Nama | Metode | Path | Permission | Daftar |
|---|---|---|---|---|
| `admin.users.index` | GET | `/pengguna` | `users.view` | ✅ |
| `admin.users.create` / `store` | GET/POST | `/pengguna/baru` · `/pengguna` | `users.create` | |
| `admin.users.show` | GET | `/pengguna/{user}` | `users.view` | Tab: profil, role, sesi, perangkat, riwayat |
| `admin.users.edit` / `update` | GET/PUT | `/pengguna/{user}/ubah` · `/pengguna/{user}` | `users.update` | |
| `admin.users.toggle` | PATCH | `/pengguna/{user}/status` | `users.deactivate` | Nonaktif = cabut sesi & token |
| `admin.users.reset-password` | POST | `/pengguna/{user}/reset-password` | `users.reset_password` | FR-007, konfirmasi password admin |
| `admin.users.reset-mfa` | POST | `/pengguna/{user}/reset-mfa` | `users.reset_mfa` | |
| `admin.users.revoke-sessions` | POST | `/pengguna/{user}/cabut-sesi` | `users.update` | |
| `admin.users.export` | GET | `/pengguna/ekspor` | `users.export` | |
| `admin.roles.index` | GET | `/role` | `roles.view` | ✅ |
| `admin.roles.create` / `store` | GET/POST | `/role/baru` · `/role` | `roles.manage` + konfirmasi password | |
| `admin.roles.edit` / `update` | GET/PUT | `/role/{role}/ubah` · `/role/{role}` | `roles.manage` + konfirmasi password | Matriks permission. `owner`/`super_admin` dan role yang dipegang pengguna sendiri: baca-saja (update → 403) |
| `admin.roles.destroy` | DELETE | `/role/{role}` | `roles.manage` + konfirmasi password | Ditolak bila `is_system` atau masih dipakai pengguna |
| `admin.audit.index` | GET | `/audit` | `audit.view` | ✅ |
| `admin.audit.show` | GET | `/audit/{log}` | `audit.view` | Diff lama/baru |
| `admin.audit.export` | GET | `/audit/ekspor` | `audit.export` | |
| `admin.security-events.index` | GET | `/log-keamanan` | `audit.view` | ✅ |
| `admin.security-events.export` | GET | `/log-keamanan/ekspor` | `audit.export` | |

### 4.5 Wilayah & Organisasi

| Nama | Metode | Path | Permission | Daftar |
|---|---|---|---|---|
| `admin.regions.index` | GET | `/wilayah` | `regions.view` | ✅ (desa/kelurahan + filter berjenjang) |
| `admin.locations.index` | GET | `/lokasi` | `locations.view` | ✅ |
| `admin.locations.create`/`store`/`edit`/`update`/`destroy` | | `/lokasi/...` | `locations.manage` | Hapus ditolak bila dipakai penugasan (nonaktifkan saja) |
| `admin.locations.export` | GET | `/lokasi/ekspor` | `locations.view` | |
| `admin.departments.index` | GET | `/departemen` | `departments.view` | ✅ |
| `admin.departments.create`/`store`/`edit`/`update`/`destroy` | | `/departemen/...` | `departments.manage` | |
| `admin.departments.export` | GET | `/departemen/ekspor` | `departments.view` | |
| `admin.positions.index` | GET | `/jabatan` | `positions.view` | ✅ |
| `admin.positions.create`/`store`/`edit`/`update`/`destroy` | | `/jabatan/...` | `positions.manage` | |
| `admin.positions.export` | GET | `/jabatan/ekspor` | `positions.view` | |

### 4.6 Pegawai & Penugasan

| Nama | Metode | Path | Permission | Daftar |
|---|---|---|---|---|
| `admin.employees.index` | GET | `/pegawai` | `employees.view` | ✅ (scope data) |
| `admin.employees.create` / `store` | GET/POST | `/pegawai/baru` · `/pegawai` | `employees.create` | Sekaligus penugasan pertama + opsi buat akun |
| `admin.employees.show` | GET | `/pegawai/{employee}` | `employees.view` | Tab: data, penugasan, akun & perangkat, jadwal, absensi, riwayat |
| `admin.employees.edit` / `update` | GET/PUT | `/pegawai/{employee}/ubah` · `/pegawai/{employee}` | `employees.update` | |
| `admin.employees.destroy` | DELETE | `/pegawai/{employee}` | `employees.delete` | Hanya bila belum punya absensi/akun pernah login (BR-055). Selain itu: offboard |
| `admin.employees.reveal-nik` | POST | `/pegawai/{employee}/nik` | `employees.view_sensitive` | JSON, dicatat `sensitive_data_viewed` |
| `admin.employees.photo` | GET | `/pegawai/{employee}/foto` | `employees.view` | Foto pegawai dari disk privat (SEC-050), ber-scope (luar scope 404). Ditambahkan T-024 |
| `admin.employees.offboard` | POST | `/pegawai/{employee}/nonaktifkan` | `employees.offboard` | FR-027 |
| `admin.employees.rehire` | POST | `/pegawai/{employee}/aktifkan` | `employees.offboard` | Status aktif + penugasan baru wajib |
| `admin.employees.account` | POST | `/pegawai/{employee}/akun` | `users.create` | Buat akun dari data pegawai |
| `admin.employees.export` | GET | `/pegawai/ekspor` | `employees.export` | |
| `admin.assignments.index` | GET | `/penugasan` | `assignments.view` | ✅ (scope data) |
| `admin.assignments.store` | POST | `/pegawai/{employee}/penugasan` | `assignments.manage` | Mutasi: tutup lama + buat baru (BR-052) |
| `admin.assignments.update` | PUT | `/penugasan/{assignment}` | `assignments.manage` | Hanya penugasan aktif, field: atasan, mode absen, catatan |
| `admin.assignments.end` | POST | `/penugasan/{assignment}/akhiri` | `assignments.manage` | |
| `admin.assignments.export` | GET | `/penugasan/ekspor` | `assignments.view` | |

### 4.7 Import & Export

| Nama | Metode | Path | Permission | Daftar |
|---|---|---|---|---|
| `admin.imports.index` | GET | `/impor` | `employees.import` | ✅ |
| `admin.imports.template` | GET | `/impor/pegawai/templat` | `employees.import` | Templat .xlsx standar |
| `admin.imports.create` | GET | `/impor/pegawai/baru` | `employees.import` | Halaman unggah, langkah 1 wizard (keputusan Mas 15 Nov 2026) |
| `admin.imports.store` | POST | `/impor/pegawai` | `employees.import` | Unggah (FR-028) |
| `admin.imports.mapping` / `admin.imports.mapping.update` | GET/PUT | `/impor/{batch}/pemetaan` | `employees.import` | Simpan sebagai profil opsional |
| `admin.imports.show` | GET | `/impor/{batch}` | `employees.import` | Ringkasan + tabel baris |
| `admin.imports.rows.index` | GET | `/impor/{batch}/baris` | `employees.import` | ✅ (filter status baris) |
| `admin.imports.process` | POST | `/impor/{batch}/proses` | `employees.import` | Hanya bila `validated` |
| `admin.imports.cancel` | POST | `/impor/{batch}/batal` | `employees.import` | |
| `admin.imports.errors` | GET | `/impor/{batch}/galat` | `employees.import` | .xlsx baris galat + kolom pesan |
| `admin.import-profiles.index` | GET | `/impor/profil` | `employees.import` | ✅ |
| `admin.import-profiles.destroy` | DELETE | `/impor/profil/{profile}` | `employees.import` | |
| `admin.exports.index` | GET | `/unduhan` | auth (milik sendiri) | ✅ |
| `admin.exports.download` | GET | `/unduhan/{file}` | pemilik | Signed URL berumur pendek |

Semua route `*.export`: bila baris ≤ `export.sync_max_rows` → unduh langsung. Bila lebih → antrean, notifikasi saat siap (FR-029).

### 4.8 Perangkat

| Nama | Metode | Path | Permission | Daftar |
|---|---|---|---|---|
| `admin.devices.index` | GET | `/perangkat` | `devices.view` | ✅ (scope data) |
| `admin.devices.approve` | POST | `/perangkat/{device}/setujui` | `devices.manage` | Perangkat aktif lama → `replaced` |
| `admin.devices.reject` | POST | `/perangkat/{device}/tolak` | `devices.manage` | Alasan wajib |
| `admin.devices.revoke` | POST | `/perangkat/{device}/cabut` | `devices.manage` | Token dicabut |
| `admin.devices.export` | GET | `/perangkat/ekspor` | `devices.view` | |

### 4.9 Shift, Jadwal, Hari Libur

| Nama | Metode | Path | Permission | Daftar |
|---|---|---|---|---|
| `admin.shifts.index` | GET | `/shift` | `shifts.view` | ✅ |
| `admin.shifts.create`/`store`/`edit`/`update`/`destroy` | | `/shift/...` | `shifts.manage` | Hapus ditolak bila dipakai jadwal (nonaktifkan) |
| `admin.schedules.index` | GET | `/jadwal` | `schedules.view` | ✅ (pegawai + pola aktif, scope data) |
| `admin.schedules.calendar` | GET | `/jadwal/kalender` | `schedules.view` | Grid bulan × pegawai, paging per 25 pegawai + cari + filter `month` (`filter[month]=YYYY-MM`, wajib, default bulan ini)/lokasi/departemen/jabatan |
| `admin.schedules.patterns.store` | POST | `/jadwal/pola` | `schedules.manage` | Massal: `employee_ids[]`, `effective_from`, `days{mon..sun}` |
| `admin.schedule-overrides.index` | GET | `/jadwal/pengecualian` | `schedules.view` | ✅ |
| `admin.schedule-overrides.export` | GET | `/jadwal/pengecualian/ekspor` | `schedules.view` | |
| `admin.schedule-overrides.store` | POST | `/jadwal/pengecualian` | `schedules.manage` | Massal per tanggal |
| `admin.schedule-overrides.destroy` | DELETE | `/jadwal/pengecualian/{override}` | `schedules.manage` | |
| `admin.holidays.index` | GET | `/hari-libur` | `holidays.view` | ✅ |
| `admin.holidays.export` | GET | `/hari-libur/ekspor` | `holidays.view` | |
| `admin.holidays.create`/`store`/`edit`/`update`/`destroy` | | `/hari-libur/...` | `holidays.manage` | |
| `admin.field-trips.index` | GET | `/tugas-luar-kota` | `field_trips.view` | ✅ (scope: tugas yang punya pegawai dalam scope) |
| `admin.field-trips.create`/`store` | GET/POST | `/tugas-luar-kota/baru`, `/tugas-luar-kota` | `field_trips.manage` | Langsung `approved` (T-036A bagian 1); semua pegawai harus dalam scope |
| `admin.field-trips.edit`/`update` | GET/PUT | `/tugas-luar-kota/{trip}/ubah`, `/tugas-luar-kota/{trip}` | `field_trips.manage` | Hanya `approved` yang belum selesai; semua pegawai tugas dalam scope (selain itu 403); di luar scope → 404 |
| `admin.field-trips.cancel` | POST | `/tugas-luar-kota/{trip}/batalkan` | `field_trips.manage` | `approved` → `cancelled` |
| `admin.field-trips.approve`/`reject` | POST | `/tugas-luar-kota/{trip}/setujui`, `/tugas-luar-kota/{trip}/tolak` | `field_trips.approve` | Pengajuan Pakis Pro `pending` → `approved`/`rejected` (06 §6, §7.2a; T-036A bagian 2). `note` opsional ≤ 255 / wajib 5–255. Milik sendiri 403, di luar wewenang 404, sudah diputuskan atau bentrok tugas lain → galat form |

### 4.10 Absensi & Koreksi

| Nama | Metode | Path | Permission | Daftar |
|---|---|---|---|---|
| `admin.attendance.index` | GET | `/absensi` | `attendance.view` | ✅ (per pegawai-tanggal, scope data) |
| `admin.attendance.show` | GET | `/absensi/{day}` | `attendance.view` | Log masuk/pulang, peta, foto (bila berhak) |
| `admin.attendance.selfie` | GET | `/absensi/foto/{log}` | `attendance.view_photo` | Signed URL 5 menit, stream dari disk privat |
| `admin.attendance.export` | GET | `/absensi/ekspor` | `attendance.export` | |
| `admin.attendance-summary.index` | GET | `/absensi/rekap` | `attendance.view` | ✅ (per pegawai per bulan) |
| `admin.attendance-summary.export` | GET | `/absensi/rekap/ekspor` | `attendance.export` | |
| `admin.attendance-corrections.index` | GET | `/koreksi-absen` | `attendance_corrections.view` | ✅ (scope data) |
| `admin.attendance-corrections.show` | GET | `/koreksi-absen/{correction}` | `attendance_corrections.view` | |
| `admin.attendance-corrections.approve` | POST | `/koreksi-absen/{correction}/setujui` | `attendance_corrections.approve` | BR-069 |
| `admin.attendance-corrections.reject` | POST | `/koreksi-absen/{correction}/tolak` | `attendance_corrections.approve` | Alasan wajib |
| `admin.attendance-corrections.export` | GET | `/koreksi-absen/ekspor` | `attendance_corrections.view` | |
| `admin.attendance-corrections.attachment` | GET | `/koreksi-absen/{correction}/lampiran` | `attendance_corrections.view` | Unduh lampiran (`Content-Disposition: attachment`, `no-store`), scope sama dengan detail (T-041) |
| `admin.attendance-corrections.manual` | POST | `/koreksi-absen/manual` | `attendance_corrections.approve` | Presensi manual (keputusan Mas 16 Nov 2026, BR-069): `employee_id`, `work_date`, `type`, `proposed_check_in/out`, `reason_code`, `note`; langsung `approved`, wewenang 06 §6, bukan untuk diri sendiri (403) |

### 4.10a Persetujuan (Paket A, T-067)

Mesin approval generik (`15` §4.2, §5, BR-153). Galat domain lewat jalur Inertia PA-16 (`ApiError` → galat form + toast; JSON bila `expectsJson`).

| Nama | Metode | Path | Permission | Daftar |
|---|---|---|---|---|
| `admin.approvals.index` | GET | `/persetujuan` | login web; tab `submitted`/`all` butuh `approvals.view` (403) | ✅ `approvals` (tab `mine` default, PA-27) |
| `admin.approval-delegations.index` | GET | `/persetujuan/delegasi` | login web (milik sendiri), `approvals.configure` (semua) | ✅ `approval_delegations` |
| `admin.approval-delegations.store` | POST | `/persetujuan/delegasi` | login web; `delegator_user_id` lain hanya `approvals.configure` | `delegate_user_id`, `start_date` ≥ hari ini, `end_date`, `document_types[]` (kosong = semua), `reason` 5–255; ke diri sendiri 422 |
| `admin.approval-delegations.destroy` | DELETE | `/persetujuan/delegasi/{delegation}` | pemberi atau `approvals.configure` (penerima 403, lainnya 404) | Cabut (`is_active=false`) |
| `admin.approvals.show` | GET | `/persetujuan/{approval}` | calon penyetuju langkah aktif / pengaju / pemutus sebelumnya / `approvals.view` dalam scope; selain itu 404 | |
| `admin.approvals.approve`/`reject` | POST | `/persetujuan/{approval}/setujui`, `/tolak` | calon penyetuju langkah aktif (BR-153) | `step` wajib (langkah lain → 409 `STATE_CONFLICT`), `note` (tolak wajib 5–500), `current_password` bila nominal ≥ `approval.reauth_amount` dan belum konfirmasi (galat field `current_password`). Pengaju/pembuat/delegasi mereka → 403 `APPROVAL_SELF_DECISION`; bukan giliran → 409. `throttle:20,1` |
| `admin.approvals.cancel` | POST | `/persetujuan/{approval}/batalkan` | pengaju, atau `approvals.configure` (bukan `super_admin`, PA-38); lainnya 403 | Pengaju: hanya selama belum ada langkah diputuskan (409). Admin: kapan pun selama `pending`, `note` wajib 5–500 |
| `admin.approval-rules.index` | GET | `/pengaturan/aturan-persetujuan` | `approvals.configure` | ✅ `approval_rules` |
| `admin.approval-rules.preview` | POST | `/pengaturan/aturan-persetujuan/pratinjau` | `approvals.configure` | JSON `{data: {rule, auto_approve, missing, steps[]}}` |
| `admin.approval-rules.create`/`store` | GET/POST | `/pengaturan/aturan-persetujuan/baru`, `/pengaturan/aturan-persetujuan` | `approvals.configure` + konfirmasi password | |
| `admin.approval-rules.edit`/`update`/`destroy` | GET/PUT/DELETE | `/pengaturan/aturan-persetujuan/{rule}/ubah`, `/pengaturan/aturan-persetujuan/{rule}` | `approvals.configure` + konfirmasi password | Hapus: belum dipakai = dihapus, sudah dipakai = dinonaktifkan |

### 4.10b Lokasi Stok, Rak & Kendaraan (Paket A, T-068)

Dimensi scope **Stok** (`15` §6.2, `DataScope::forStock()`): di luar scope → 404. Tanpa rute hapus: lokasi stok, rak, dan kendaraan dinonaktifkan (PA-43). Galat domain lewat jalur Inertia PA-16.

| Nama | Metode | Path | Permission | Daftar / isi |
|---|---|---|---|---|
| `admin.stock-locations.index` | GET | `/lokasi-stok` | `stock_locations.view` | ✅ `stock_locations` |
| `admin.stock-locations.create`/`store` | GET/POST | `/lokasi-stok/baru`, `/lokasi-stok` | `stock_locations.manage` | `code` (`[A-Z0-9-]{2,20}`, unik termasuk terhapus; awalan `KDR-` & `TRANSIT` dicadangkan), `name`, `type` (`warehouse`/`store` saja), `location_id` (aktif, dalam scope), `is_sellable`, `is_active`. Rak `Q-01` dibuat otomatis |
| `admin.stock-locations.edit`/`update` | GET/PUT | `/lokasi-stok/{stockLocation}/ubah`, `/lokasi-stok/{stockLocation}` | `stock_locations.manage` | Tipe tidak bisa diubah (422). Lokasi stok kendaraan: hanya `is_sellable`. TRANSIT → 403. Nonaktif saat bersaldo → 409 `STATE_CONFLICT` |
| `admin.stock-bins.index` | GET | `/lokasi-stok/rak` | `stock_locations.view` | ✅ `stock_bins` (`filter[stock_location_id]` wajib) |
| `admin.stock-bins.store` | POST | `/lokasi-stok/rak` | `stock_locations.manage` | `stock_location_id` (gudang/toko; kendaraan/TRANSIT 422; di luar scope 404), `code` (`[A-Z0-9-]{1,20}`, unik per lokasi stok), `name?`, `zone`, `is_active` |
| `admin.stock-bins.update` | PUT | `/lokasi-stok/rak/{bin}` | `stock_locations.manage` | Sama tanpa `stock_location_id` (422 bila dikirim). `Q-01`: kode/zona/status terkunci (422). Nonaktif saat bersaldo → 409 |
| `admin.vehicles.index` | GET | `/kendaraan` | `vehicles.view` | ✅ `vehicles` (scope lokasi asal, dimensi Stok) |
| `admin.vehicles.create`/`store`/`edit`/`update` | GET/POST/GET/PUT | `/kendaraan/baru`, `/kendaraan`, `/kendaraan/{vehicle}/ubah`, `/kendaraan/{vehicle}` | `vehicles.manage` | `plate_no` (spasi dibuang, huruf besar, `[A-Z0-9]{2,15}`, unik termasuk terhapus), `name`, `vehicle_type`, `is_canvas`, `home_location_id` (wajib bila canvas, dalam scope), `is_active`. Canvas & aktif → lokasi stok `KDR-{plat}`; cabut/nonaktif → lokasi stoknya nonaktif (409 bila bersaldo) |

### 4.10d Area Penjualan & Pelanggan (Paket A, T-073)

Dimensi scope **Pelanggan** (`15` §6.2, `DataScope::forCustomer()`): kios di luar scope → 404 (route binding ber-scope `Customer::resolveRouteBinding`). Area penjualan tidak ber-scope untuk dilihat; pilihan area & sales di form kios dan pengalihan dibatasi scope pelaku (supervisor: area yang ia supervisi + sub-area, sales anggota timnya). Galat domain lewat jalur Inertia PA-16. Tanpa rute hapus.

| Nama | Metode | Path | Permission | Daftar / isi |
|---|---|---|---|---|
| `admin.sales-areas.index` | GET | `/area-penjualan` | `sales_areas.view` | ✅ `sales_areas`. Tambah/ubah lewat dialog di halaman ini |
| `admin.sales-areas.store`/`update` | POST/PUT | `/area-penjualan`, `/area-penjualan/{salesArea}` | `sales_areas.manage` | `code` (`[A-Z0-9-]{2,20}`, unik termasuk terhapus), `name`, `parent_id?` (area aktif; tanpa siklus; kedalaman maks 3 tingkat), `supervisor_employee_id?` (pegawai aktif dalam scope), `home_stock_location_id?` (gudang/toko aktif dalam dimensi Stok), `district_codes[]` (kode kecamatan, maks 100; satu kecamatan boleh di beberapa area), `is_active` |
| `admin.customers.index` | GET | `/pelanggan` | `customers.view` | ✅ `customers` (scope Pelanggan) |
| `admin.customers.create`/`store` | GET/POST | `/pelanggan/baru`, `/pelanggan` | `customers.manage` | `code` (hanya bila `customer.code_mode = manual`: `[A-Z0-9-]{3,20}`, unik termasuk terhapus; mode `auto` → `NumberGenerator('customer')`), `store_name`, `owner_name`, `owner_phone` (dinormalisasi `62…`, `PhoneNormalizer`), `customer_type_id` (referensi `customer_type` aktif), `address?`, `village_code?`, `latitude?`/`longitude?` (berpasangan), `sales_area_id` (aktif, dalam scope pelaku), `assigned_sales_employee_id?` (pegawai aktif dalam scope), `home_stock_location_id?` (gudang/toko dalam dimensi Stok), `term_days` (0–365, default `customer.default_term_days`). Status awal `prospect`, limit nonaktif; penugasan pertama mulai hari ini |
| `admin.customers.show` | GET | `/pelanggan/{customer}` | `customers.view` | Detail bertab `?tab=` (profil, riwayat area/sales; kontak, verifikasi, limit ditambah T-074) |
| `admin.customers.edit`/`update` | GET/PUT | `/pelanggan/{customer}/ubah`, `/pelanggan/{customer}` | `customers.manage` | Data profil saja (tanpa kode, area/sales, limit, verifikasi). HP hanya bisa diubah selama kios `prospect` dan HP belum terverifikasi; setelahnya lewat ganti HP (T-074) → 409 `STATE_CONFLICT` |
| `admin.customers.reassign` | POST | `/pelanggan/alihkan` | `customers.manage` | `customer_ids[]` (1–500, dalam scope, bukan `merged`), `sales_area_id`, `sales_employee_id?`, `effective_date` (hari ini atau nanti, tanggal lokal), `reason?` (≤ 255). Satu transaksi (BR-167): penugasan lama ditutup `end_date = H−1`, baru mulai H; cache `customers` diperbarui bila H = hari ini, selain itu oleh job `customers:apply-assignments` (00.05). Pengalihan kedua sebelum H mengganti penugasan yang masih tertunda. `throttle:30,1` |
| `admin.customers.export` | GET | `/pelanggan/ekspor` | `customers.export` | Listing `customers` lewat `StartExport`; HP pemilik **selalu termasking** (SEC-108) |
| `admin.customers.store`/`update` (T-074) | | | | Tambahan BR-166: HP pegawai aktif / akun aktif → 422 `PHONE_BELONGS_TO_EMPLOYEE`; HP kios lain (prospek/aktif/tahan kredit) → 422 `CUSTOMER_PHONE_DUPLICATE` (galat field `owner_phone`). Ada kandidat duplikat → galat field `not_duplicate` sampai dikirim `not_duplicate=true` + `duplicate_reason` (5–255), override tercatat audit `customer.duplicate_override`. HP prospek yang diubah membatalkan verifikasi aktivasi yang menunggu (BR-153(10)) |
| `admin.customers.duplicates` | POST | `/pelanggan/cek-duplikat` | `customers.manage` | **JSON.** Body `store_name`, `owner_phone?`, `latitude?`/`longitude?`, `village_code?`, `customer_id?` (kios yang sedang diubah, dikecualikan; di luar scope → 404). `{data: {phone_conflict: null\|{code, message}, candidates: [{id\|null, in_scope, code, store_name, status, status_label, reasons[name_nearby\|name_same_village\|phone], similarity, distance_m, owner_name?, owner_phone?, village?, sales_area?}]}}` (maks 10). Kios di luar scope: tanpa `id`/pemilik/HP (PA-52). Galat format §2.2 |
| `admin.customers.otp.send` | POST | `/pelanggan/{customer}/otp` | `customers.manage` | **JSON.** Kirim OTP aktivasi lewat WhatsApp ke `owner_phone` (kios `prospect`, tanpa verifikasi menunggu). `{data: {phone (termasking), expires_at, length}}`; kode tidak pernah dikembalikan. 503 `CHANNEL_UNAVAILABLE` (setting mati / WhatsApp tidak tersedia / kirim gagal), 429 `RATE_LIMITED` (`customer.otp_send_per_phone_hour`, `customer.otp_send_per_user_hour`) |
| `admin.customers.otp.check` | POST | `/pelanggan/{customer}/otp/cek` | `customers.manage` | **JSON.** Body `code` (`auth.otp_length` digit). `{data: {valid: true}}`; 422 `OTP_INVALID`/`OTP_EXPIRED`, 429 `RATE_LIMITED` (> `customer.otp_max_attempts`), 503. Memakai satu jatah percobaan, tidak menghabiskan kode |
| `admin.customers.verify` | POST | `/pelanggan/{customer}/verifikasi` | `customers.manage` | Multipart: `method` (`otp`/`office_call`), `photo` (JPG/PNG/WEBP menurut isi, SEC-109), `latitude`, `longitude`, `accuracy_m` (≤ `customer.verify_max_accuracy_m`, tidak disimpan), `requested_credit_limit` (rupiah ≥ 0, kosong = 0), `otp_code` (wajib bila `otp`). `otp` → baris `customer_verifications` + approval `customer_activation`; `office_call` → draf menunggu telepon kantor (PA-49). `throttle:30,1` |
| `admin.customers.office-call` | POST | `/pelanggan/{customer}/verifikasi/telepon-kantor` | `customers.manage` | Body `office_call_note` (10–500). Pengguna ≠ pengaju draf (403-bisnis `APPROVAL_SELF_DECISION`, galat field `office_call_note`) → baris verifikasi `office_call` + approval `customer_activation` atas nama pengaju (PA-49). `throttle:30,1` |
| `admin.customer-verifications.index` | GET | `/pelanggan/verifikasi` | `customers.verify` | ✅ `customer_verifications` (scope Pelanggan), antrean verifikasi. Baris membawa `photo_url` (signed 5 menit) |
| `admin.customers.change-phone` (T-074) | POST | `/pelanggan/{customer}/ganti-hp` | `customers.change_phone` | `new_phone` (dinormalisasi `62…`, ≠ HP sekarang, lolos BR-166(3) → 422 `PHONE_BELONGS_TO_EMPLOYEE`/`CUSTOMER_PHONE_DUPLICATE` di `new_phone`), `method` (`otp`/`office_call`), `otp_code` (wajib bila `otp`, kode dari HP **lama**), `office_call_note` (10–500, wajib bila `office_call`), `reason` (5–255). Kios digabung / prospek ber-HP belum terverifikasi (ubah lewat form) → 409; sudah ada ganti HP menunggu → 409 `APPROVAL_ALREADY_PENDING`. → verifikasi `phone_change` + approval `customer_phone_change`. `throttle:30,1` |
| `admin.customers.change-phone.otp.send` / `.otp.check` (T-074) | POST | `/pelanggan/{customer}/ganti-hp/otp` · `/ganti-hp/otp/cek` | `customers.change_phone` | **JSON**, bentuk & galat sama dengan `admin.customers.otp.*`; tujuan HP **lama** pemilik, `purpose = phone_change` |
| `admin.customers.credit-limit` (T-074) | POST | `/pelanggan/{customer}/limit` | `customers.manage` | `credit_limit` (rupiah ≥ 0, ≠ limit sekarang), `reason` (5–255). Belum terverifikasi → 422 `CUSTOMER_NOT_VERIFIED` (galat field `credit_limit`); status bukan aktif/tahan kredit → 409; limit menunggu → 409 `APPROVAL_ALREADY_PENDING`. → approval `customer_credit_limit`. `throttle:30,1` |
| `admin.customers.merge` (T-074) | POST | `/pelanggan/{customer}/gabung` | `customers.manage` | `target_customer_id` (kios lain yang belum nonaktif/digabung dan dalam scope; selain itu galat field "tidak ditemukan"), `reason` (5–255). Kios di URL = sumber. → approval `customer_merge`. `throttle:30,1` |
| `admin.customers.status` (T-074) | POST | `/pelanggan/{customer}/status` | `customers.manage` | `action` (`hold`/`release`/`deactivate`/`reactivate`), `reason` (5–255). Transisi tidak sah dari status sekarang → 409 (`15` §6.6). `throttle:30,1` |
| `admin.customers.contacts.store` / `update` / `destroy` (T-074) | POST/PUT/DELETE | `/pelanggan/{customer}/kontak` · `/pelanggan/{customer}/kontak/{contact}` | `customers.manage` | `name` (2–100), `role?` (≤ 60), `phone?` (dinormalisasi `62…`). Kontak milik kios lain → 404. Kios digabung → 409. `throttle:60,1` |
| `admin.customers.photo` / `admin.customers.restricted-document` / `admin.customer-verifications.photo` (T-074) | GET | `/pelanggan/{customer}/foto` · `/pelanggan/{customer}/dokumen-pembeli-terbatas` · `/pelanggan/verifikasi/{verification}/foto` | `customers.view` + `signed:relative` | Berkas disk privat, URL bertanda tangan 5 menit dari prop halaman (`photo_url`, `restricted_buyer_document_url`); tanpa/kedaluwarsa tanda tangan → 403, di luar scope → 404. `throttle:120,1` |
| `admin.customers.update` (T-074, ketentuan dagang) | | | | Tambahan opsional (hanya ubah): `harvest_term_allowed` (bool) + `harvest_term_max_days` (1–365, wajib bila aktif), `restricted_buyer_eligible` (bool) + `restricted_buyer_note?` (≤ 255) + `restricted_buyer_valid_until` (tanggal ≥ hari ini, wajib bila layak) + `restricted_buyer_document` (berkas JPG/PNG/WEBP/PDF menurut isi, SEC-109; wajib bila layak dan belum ada). Kolom yang tidak dikirim tidak berubah |

### 4.11 Lookup (untuk select/autocomplete)
Satu route `admin.lookup.show`, GET `/lookup/<sumber>?q=&parent=&limit=&ids[]=` (sumber terdaftar di `LookupRegistry`; tak dikenal → 404, tanpa izin sumber → 403, parameter salah → 422, `throttle:120,1`). Mengembalikan `[{value,label,hint?}]`, maksimum 20 hasil (`limit` 1–20, `LIMIT` di SQL), hanya entri aktif dan **dalam scope data** pemanggil. `ids[]` (≤ 20) mengembalikan label nilai yang sudah terpilih (chip filter), tetap aktif & ber-scope. Sumber: `regions` (dengan `level` & `parent`), `locations`, `departments`, `positions`, `employees`, `shifts`, `roles`, `references` (dengan `group`, semua pengguna login), `users` (pemegang `audit.view` atau `users.view`; akun nonaktif ikut tampil untuk menyaring pelaku audit lama — keputusan 15 Nov 2026), `approval_delegates` (semua login web; akun aktif saja, label nama + username; untuk form delegasi persetujuan, T-067), `stock_locations` (pemegang `stock_locations.view` atau `stock.view`; dimensi Stok; `type` opsional, `binnable=1` = gudang/toko saja; T-068), `stock_bins` (izin sama; `parent` = ID lokasi stok, wajib; rak aktif di lokasi stok dalam scope; T-068), `sales_areas` (pemegang `sales_areas.view`, `customers.view`, atau `customers.manage`; area aktif, tanpa scope; `exclude` = sembunyikan satu area & sub-areanya untuk pilihan induk, `assignable=1` = hanya area dalam scope pelaku untuk form kios/pengalihan; T-073), `customers` (pemegang `customers.view`; scope Pelanggan; tanpa kios `inactive`/`merged`; T-073), `sales_employees` (pemegang `customers.view`, `customers.manage`, `sales_areas.view`, atau `sales_areas.manage`; pegawai aktif dalam scope data pemanggil, untuk pemilih sales/supervisor tanpa melonggarkan `employees`; T-073). Lookup **bukan** daftar `*.index`, jadi dikecualikan dari test kontrak listing. Lookup tetap wajib pencarian + limit (BR-006).

## 5. API v1 (Pakis Pro)

### 5.1 Ringkasan Endpoint

| Nama | Metode | Path | Akses | Daftar |
|---|---|---|---|---|
| `api.v1.config` | GET | `/api/v1/config` | publik | |
| `api.v1.auth.login` | POST | `/api/v1/auth/login` | publik, throttle `login` | |
| `api.v1.auth.logout` | POST | `/api/v1/auth/logout` | token | |
| `api.v1.auth.password.forgot` | POST | `/api/v1/auth/password/forgot` | publik, throttle `password` | |
| `api.v1.auth.password.reset-otp` | POST | `/api/v1/auth/password/reset-otp` | publik, throttle `password` | |
| `api.v1.me.show` | GET | `/api/v1/me` | token | |
| `api.v1.me.update` | PUT | `/api/v1/me` | token | `phone`, `avatar` |
| `api.v1.me.password` | PUT | `/api/v1/me/password` | token | |
| `api.v1.me.revoke-others` | POST | `/api/v1/me/sessions/revoke-others` | token | Cabut sesi web milik sendiri |
| `api.v1.me.avatar` | GET | `/api/v1/me/avatar` | token | Foto profil milik sendiri (WebP, `no-store`); tanpa foto → 404 `NOT_FOUND` (keputusan Mas 16 Nov 2026) |
| `api.v1.attendance.today` | GET | `/api/v1/attendance/today` | `attendance.self` | |
| `api.v1.attendance.store` | POST | `/api/v1/attendance` | `attendance.self` | |
| `api.v1.attendance.index` | GET | `/api/v1/attendance` | `attendance.self` | ✅ |
| `api.v1.attendance.summary` | GET | `/api/v1/attendance/summary` | `attendance.self` | |
| `api.v1.attendance-corrections.index` | GET | `/api/v1/attendance-corrections` | `attendance_corrections.create` | ✅ (milik sendiri) |
| `api.v1.attendance-corrections.store` | POST | `/api/v1/attendance-corrections` | `attendance_corrections.create` | |
| `api.v1.attendance-corrections.cancel` | POST | `/api/v1/attendance-corrections/{id}/cancel` | pemilik, status `pending` | |
| `api.v1.attendance-corrections.attachment` | GET | `/api/v1/attendance-corrections/{id}/attachment` | pengaju, atau berwenang memutuskan (06 §6, termasuk yang sudah diputuskan) | Biner `attachment`, `no-store`, throttle 60/menit; lainnya / tanpa lampiran → 404 `NOT_FOUND` (keputusan Mas 16 Nov 2026, MOB-09) |
| `api.v1.lookup.correction-reasons` | GET | `/api/v1/lookup/correction-reasons` | `attendance_corrections.create` | `{ data: [{ code, name }] }` — `reference_items` grup `correction_reason` yang aktif, urut `sort_order`. Bukan daftar `*.index` (pendek, tanpa paging, seperti lookup web §4.11) |
| `api.v1.approvals.attendance-corrections.index` | GET | `/api/v1/approvals/attendance-corrections` | `attendance_corrections.approve` | ✅ (scope data) |
| `api.v1.approvals.attendance-corrections.approve` | POST | `/api/v1/approvals/attendance-corrections/{id}/approve` | `attendance_corrections.approve` | |
| `api.v1.approvals.attendance-corrections.reject` | POST | `/api/v1/approvals/attendance-corrections/{id}/reject` | `attendance_corrections.approve` | |
| `api.v1.notifications.index` | GET | `/api/v1/notifications` | token | ✅ |
| `api.v1.notifications.read` | POST | `/api/v1/notifications/{id}/read` | token | |
| `api.v1.notifications.read-all` | POST | `/api/v1/notifications/read-all` | token | |
| `api.v1.field-trips.index` | GET | `/api/v1/field-trips` | `field_trips.request` | ✅ (milik sendiri) |
| `api.v1.field-trips.store` | POST | `/api/v1/field-trips` | `field_trips.request` | Ajukan untuk diri sendiri → `pending` (§5.10) |
| `api.v1.approvals.field-trips.index` | GET | `/api/v1/approvals/field-trips` | `field_trips.approve` | ✅ (scope 06 §6) |
| `api.v1.approvals.field-trips.approve` | POST | `/api/v1/approvals/field-trips/{id}/approve` | `field_trips.approve` | |
| `api.v1.approvals.field-trips.reject` | POST | `/api/v1/approvals/field-trips/{id}/reject` | `field_trips.approve` | |

### 5.2 `GET /api/v1/config`
```json
{
  "data": {
    "app_name": "PAKIS ONE",
    "company_name": "CV Pakis Jaya Abadi",
    "logo_url": "https://pakis.example/storage/branding/logo.png",
    "primary_color": "#2F6B3B",
    "timezone": "Asia/Jakarta",
    "mobile": { "min_version": "1.0.0", "latest_version": "1.0.3", "download_url": "https://pakis.example/unduh/pakis-pro.apk", "update_message": "Versi baru tersedia." },
    "attendance": { "require_selfie": true, "max_accuracy_m": 100, "offline_max_hours": 72, "correction_max_days": 7, "correction_attachment_max_kb": 2048 },
    "auth": { "password_min_length": 10, "forgot_channels": ["email"] }
  }
}
```
Hanya kunci `is_public = true` di registri setting yang boleh muncul di sini (SEC-062).

### 5.3 `POST /api/v1/auth/login`
Request:
```json
{ "login": "agus.s", "password": "••••••••••", "otp": null,
  "device": { "uid": "9f2c...e1", "model": "Samsung SM-A045F", "os_version": "Android 13" } }
```
Respons 200:
```json
{ "data": { "token": "12|Qw3...", "token_type": "Bearer", "expires_at": "2027-02-13T03:00:00+00:00",
  "user": { "id": 31, "name": "Agus Santoso", "username": "agus.s", "email": null, "phone": "6281234567890",
            "has_avatar": false, "must_change_password": false,
            "employee": { "id": 24, "employee_number": "PJA-260024", "position": "Sales", "location": "Kantor Pusat Kalirejo" },
            "permissions": ["attendance.self", "attendance_corrections.create"] },
  "device": { "status": "active" } } }
```
Aturan: perangkat baru ketika pengguna belum punya perangkat aktif dan `mobile.auto_approve_first_device = true` → langsung `active`. Selain itu → buat `pending`, respons `403 DEVICE_PENDING` tanpa token (BR-040). Kalau role butuh MFA dan `otp` kosong → `401 MFA_REQUIRED`.

Catatan implementasi (T-031): `user` yang sama dikembalikan `GET /me`. `permissions` = permission mobile yang dipegang (`attendance.self`, `attendance_corrections.create`, `attendance_corrections.approve`) dan menjadi ability token. Akun tanpa satu pun → `403 FORBIDDEN`. MFA juga diminta bila pengguna mengaktifkannya sukarela; `otp` boleh berisi kode pemulihan. HP yang masih aktif di akun lain tidak pernah auto-approve. Login ulang dari HP `pending` yang sama tidak membuat permintaan baru. Rincian skema: `docs/openapi.yaml`.

### 5.4 `POST /api/v1/attendance` (multipart)

| Field | Tipe | Aturan |
|---|---|---|
| `client_uuid` | string | UUID v4, wajib |
| `type` | string | `in` / `out` |
| `client_time` | string | ISO-8601 dengan offset, mis. `2026-11-03T07:41:12+07:00` |
| `is_offline` | bool | `true` bila dikirim dari antrean |
| `latitude`, `longitude` | decimal | Wajib, rentang Indonesia |
| `accuracy_m` | decimal | Wajib |
| `is_mock` | bool | Dari `Position.isMocked` |
| `selfie` | file | JPEG ≤ 300 KB, wajib bila `attendance.require_selfie` |
| `device_clock_offset_s` | int | Selisih jam perangkat vs server yang terakhir diketahui (dari header `Date` respons sebelumnya) |

Respons 201 (atau 200 + `meta.replayed=true` bila `client_uuid` sudah ada):
```json
{ "data": { "work_date": "2026-11-03", "status": "late", "late_minutes": 7,
  "shift": { "code": "SLS", "name": "Sales", "start": "07:30", "end": "16:30" },
  "check_in_at": "2026-11-03T07:37:02+07:00", "check_out_at": null,
  "location": { "name": "Kantor Pusat Kalirejo", "distance_m": 42 }, "is_offline": false },
  "meta": { "request_id": "01JBX...", "replayed": false } }
```

`client_uuid` yang sudah dipakai kiriman **akun lain** → 409 `STATE_CONFLICT` (tanpa data). Pegawai selalu diambil dari akun pemilik token. Batas laju unggah 10/menit per pengguna (SEC-068).

### 5.4a `GET /api/v1/attendance/today`
Hari kerja "sekarang" untuk beranda Pakis Pro (T-036). Shift malam yang sudah masuk dan belum pulang tetap ditampilkan sebagai hari kerja kemarin sampai jendela pulangnya lewat. `attendance` = bentuk `data` 5.4 (null bila belum absen). `next_action` = `in` / `out` / `null` (tidak ada jadwal, atau jendela pulang sudah lewat).
```json
{ "data": { "work_date": "2026-11-03",
  "schedule": { "kind": "shift", "shift": { "code": "SLS", "name": "Sales", "start": "07:30", "end": "16:30" }, "holiday": null },
  "attendance": null, "next_action": "in" },
  "meta": { "request_id": "01JBX..." } }
```

### 5.5 `GET /api/v1/attendance` (daftar)
Spec listing `my_attendance_days`: filter `month` (`YYYY-MM`, default bulan berjalan), `status` (multi), sort `-work_date` (default) / `work_date`, `per_page` 20 (opsi 20, 50). Pencarian `q` mencari di nama shift.

### 5.6 `GET /api/v1/attendance/summary?month=2026-11`
```json
{ "data": { "month": "2026-11", "scheduled_days": 25, "present": 2, "late": 1, "early_leave": 0,
  "no_checkout": 0, "absent": 0, "holiday": 0, "off": 0, "late_minutes_total": 7, "corrections_pending": 0 } }
```
Definisi angka = BR-072 (sama dengan rekap web, T-043): `present` = hari dengan presensi masuk (`present`/`late`/`early_leave`/`no_checkout`), `scheduled_days` = `present` + `absent` (hanya hari yang sudah berstatus; contoh di atas ilustrasi), `late`/`early_leave` = jumlah hari bermenit > 0, sisanya jumlah hari berstatus itu, `corrections_pending` = koreksi `pending` untuk tanggal di bulan itu. `month` tidak berformat `YYYY-MM` → 422 `VALIDATION_FAILED`. Item §5.5: `id`, `work_date`, `status`, `shift` (`code`/`name`/`start`/`end`), `check_in_at`, `check_out_at`, `late_minutes`, `early_leave_minutes`, `is_offline`, `is_corrected` (tanpa koordinat/selfie). Akun tanpa pegawai → 403 `FORBIDDEN`.

### 5.7 `POST /api/v1/attendance-corrections` (multipart)
`work_date` (date), `type` (`check_in`/`check_out`/`both`), `proposed_check_in` (HH:mm), `proposed_check_out` (HH:mm), `reason_code` (kode `correction_reason`), `note` (10–500 karakter), `attachment` (opsional, JPEG/PNG/PDF ≤ `attendance.correction_attachment_max_kb`, default 2048 KB; isi berkas diperiksa). `proposed_check_in` wajib untuk `check_in`/`both`, `proposed_check_out` untuk `check_out`/`both`, lainnya diabaikan. Respons 201 `{ data: <koreksi §5.7a>, meta }`.

Galat: tanggal di masa depan / lebih tua dari `attendance.correction_max_days` → 422 `CORRECTION_NOT_ALLOWED`; sudah ada `pending` untuk tanggal itu → 409 `CORRECTION_DUPLICATE_PENDING`; jam di luar jendela shift (BR-060) → 422 `VALIDATION_FAILED` pada field jam; akun tanpa data pegawai → 403 `FORBIDDEN`.

`GET /api/v1/attendance-corrections` = listing `my_corrections` (§3, 07 §3.4) milik sendiri. `POST /api/v1/attendance-corrections/{id}/cancel` → 200 objek koreksi; bukan milik sendiri → 404; bukan `pending` → 409 `STATE_CONFLICT`.

### 5.7a Objek koreksi
```json
{ "id": 12, "number": "KOR-202611-0001", "status": "pending", "work_date": "2026-11-16", "type": "check_in",
  "proposed_check_in": "07:55", "proposed_check_out": null,
  "reason": { "code": "forgot", "name": "Lupa absen" }, "note": "Lupa menekan Masuk", "has_attachment": false,
  "employee": { "id": 7, "employee_number": "PJA-260007", "name": "Bayu Saputra" },
  "approver": { "name": "Eko Wibowo" }, "is_manual": false,
  "submitted_at": "2026-11-16T09:12:00+07:00", "decided_at": null, "decided_by": null, "decision_note": null }
```
`approver` = atasan langsung saat diajukan (null bila tidak ada; diputuskan kepala lokasi/HRD, 06 §6). `is_manual` = presensi manual oleh admin/atasan (BR-069). `decided_by` = `{ "name" }`. Waktu ISO-8601 dengan offset `general.timezone`.

### 5.8 Approve / Reject
`GET /api/v1/approvals/attendance-corrections` = listing `approval_corrections` (07 §3.4): koreksi yang boleh diputuskan pengguna (06 §6), tanpa milik sendiri. `POST .../approve` body `{ "note": "opsional, ≤ 500" }`. `POST .../reject` body `{ "note": "wajib, 5–500 karakter" }`. Respons 200 berisi objek koreksi terbaru (§5.7a). Bila status sudah bukan `pending` → `409 STATE_CONFLICT`. Koreksi di luar wewenang → 404; milik sendiri → 403 `CORRECTION_SELF_APPROVAL`.

### 5.10 Tugas luar kota (T-036A bagian 2)
Objek FieldTrip: `{ id, number, status, start_date, end_date, days, destination, purpose|null, attendance_rule (anywhere|destination|any_location), destinations: [{ name, radius_m|null }] (tanpa koordinat), employees: [{ id, employee_number, name }], requested_by: { name }|null, is_request (diajukan dari HP), submitted_at, decided_at|null, decided_by: { name }|null, decision_note|null }`.

`GET /api/v1/field-trips` = listing `my_field_trips` (07 §3.4): tugas yang memuat pegawai pemilik token, buatan atasan maupun pengajuan sendiri. `POST /api/v1/field-trips` JSON `{ start_date, end_date (opsional = start_date), destination (2–150), purpose (≤ 500, opsional), attendance_rule, destinations: [{ name, latitude, longitude, radius_m|null }] (1–10, wajib bila `destination`) }` → 201 FieldTrip `pending`; pegawai = pemilik token (`employee_ids` dari klien diabaikan). Validasi sama dengan form web (batas `attendance.field_trip_max_days`, tanggal selesai belum lewat); tumpang tindih dengan tugas `approved` → 422 di field `start_date`. Pengajuan `pending` tidak membuka lokasi tambahan (BR-071) dan tidak bisa dibatalkan pengaju (06 §7.2a).

`GET /api/v1/approvals/field-trips` = listing `approval_field_trips`: pengajuan dari HP yang boleh diputuskan pengguna (06 §6: atasan langsung pengaju saat ini, atau scope `all`/`location`), tanpa yang memuat dirinya. `POST .../approve` `{ note? ≤ 255 }`, `POST .../reject` `{ note 5–255 }` → 200 FieldTrip. Milik sendiri 403 `FORBIDDEN` (SEC-031), di luar wewenang 404 `NOT_FOUND`, sudah diputuskan atau bentrok tugas `approved` lain → 409 `STATE_CONFLICT` (pesan menyebut tugas yang bentrok). Notifikasi `field_trip_submitted` ke penyetuju (pola BR-070), `field_trip_decided` ke pengaju.

`GET /api/v1/attendance/today` memuat `field_trip: { number, destination, end_date, attendance_rule } | null` = tugas `approved` yang mencakup tanggal kerja (kartu beranda).

### 5.9 Notifikasi
`GET /api/v1/notifications` = listing `my_notifications` (07 §3.4) milik sendiri; item `{ id (uuid), type, title, body, read, created_at }` tanpa tautan web. Jumlah belum dibaca untuk lonceng = `meta.pagination.total` dengan `filter[read]=unread`. `POST /api/v1/notifications/{id}/read` → 200 `{ data: <notifikasi> }` (idempoten); milik orang lain / tidak ada → 404 `NOT_FOUND`. `POST /api/v1/notifications/read-all` → 200 `{ data: { marked: <jumlah> } }`.
