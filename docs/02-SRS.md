# 02 — Software Requirements Specification (Fase 0 + 0,5)

Notasi: **W** = web admin, **M** = Pakis Pro (mobile), **API** = API v1. Permission mengacu ke `06-AUTHORIZATION.md`. Aturan bisnis mengacu ke `08-BUSINESS-RULES.md`.

## 1. Kebutuhan Fungsional

### 1.1 Autentikasi & Akun

| ID | Kebutuhan | Kanal | Rujukan |
|---|---|---|---|
| FR-001 | Login dengan **username atau email** + password. Akun nonaktif atau pegawai nonaktif ditolak dengan pesan generik. | W | BR-010, SEC-001, SEC-003 |
| FR-002 | Login mobile menghasilkan token Sanctum yang terikat ke satu perangkat terdaftar. | M, API | BR-040, SEC-020 |
| FR-003 | Logout. Daftar sesi web & perangkat mobile aktif milik sendiri, dengan aksi "keluarkan sesi lain". | W, M | SEC-005 |
| FR-004 | Ubah password sendiri: wajib password lama, password baru mengikuti kebijakan dinamis, semua sesi lain dikeluarkan. | W, M | BR-011, SEC-002 |
| FR-005 | Lupa password via **email**: tautan sekali pakai, masa berlaku dari setting, respons selalu generik. | W, M | BR-012, SEC-004 |
| FR-006 | Lupa password via **WhatsApp OTP** melalui `OtpChannel` adapter. Paket ini membangun adapter + driver `log`. Driver penyedia nyata menunggu OQ-004. Kanal aktif diatur di setting. | W, M | BR-013, OQ-004 |
| FR-007 | Reset password oleh admin: sistem membuat password sementara (ditampilkan sekali), menandai `must_change_password`, dan mengeluarkan semua sesi/token pengguna itu. | W | BR-014, SEC-006 |
| FR-008 | MFA TOTP + 8 kode pemulihan. Wajib bagi role dengan `mfa_required = true` (diatur di halaman Role). Berlaku untuk login web dan mobile. | W, M | BR-015, SEC-007 |
| FR-009 | Rate limit login & lupa password, penguncian sementara, notifikasi email login dari perangkat baru. Semua angka dari setting `security.*`. | W, M | BR-016, SEC-003 |
| FR-010 | Profil saya: nama tampilan, nomor HP, foto, email (perubahan email wajib verifikasi). | W, M | — |

### 1.2 Pengaturan Dinamis

| ID | Kebutuhan | Kanal | Rujukan |
|---|---|---|---|
| FR-011 | **Site setting** berbasis registri: setiap kunci punya tipe, default, validasi, label, grup, dan penanda publik. Form dibuat otomatis dari registri. Nilai di-cache dan setiap perubahan masuk audit. | W | BR-020–BR-024 |
| FR-012 | Branding: nama aplikasi, logo terang/gelap, favicon, warna primer. Dipakai di layout web, halaman login, email, dan Pakis Pro (via konfigurasi publik). | W, M | BR-021 |
| FR-013 | Penomoran dokumen dinamis: pola token (`{PREFIX}`, `{YYYY}`, `{YY}`, `{MM}`, `{DD}`, `{LOC}`, `{SEQ:n}`), periode reset (tidak pernah/tahunan/bulanan/harian). Nomor selalu dibuat server, aman dari tabrakan. | W | BR-025, SEC-030 |
| FR-014 | Referensi dinamis (`reference_items`) per grup: tambah/ubah/urutkan/nonaktifkan. Item yang sudah dipakai tidak bisa dihapus, hanya dinonaktifkan. | W | BR-026 |
| FR-015 | Pengaturan versi aplikasi mobile: versi minimum (di bawahnya wajib update), versi terbaru, URL unduh APK, pesan update. | W, M | BR-027 |

### 1.3 Akses & Audit

| ID | Kebutuhan | Kanal | Rujukan |
|---|---|---|---|
| FR-016 | Manajemen pengguna: buat/ubah, tautkan ke pegawai, set role, aktif/nonaktif, reset password, reset MFA, lihat sesi & perangkat. | W | BR-014 |
| FR-017 | Role dinamis: buat/ubah/hapus role non-sistem, centang permission di matriks per modul, set `data_scope` dan `mfa_required`. Permission dibuat dari kode (seeder), bukan dari UI. | W | BR-030, BR-031 |
| FR-018 | Scope data per role: `all`, `location`, `team`, `self`. Diterapkan di daftar pegawai, penugasan, absensi, koreksi, perangkat. | W, API | BR-032 |
| FR-019 | Audit log: siapa, kapan, aksi, objek, nilai lama/baru (field sensitif ditutup), IP, user agent. Hanya bisa dibaca, tidak bisa diubah dari aplikasi. | W | SEC-040 |
| FR-020 | Log keamanan: login berhasil/gagal, lockout, reset password, MFA aktif/nonaktif, perubahan role, sesi dicabut. | W | SEC-041 |

### 1.4 Organisasi & Pegawai

| ID | Kebutuhan | Kanal | Rujukan |
|---|---|---|---|
| FR-021 | Master wilayah Indonesia (provinsi, kabupaten/kota, kecamatan, desa/kelurahan) diimpor dari SQL Sintech lewat command. Hanya-baca di UI, dengan pencarian & filter berjenjang. | W | OQ-003 |
| FR-022 | Lokasi: kode, nama, tipe (referensi `location_type`: kantor/gudang/toko/lainnya), alamat, desa, koordinat, radius absen (m), status aktif, kepala lokasi. | W | BR-041 |
| FR-023 | Departemen: kode, nama, induk (opsional), aktif. | W | — |
| FR-024 | Jabatan: kode, nama, departemen, jabatan atasan, level, mode absen default (`location` = lokasi penugasan, `any_location` = lokasi Pakis mana pun, `anywhere` = di mana saja dengan GPS), aktif. | W | BR-042 |
| FR-025 | Pegawai: nomor pegawai otomatis (FR-013), nama, NIK (terenkripsi, opsional), jenis kelamin, tanggal lahir, HP, email, alamat, foto, status kerja (referensi `employment_status`), tanggal masuk, tanggal keluar, status. | W | BR-050, SEC-010 |
| FR-026 | Penugasan: pegawai → jabatan + lokasi + atasan langsung + mode absen (override) + tanggal mulai/selesai. Maksimal satu penugasan utama aktif. Mutasi = tutup penugasan lama + buat baru, riwayat tidak dihapus. | W | BR-051, BR-052 |
| FR-027 | Offboarding: tanggal keluar + alasan → penugasan ditutup, akun nonaktif, semua sesi web, token API, dan perangkat dicabut dalam satu transaksi. | W | BR-053, SEC-021 |
| FR-028 | Import pegawai dari .xlsx/.csv: unggah → deteksi header → pemetaan kolom (profil bisa disimpan) → validasi per baris → pratinjau error per sel → konfirmasi → proses antrean → laporan. Idempoten berdasarkan hash file dan kunci baris. | W | BR-054, SEC-050 |
| FR-029 | Export daftar ke .xlsx sesuai filter yang sedang aktif. Di atas ambang (setting) diproses di antrean dan tautan unduh dikirim lewat notifikasi. | W | BR-005, SEC-051 |
| FR-030 | Notifikasi dalam aplikasi (lonceng web, daftar di mobile) + email untuk peristiwa tertentu. | W, M | BR-070 |

### 1.5 Absensi

| ID | Kebutuhan | Kanal | Rujukan |
|---|---|---|---|
| FR-031 | Shift: kode, nama, jam masuk, jam pulang, lintas hari, toleransi telat (menit), batas awal absen masuk (menit sebelum jam masuk), batas akhir absen pulang (menit setelah jam pulang), aktif. | W | BR-060 |
| FR-032 | Jadwal: pola mingguan per pegawai (shift per hari Senin–Minggu, atau libur) + override per tanggal. Penetapan massal per lokasi/jabatan/pegawai terpilih. | W | BR-061 |
| FR-033 | Hari libur: tanggal, nama, berlaku untuk semua atau lokasi tertentu. | W | BR-062 |
| FR-034 | Perangkat: registrasi otomatis saat login pertama (bila setting mengizinkan) atau menunggu persetujuan; permintaan ganti HP; admin menyetujui/mereset. Satu pengguna, satu perangkat aktif. | W, M | BR-040, SEC-020 |
| FR-035 | Absen masuk/pulang: selfie dari kamera (bukan galeri), koordinat + akurasi, penanda lokasi palsu, ID perangkat, UUID klien, waktu perangkat. Server memakai **waktu server** (kecuali offline, lihat FR-036) dan memvalidasi radius sesuai mode absen. | M, API | BR-063–BR-066, SEC-022, SEC-023 |
| FR-036 | Antrean offline: absen disimpan terenkripsi di HP dan dikirim otomatis saat online. Server menerima dengan waktu perangkat bila selisih ≤ batas setting, menandai `is_offline`, dan menolak bila melewati batas. | M, API | BR-067 |
| FR-037 | Penentuan status harian otomatis (job terjadwal + saat absen) ke `attendance_days.status`: `present`, `late`, `early_leave`, `no_checkout`, `absent`, `holiday`, `off`. Penanda tambahan: `is_corrected`, `is_offline`, `has_anomaly`. | W | BR-068 |
| FR-038 | Koreksi absen: pegawai mengajukan (tanggal, jenis, jam usulan, alasan dari referensi `correction_reason`, catatan, lampiran opsional). Atasan langsung atau pemegang `attendance_corrections.approve` dalam scope menyetujui/menolak (tolak wajib alasan). Disetujui → absensi hari itu diperbarui dan ditandai `is_corrected`. | W, M, API | BR-069, SEC-031 |
| FR-039 | Daftar & rekap absensi web: harian (per baris pegawai-tanggal) dan bulanan (per pegawai). Filter, foto selfie (permission), peta titik absen, export. | W | BR-001–BR-006 |
| FR-040 | Dashboard: kehadiran hari ini (hadir, telat, belum absen, libur), koreksi menunggu, perangkat menunggu. | W | — |
| FR-041 | Mobile: status hari ini, riwayat absen (paging + filter bulan/status), rekap bulan, daftar koreksi saya, persetujuan untuk atasan. | M, API | BR-003 |
| FR-042 | Konfigurasi publik untuk mobile: branding, setting absensi yang relevan, versi minimum. Aplikasi menolak jalan bila versinya di bawah minimum. | M, API | BR-027 |

## 2. Kebutuhan Non-Fungsional

| ID | Kategori | Target |
|---|---|---|
| NFR-001 | Performa web | p95 < 500 ms untuk halaman daftar dengan 200.000 baris absensi (paging server, index sesuai `04`). |
| NFR-002 | Performa API | p95 < 800 ms untuk API selain unggah. Unggah absen p95 < 2 s pada 4G. |
| NFR-003 | Kapasitas | ≤ 150 pegawai, ≤ 15 lokasi, ≤ 20 pengguna web bersamaan, ±90.000 baris absensi/tahun, proyeksi 3 tahun ±300.000 baris. |
| NFR-004 | Penyimpanan selfie | Dikompresi di HP ke ≤ 100 KB (JPEG, sisi terpanjang 720 px). ±9 GB/tahun. Dihapus otomatis setelah `attendance.selfie_retention_days` (default 365). |
| NFR-005 | Ketersediaan | 99,5% pada jam operasional 06.00–21.00 WIB. Pemeliharaan terjadwal di luar jam itu. |
| NFR-006 | Backup | DB harian + binlog (RPO ≤ 1 jam), storage harian. Terenkripsi, salinan di luar server utama, retensi 30 hari harian + 12 bulanan. RTO ≤ 4 jam. Uji restore tiap bulan. |
| NFR-007 | Offline | Pakis Pro menyimpan minimal 50 absen tertunda dan menyinkronkan otomatis ≤ 1 menit setelah koneksi kembali. |
| NFR-008 | Kompatibilitas | Android 8.0+ (API 26). Web: Chrome, Edge, Firefox 2 versi terakhir. Lebar 360 px s.d. 1920 px. |
| NFR-009 | UX & aksesibilitas | WCAG 2.2 AA, tema terang & gelap, Bahasa Indonesia, format tanggal & angka Indonesia, zona waktu dari setting. |
| NFR-010 | Standar daftar | **Setiap** halaman daftar dan endpoint daftar API wajib paging, pencarian, filter, sort, dan state di URL (BR-001–BR-006). Ditegakkan oleh test kontrak (`10` §3). |
| NFR-011 | Observability | Log JSON + `request_id`, endpoint `/up`, metrik antrean & job gagal, alert ke email/WA admin (penerima di setting `monitoring.alert_emails`). |
| NFR-012 | Maintainability | Larastan level 6, Pint, ESLint, `flutter analyze` bersih. Logika bisnis di Actions dengan test. |
| NFR-013 | Retensi | Audit log 5 tahun, log keamanan 1 tahun, selfie sesuai setting, berkas import 90 hari, berkas export 7 hari. |
| NFR-014 | Mobile | Ukuran APK < 40 MB, cold start < 3 s di HP RAM 3 GB, absen online selesai ≤ 3 ketukan. |
