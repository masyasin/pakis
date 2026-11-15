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

Tabel ini dicocokkan dua arah dengan enum `App\Support\Api\ErrorCode` oleh `tests/Contract/ApiErrorFormatTest.php`. Menambah kode berarti mengubah keduanya.

### 2.4 Header

| Header | Arah | Wajib | Keterangan |
|---|---|---|---|
| `Authorization: Bearer <token>` | → | Ya (kecuali login/forgot/config) | Token Sanctum |
| `X-Device-Id` | → | Ya (API) | `device_uid` (hash) yang terdaftar |
| `X-App-Version` | → | Ya (API) | Semver Pakis Pro, mis. `1.0.3` |
| `X-Request-Id` | ↔ | Tidak | Dibuat server bila kosong (ULID), dikembalikan di respons |
| `Accept: application/json` | → | Ya | |

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
| `admin.schedules.calendar` | GET | `/jadwal/kalender` | `schedules.view` | Grid bulan × pegawai, paging per 25 pegawai + filter lokasi/jabatan + cari |
| `admin.schedules.patterns.store` | POST | `/jadwal/pola` | `schedules.manage` | Massal: `employee_ids[]`, `effective_from`, `days{mon..sun}` |
| `admin.schedule-overrides.index` | GET | `/jadwal/pengecualian` | `schedules.view` | ✅ |
| `admin.schedule-overrides.store` | POST | `/jadwal/pengecualian` | `schedules.manage` | Massal per tanggal |
| `admin.schedule-overrides.destroy` | DELETE | `/jadwal/pengecualian/{override}` | `schedules.manage` | |
| `admin.holidays.index` | GET | `/hari-libur` | `holidays.view` | ✅ |
| `admin.holidays.export` | GET | `/hari-libur/ekspor` | `holidays.view` | |
| `admin.holidays.create`/`store`/`edit`/`update`/`destroy` | | `/hari-libur/...` | `holidays.manage` | |

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

### 4.11 Lookup (untuk select/autocomplete)
Satu route `admin.lookup.show`, GET `/lookup/<sumber>?q=&parent=&limit=&ids[]=` (sumber terdaftar di `LookupRegistry`; tak dikenal → 404, tanpa izin sumber → 403, parameter salah → 422, `throttle:120,1`). Mengembalikan `[{value,label,hint?}]`, maksimum 20 hasil (`limit` 1–20, `LIMIT` di SQL), hanya entri aktif dan **dalam scope data** pemanggil. `ids[]` (≤ 20) mengembalikan label nilai yang sudah terpilih (chip filter), tetap aktif & ber-scope. Sumber: `regions` (dengan `level` & `parent`), `locations`, `departments`, `positions`, `employees`, `shifts`, `roles`, `references` (dengan `group`, semua pengguna login), `users` (pemegang `audit.view` atau `users.view`; akun nonaktif ikut tampil untuk menyaring pelaku audit lama — keputusan 15 Nov 2026). Lookup **bukan** daftar `*.index`, jadi dikecualikan dari test kontrak listing. Lookup tetap wajib pencarian + limit (BR-006).

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
| `api.v1.attendance.today` | GET | `/api/v1/attendance/today` | `attendance.self` | |
| `api.v1.attendance.store` | POST | `/api/v1/attendance` | `attendance.self` | |
| `api.v1.attendance.index` | GET | `/api/v1/attendance` | `attendance.self` | ✅ |
| `api.v1.attendance.summary` | GET | `/api/v1/attendance/summary` | `attendance.self` | |
| `api.v1.attendance-corrections.index` | GET | `/api/v1/attendance-corrections` | `attendance_corrections.create` | ✅ (milik sendiri) |
| `api.v1.attendance-corrections.store` | POST | `/api/v1/attendance-corrections` | `attendance_corrections.create` | |
| `api.v1.attendance-corrections.cancel` | POST | `/api/v1/attendance-corrections/{id}/cancel` | pemilik, status `pending` | |
| `api.v1.approvals.attendance-corrections.index` | GET | `/api/v1/approvals/attendance-corrections` | `attendance_corrections.approve` | ✅ (scope data) |
| `api.v1.approvals.attendance-corrections.approve` | POST | `/api/v1/approvals/attendance-corrections/{id}/approve` | `attendance_corrections.approve` | |
| `api.v1.approvals.attendance-corrections.reject` | POST | `/api/v1/approvals/attendance-corrections/{id}/reject` | `attendance_corrections.approve` | |
| `api.v1.notifications.index` | GET | `/api/v1/notifications` | token | ✅ |
| `api.v1.notifications.read` | POST | `/api/v1/notifications/{id}/read` | token | |
| `api.v1.notifications.read-all` | POST | `/api/v1/notifications/read-all` | token | |

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
    "attendance": { "require_selfie": true, "max_accuracy_m": 100, "offline_max_hours": 72, "correction_max_days": 7 },
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

### 5.5 `GET /api/v1/attendance` (daftar)
Spec listing `my_attendance_days`: filter `month` (`YYYY-MM`, default bulan berjalan), `status` (multi), sort `-work_date` (default) / `work_date`, `per_page` 20 (opsi 20, 50). Pencarian `q` mencari di nama shift.

### 5.6 `GET /api/v1/attendance/summary?month=2026-11`
```json
{ "data": { "month": "2026-11", "scheduled_days": 25, "present": 2, "late": 1, "early_leave": 0,
  "no_checkout": 0, "absent": 0, "holiday": 0, "off": 0, "late_minutes_total": 7, "corrections_pending": 0 } }
```

### 5.7 `POST /api/v1/attendance-corrections` (multipart)
`work_date` (date), `type` (`check_in`/`check_out`/`both`), `proposed_check_in` (HH:mm), `proposed_check_out` (HH:mm), `reason_code` (kode `correction_reason`), `note` (10–500 karakter), `attachment` (opsional, JPEG/PNG/PDF ≤ 2 MB). Respons 201 berisi `number`, `status: "pending"`, `approver.name`.

### 5.8 Approve / Reject
`POST .../approve` body `{ "note": "opsional" }`. `POST .../reject` body `{ "note": "wajib, 5–500 karakter" }`. Respons 200 berisi objek koreksi terbaru. Bila status sudah bukan `pending` → `409 STATE_CONFLICT`.
