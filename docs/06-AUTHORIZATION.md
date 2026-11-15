# 06 — Otorisasi, Role, Scope & Menu (Fase 0 + 0,5)

Model: **RBAC dinamis** (Spatie) + **scope data per role** + **Policy per resource**. Permission format `modul.aksi`. Role adalah kumpulan permission yang bisa diatur Administrator dari layar. Permission sendiri hanya dibuat oleh seeder dari dokumen ini (ADR-011).

## 1. Prinsip

1. **Least privilege.** Role baru dibuat tanpa permission.
2. **Permission ≠ scope.** Permission menentukan *boleh melakukan apa*, sedangkan `data_scope` menentukan *terhadap data siapa*.
3. **Cek di backend per resource.** Menyembunyikan menu hanya untuk UX. Data di luar scope menghasilkan **404**, bukan 403 (SEC-033).
4. **Maker-checker.** Tidak ada yang bisa menyetujui koreksi absennya sendiri (SEC-031).
5. **Super Admin (Sintech) terpisah dari Owner.** Super Admin hanya untuk dukungan teknis. Owner bisa menonaktifkan akun Super Admin kapan saja, dan semua aksinya tercatat (SEC-009).
6. **Aksi sensitif butuh konfirmasi ulang password** (`password.confirm`, berlaku `security.password_confirm_minutes`): reset password pengguna, reset MFA, ubah role/permission, offboarding, ubah setting grup `security`, export audit.

## 2. Role Bawaan

| Kode | Nama | `data_scope` | `mfa_required` | `is_system` | Pemegang contoh |
|---|---|---|---|---|---|
| `super_admin` | Super Admin | `all` | ✅ | ✅ | Tim Sintech |
| `owner` | Owner | `all` | ✅ | ✅ | Pemilik CV |
| `administrator` | Administrator | `all` | ✅ | ❌ | Admin kantor / IT internal |
| `hrd` | HRD | `all` | ✅ | ❌ | Staf HRD |
| `kepala_lokasi` | Kepala Lokasi | `location` | ❌ | ❌ | Kepala Gudang, Kepala Toko |
| `supervisor` | Supervisor | `team` | ❌ | ❌ | Supervisor Sales |
| `pegawai` | Pegawai | `self` | ❌ | ✅ | Sales, driver, staf gudang, kasir |

`super_admin` otomatis lolos semua permission lewat `Gate::before`, **kecuali** satu hal: Super Admin tidak bisa mengubah role/permission akun Owner. Role `is_system` tidak bisa dihapus atau diganti kodenya, tetapi permission-nya tetap bisa diubah, kecuali `super_admin` dan `owner` yang terkunci penuh. Satu pengguna bisa memegang beberapa role. Scope efektif = scope **terluas** dari role-role itu, dan MFA wajib bila salah satu role mewajibkan.

## 3. Daftar Permission (50)

| Modul | Permission | Arti |
|---|---|---|
| Dasbor | `dashboard.view` | Melihat dasbor |
| Pengaturan | `settings.view`, `settings.update` | Melihat / mengubah site setting |
| Penomoran | `sequences.view`, `sequences.update` | Melihat / mengubah pola penomoran |
| Referensi | `references.view`, `references.manage` | Melihat / mengelola daftar referensi |
| Pengguna | `users.view`, `users.create`, `users.update`, `users.deactivate`, `users.reset_password`, `users.reset_mfa`, `users.export` | Kelola akun |
| Role | `roles.view`, `roles.manage` | Melihat / mengelola role & matriks |
| Audit | `audit.view`, `audit.export` | Audit log & log keamanan |
| Wilayah | `regions.view` | Melihat master wilayah |
| Lokasi | `locations.view`, `locations.manage` | |
| Departemen | `departments.view`, `departments.manage` | |
| Jabatan | `positions.view`, `positions.manage` | |
| Pegawai | `employees.view`, `employees.create`, `employees.update`, `employees.delete`, `employees.view_sensitive`, `employees.offboard`, `employees.import`, `employees.export` | `view_sensitive` = buka NIK lengkap |
| Penugasan | `assignments.view`, `assignments.manage` | |
| Perangkat | `devices.view`, `devices.manage` | Setujui/tolak/cabut HP |
| Shift | `shifts.view`, `shifts.manage` | |
| Jadwal | `schedules.view`, `schedules.manage` | Pola & pengecualian |
| Hari libur | `holidays.view`, `holidays.manage` | |
| Absensi | `attendance.view`, `attendance.view_photo`, `attendance.export`, `attendance.self` | `self` = absen & lihat milik sendiri via mobile |
| Koreksi absen | `attendance_corrections.view`, `attendance_corrections.create`, `attendance_corrections.approve` | `create` = mengajukan milik sendiri |

## 4. Matriks Kewenangan (default seeder)

✅ penuh · 🔸 terbatas oleh scope role · ❌ tidak. `super_admin` = semua ✅ (tidak ditampilkan).

| Permission | owner | administrator | hrd | kepala_lokasi | supervisor | pegawai |
|---|---|---|---|---|---|---|
| `dashboard.view` | ✅ | ✅ | ✅ | 🔸 | 🔸 | ❌ |
| `settings.view` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `settings.update` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `sequences.view` / `sequences.update` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `references.view` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `references.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `users.view` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `users.create` / `users.update` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `users.deactivate` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `users.reset_password` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `users.reset_mfa` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `users.export` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `roles.view` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `roles.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `audit.view` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `audit.export` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `regions.view` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `locations.view` | ✅ | ✅ | ✅ | 🔸 | ❌ | ❌ |
| `locations.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `departments.view` / `positions.view` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `departments.manage` / `positions.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `employees.view` | ✅ | ✅ | ✅ | 🔸 | 🔸 | ❌ |
| `employees.create` / `employees.update` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `employees.delete` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `employees.view_sensitive` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `employees.offboard` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `employees.import` / `employees.export` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `assignments.view` | ✅ | ✅ | ✅ | 🔸 | 🔸 | ❌ |
| `assignments.manage` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `devices.view` | ✅ | ✅ | ✅ | 🔸 | ❌ | ❌ |
| `devices.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `shifts.view` | ✅ | ✅ | ✅ | 🔸 | ❌ | ❌ |
| `shifts.manage` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `schedules.view` | ✅ | ✅ | ✅ | 🔸 | 🔸 | ❌ |
| `schedules.manage` | ✅ | ❌ | ✅ | 🔸 | ❌ | ❌ |
| `holidays.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| `holidays.manage` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `attendance.view` | ✅ | ✅ | ✅ | 🔸 | 🔸 | ❌ |
| `attendance.view_photo` | ✅ | ❌ | ✅ | 🔸 | 🔸 | ❌ |
| `attendance.export` | ✅ | ❌ | ✅ | 🔸 | ❌ | ❌ |
| `attendance.self` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `attendance_corrections.view` | ✅ | ✅ | ✅ | 🔸 | 🔸 | ❌ |
| `attendance_corrections.create` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `attendance_corrections.approve` | ✅ | ❌ | ✅ | 🔸 | 🔸 | ❌ |

Catatan: `administrator` sengaja **tidak** bisa membuka NIK, mengubah data pegawai, atau melihat foto. Peran ini mengurus sistem, bukan data SDM. Pemisahan ini mengurangi risiko orang dalam (threat T-05, `09` §2).

## 5. Scope Data

| `data_scope` | Pegawai yang terlihat | Diterapkan pada |
|---|---|---|
| `all` | Semua | — |
| `location` | Pegawai yang penugasan utama aktifnya di **lokasi pengguna**: (a) lokasi penugasan utama pengguna, dan (b) lokasi yang `head_employee_id` = pegawai pengguna. Untuk `attendance_days` dipakai snapshot `attendance_days.location_id`. | employees, assignments, devices (lewat user→employee), schedules, attendance_days, attendance_corrections, locations (hanya lokasinya), shifts (hanya yang dipakai pegawai di lokasinya), users (lewat `users.employee_id`; akun tanpa pegawai hanya terlihat scope `all`, keputusan Mas 15 Nov 2026) |
| `team` | Diri sendiri + bawahan langsung & tidak langsung lewat `assignments.supervisor_employee_id` (penugasan aktif, CTE rekursif maks 5 tingkat) | sama dengan baris atas |
| `self` | Diri sendiri | API mobile milik sendiri |

Implementasi: `App\Domain\Access\DataScope::apply(Builder $q, User $u, string $employeeColumn)` dipanggil oleh setiap `*Listing` dan setiap Policy `view/update`; `DataScope::allows($u, $employeeId)` untuk Policy. Pengguna tanpa pegawai (mis. Super Admin) dengan scope selain `all` tidak melihat data apa pun. Struktur organisasi dibaca lewat `OrgStructure` (`DatabaseOrgStructure`: penugasan aktif = `start_date ≤ hari ini` dan `end_date` kosong/≥ hari ini, tanggal lokal). `team` dihitung per tingkat (≤ 5 query, pengaman struktur melingkar) — hasil sama dengan CTE rekursif 5 tingkat (T-019).

## 6. Aturan Persetujuan Koreksi (BR-069)

Pengguna **U** boleh memutuskan koreksi **K** bila semua syarat terpenuhi:
1. `K.status = pending`.
2. `U` punya `attendance_corrections.approve`.
3. `K.employee_id ≠ U.employee_id` (tidak menyetujui milik sendiri).
4. Salah satu dari: `U.employee_id = K.approver_employee_id` (atasan langsung saat diajukan), **atau** pegawai `K` masuk scope `U` dan scope `U` adalah `all` atau `location`.

Bila atasan langsung tidak ada atau nonaktif, koreksi tetap bisa diputuskan oleh `kepala_lokasi` di lokasi itu atau HRD (fallback lewat syarat 4b).

## 7. Transisi Status

### 7.1 Koreksi absen

| Dari | Aksi | Role berwenang | Ke |
|---|---|---|---|
| — | Ajukan | Pegawai sendiri (`attendance_corrections.create`) | `pending` |
| `pending` | Batalkan | Pengaju | `cancelled` |
| `pending` | Setujui | Sesuai §6 | `approved` |
| `pending` | Tolak (alasan wajib) | Sesuai §6 | `rejected` |
| `approved`/`rejected`/`cancelled` | — | — | final |

```mermaid
stateDiagram-v2
  [*] --> pending: Ajukan
  pending --> cancelled: Batalkan (pengaju)
  pending --> approved: Setujui (atasan/kepala lokasi/HRD)
  pending --> rejected: Tolak + alasan
  approved --> [*]
  rejected --> [*]
  cancelled --> [*]
```

### 7.2 Perangkat

| Dari | Aksi | Oleh | Ke |
|---|---|---|---|
| — | Login pertama (auto-approve aktif & belum punya perangkat) | sistem | `active` |
| — | Login dari HP baru | sistem | `pending` |
| `pending` | Setujui | `devices.manage` | `active` (perangkat aktif lama → `replaced`, token lama dicabut) |
| `pending` | Tolak (alasan) | `devices.manage` | `rejected` |
| `active` | Cabut | `devices.manage`, atau offboarding | `revoked` |

### 7.3 Pegawai
`active` → **Nonaktifkan** (`employees.offboard`) → `inactive` → **Aktifkan kembali** (`employees.offboard`, wajib penugasan baru) → `active`.

### 7.4 Batch import
`uploaded` → (pemetaan disimpan) `mapped` → (validasi selesai) `validated` → (proses) `processing` → `completed` / `failed`. Dari `uploaded`/`mapped`/`validated` bisa ke `cancelled`.

## 8. Matriks Menu (web)

Menu tampil bila pengguna punya permission pada kolom "Syarat".

| Grup | Menu | Syarat | owner | admin | hrd | kepala_lokasi | supervisor |
|---|---|---|---|---|---|---|---|
| — | Dasbor | `dashboard.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| Absensi | Absensi Harian | `attendance.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| Absensi | Rekap Bulanan | `attendance.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| Absensi | Koreksi Absen | `attendance_corrections.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| Absensi | Jadwal | `schedules.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| Absensi | Shift | `shifts.view` | ✅ | ✅ | ✅ | ✅ | ❌ |
| Absensi | Hari Libur | `holidays.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| Kepegawaian | Pegawai | `employees.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| Kepegawaian | Penugasan | `assignments.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| Kepegawaian | Impor Pegawai | `employees.import` | ✅ | ❌ | ✅ | ❌ | ❌ |
| Organisasi | Lokasi | `locations.view` | ✅ | ✅ | ✅ | ✅ | ❌ |
| Organisasi | Departemen | `departments.view` | ✅ | ✅ | ✅ | ❌ | ❌ |
| Organisasi | Jabatan | `positions.view` | ✅ | ✅ | ✅ | ❌ | ❌ |
| Organisasi | Wilayah | `regions.view` | ✅ | ✅ | ✅ | ❌ | ❌ |
| Akses | Pengguna | `users.view` | ✅ | ✅ | ✅ | ❌ | ❌ |
| Akses | Role & Hak Akses | `roles.view` | ✅ | ✅ | ❌ | ❌ | ❌ |
| Akses | Perangkat | `devices.view` | ✅ | ✅ | ✅ | ✅ | ❌ |
| Sistem | Pengaturan | `settings.view` | ✅ | ✅ | ❌ | ❌ | ❌ |
| Sistem | Penomoran | `sequences.view` | ✅ | ✅ | ❌ | ❌ | ❌ |
| Sistem | Referensi | `references.view` | ✅ | ✅ | ✅ | ❌ | ❌ |
| Sistem | Audit Log | `audit.view` | ✅ | ✅ | ❌ | ❌ | ❌ |
| Sistem | Log Keamanan | `audit.view` | ✅ | ✅ | ❌ | ❌ | ❌ |
| Header | Notifikasi, Unduhan, Profil | auth | ✅ | ✅ | ✅ | ✅ | ✅ |

Role `pegawai` saja **tidak** bisa masuk web admin. Login web ditolak dengan pesan "Akun Anda hanya untuk aplikasi Pakis Pro" bila pengguna tidak punya satu pun permission yang membuka menu di atas (BR-017).

## 9. Menu Pakis Pro (mobile)

| Tab/Layar | Syarat |
|---|---|
| Absen (beranda) | `attendance.self` |
| Riwayat & Rekap | `attendance.self` |
| Koreksi Saya | `attendance_corrections.create` |
| Persetujuan | `attendance_corrections.approve` (tab muncul hanya bila punya) |
| Notifikasi | auth |
| Profil (ubah password, keluar sesi lain, info perangkat) | auth |

## 10. Implementasi

- Permission dicek lewat Policy (`EmployeePolicy`, `AttendanceDayPolicy`, `AttendanceCorrectionPolicy`, dst.). Controller memakai atribut `#[Authorize]` (Laravel 13) atau `$this->authorize()`.
- Route-model binding untuk resource ber-scope memakai trait `App\Support\Authorization\ScopedRouteBinding` (model menyebut `scopedEmployeeColumn()`), sehingga ID di luar scope menghasilkan 404. Lookup ber-scope cukup mengisi `LookupSource::scopeColumn()`.
- Super Admin lolos semua permission lewat `Gate::before`, **kecuali** ability akun yang dilindungi Owner (`users.manage-account`, `users.assign-roles`, `users.reset-password-of`, `users.reset-mfa-of`, `UserPolicy`): bukan akun sendiri, akun Owner hanya oleh Owner (SEC-009), dan **hak target ⊆ hak pelaku** — semua permission & scope akun target harus dimiliki pelaku (T-020, keputusan Mas 15 Nov 2026). Akibatnya dengan matriks default: Administrator hanya bisa mengelola akun ber-role Pegawai (dan Administrator lain), HRD mengelola Kepala Lokasi/Supervisor/Pegawai, akun HRD & Administrator dikelola Owner.
- Penetapan role (T-020, `AssignUserRoles`): role yang ditambahkan/dilepas harus seluruh permission-nya dimiliki pelaku dan scope-nya tidak lebih luas; role `owner`/`super_admin` hanya oleh Owner.
- Gerbang web BR-017 (`RequireWebMenuAccess`) berjalan sebelum route binding ber-scope (prioritas middleware), sehingga akun tanpa menu web selalu dikeluarkan, bukan menerima 404.
- Test: `tests/Feature/Authorization/PermissionMatrixTest` (role × permission, route perwakilan per modul) dan trait `Tests\Support\AssertsIdor` + `FakeOrgStructure` untuk test IDOR modul.
- `HandleInertiaRequests` membagikan `auth.permissions` (array) dan `auth.menu` (sudah difilter server) ke frontend.
- Perubahan role/permission/penugasan → `security_events` (`role_changed`/`permission_changed`) + `audit_logs`, dan cache permission Spatie dibersihkan.

## 11. Edge Case Otorisasi

| Kasus | Perilaku |
|---|---|
| Pegawai merangkap dua role (mis. `kepala_lokasi` + `supervisor`) | Permission = gabungan. Scope = terluas (`location` > `team`). |
| Atasan dimutasi saat koreksi masih `pending` | `approver_employee_id` tetap atasan lama. Atasan baru bisa memutuskan lewat §6 syarat 4b bila scope-nya cocok. Atasan lama kehilangan akses bila pegawai keluar scope-nya, dan HRD tetap bisa. |
| Atasan langsung nonaktif / tidak ada | Fallback ke kepala lokasi / HRD (§6). Dasbor HRD menampilkan "koreksi tanpa atasan". |
| Lokasi dilebur | Nonaktifkan lokasi lama, mutasi massal penugasan ke lokasi baru (tanggal efektif). Snapshot `attendance_days.location_id` lama tidak berubah. |
| Owner tidak sengaja mencabut `roles.manage` dari semua role | Role `owner` dikunci penuh (`is_system`, permission tidak bisa dikurangi), jadi Owner selalu bisa memulihkan. |
| Super Admin mengubah Owner | Ditolak (§2). |
