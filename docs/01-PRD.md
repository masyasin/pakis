# 01 — Product Requirements Document (Fase 0 + 0,5)

## 1. Latar Belakang

CV Pakis Jaya Abadi punya kantor, beberapa gudang, dan toko di Kudus dan sekitarnya. Pegawainya mencakup sales lapangan, driver, staf gudang, kasir toko, admin, dan HRD. Kondisi saat ini (asumsi dari diskusi, diverifikasi di OQ-001):

- Data pegawai, jabatan, dan penempatan tersebar di Excel. Tidak ada satu daftar resmi siapa bekerja di mana dan siapa atasannya.
- Akses aplikasi (kalau ada) tidak dicabut saat pegawai keluar. Ini celah penggelapan.
- Absensi pakai fingerprint di kantor, atau tidak ada sama sekali untuk pegawai lapangan. Rekap dikerjakan manual.

Semua modul PAKIS ONE berikutnya (penjualan, gudang, armada, payroll) membutuhkan data pegawai, lokasi, dan hak akses yang rapi. Karena itu fondasi dibangun duluan. Absensi dipilih sebagai fitur pertama di aplikasi mobile supaya semua pegawai terbiasa memakai Pakis Pro sebelum fitur sales dan driver masuk.

## 2. Tujuan & Ukuran Keberhasilan

| ID | Tujuan | Ukuran (90 hari setelah go-live) |
|---|---|---|
| G-1 | Satu daftar resmi pegawai, jabatan, lokasi, dan atasan | 100% pegawai aktif tercatat dengan penugasan aktif |
| G-2 | Akses hilang seketika saat pegawai keluar | Waktu dari "nonaktifkan pegawai" ke semua sesi/token mati: ≤ 1 menit |
| G-3 | Absensi semua pegawai lewat Pakis Pro | ≥ 95% hari kerja tercatat absen masuk & pulang |
| G-4 | Rekap absensi tanpa kerja manual | HRD mengekspor rekap bulanan ≤ 5 menit |
| G-5 | Parameter bisnis bisa diubah tanpa developer | 0 permintaan deploy untuk ubah parameter setting |

## 3. Persona

| Persona | Contoh | Kebutuhan utama | Perangkat |
|---|---|---|---|
| Owner | Pemilik CV | Lihat kehadiran, atur kebijakan, kendali akses penuh | Laptop + HP |
| Administrator | Admin kantor / IT internal | Kelola akun, role, setting, referensi | Laptop |
| HRD | Staf HRD | Kelola pegawai, penugasan, shift, jadwal, rekap & koreksi absensi | Laptop |
| Kepala Lokasi | Kepala Gudang, Kepala Toko, Supervisor Sales | Pantau kehadiran timnya, setujui koreksi absen | HP + laptop |
| Pegawai | Sales, driver, staf gudang, kasir | Absen cepat walau sinyal jelek, lihat riwayat, ajukan koreksi, ganti password | HP Android (sering kelas bawah) |
| Super Admin | Tim Sintech | Dukungan teknis, akses tercatat dan bisa dicabut owner | Laptop |

## 4. User Story

### 4.1 Akun & Keamanan
- **US-001** Sebagai pengguna, saya login dengan username atau email dan password, di web maupun mobile.
- **US-002** Sebagai pengguna, saya mengganti password sendiri setelah memasukkan password lama.
- **US-003** Sebagai pengguna yang lupa password, saya meminta tautan reset ke email saya. Kalau saya tidak punya email, admin bisa mereset password saya.
- **US-004** Sebagai pengguna yang direset admin, saya wajib membuat password baru saat login pertama.
- **US-005** Sebagai Owner/Administrator, saya wajib memakai MFA (aplikasi authenticator), dan bisa menentukan role mana lagi yang wajib MFA.
- **US-006** Sebagai pengguna, saya melihat daftar sesi/perangkat aktif saya dan bisa mengeluarkan sesi lain.

### 4.2 Pengaturan & Master
- **US-010** Sebagai Administrator, saya mengubah identitas perusahaan, logo, kontak, zona waktu, dan parameter sistem dari layar Pengaturan.
- **US-011** Sebagai Administrator, saya mengatur format penomoran (mis. nomor pegawai `PJA-{YY}{SEQ:4}`).
- **US-012** Sebagai Administrator, saya mengelola daftar referensi (status kerja, tipe lokasi, alasan koreksi, dll.) tanpa developer.
- **US-013** Sebagai Administrator, saya membuat role baru dan mencentang permission-nya di matriks.
- **US-014** Sebagai Owner, saya melihat audit log siapa mengubah apa, kapan, dari IP mana.

### 4.3 Organisasi & Pegawai
- **US-020** Sebagai HRD, saya mengelola lokasi (kantor/gudang/toko) lengkap dengan koordinat dan radius absen.
- **US-021** Sebagai HRD, saya mengelola departemen dan jabatan beserta jabatan atasannya.
- **US-022** Sebagai HRD, saya menambah pegawai. Nomor pegawai dibuat otomatis.
- **US-023** Sebagai HRD, saya menempatkan pegawai ke jabatan, lokasi, dan atasan langsung, dengan tanggal mulai. Riwayat penugasan tetap tersimpan.
- **US-024** Sebagai HRD, saya mengimpor daftar pegawai dari Excel yang formatnya tidak seragam, dengan pemetaan kolom dan validasi per baris.
- **US-025** Sebagai HRD, saya menonaktifkan pegawai yang keluar. Akun, sesi, token, dan perangkatnya langsung tidak berlaku.
- **US-026** Sebagai HRD, saya membuat akun login untuk pegawai langsung dari halaman pegawai.

### 4.4 Absensi
- **US-030** Sebagai HRD, saya membuat shift kerja dan menjadwalkan shift ke pegawai (massal maupun per tanggal).
- **US-031** Sebagai HRD, saya mencatat hari libur.
- **US-032** Sebagai Pegawai, saya absen masuk dan pulang dengan selfie dan lokasi GPS dalam ≤ 3 ketukan.
- **US-033** Sebagai Pegawai di area tanpa sinyal, saya tetap bisa absen. Data terkirim otomatis saat sinyal kembali.
- **US-034** Sebagai Pegawai, saya melihat riwayat dan rekap absen bulan ini.
- **US-035** Sebagai Pegawai yang lupa absen atau GPS-nya bermasalah, saya mengajukan koreksi dengan alasan.
- **US-036** Sebagai Kepala Lokasi, saya menyetujui atau menolak koreksi absen tim saya.
- **US-037** Sebagai HRD, saya melihat rekap harian/bulanan, menyaring per lokasi/departemen/status, melihat foto selfie, dan mengekspor ke Excel.
- **US-038** Sebagai Administrator, saya mendaftarkan atau mereset perangkat HP pegawai (satu akun, satu HP).
- **US-039** Sebagai Owner, saya melihat dashboard kehadiran hari ini.

## 5. Scope

### 5.1 Termasuk
- Web admin (Laravel 13 + Inertia React): semua modul di §4.
- Aplikasi **Pakis Pro** (Flutter, Android): login, ubah/lupa password, wajib ganti password, registrasi perangkat, absen masuk/pulang, antrean offline, riwayat & rekap absen, pengajuan koreksi, persetujuan koreksi untuk atasan, notifikasi dalam aplikasi, profil, paksa update versi.
- API v1 untuk Pakis Pro.
- Import pegawai dari Excel/CSV, export daftar ke Excel di semua daftar yang relevan.
- Master wilayah Indonesia (provinsi s.d. desa) dari data SQL milik Sintech.

### 5.2 Di Luar Scope (paket ini)
- Produk, kios, harga, pesanan, piutang, kas, setoran (Fase 1).
- Gudang, driver, BBM, armada, Pakis Mitra (Fase 2).
- Cuti, izin, lembur, kasbon, payroll, BPJS, PPh 21 (Fase 3). Koreksi absen **bukan** cuti/izin.
- Push notification FCM (OQ-005). Notifikasi Fase 0,5 berupa notifikasi dalam aplikasi (polling) dan email.
- Lupa password lewat WhatsApp OTP. Antarmuka adapter disiapkan, tetapi pengiriman nyata menunggu pemilihan penyedia (OQ-004).
- Aplikasi iOS (OQ-006).
- Multi-tenant / dijual ke distributor lain (ADR-001).
- Pengenalan wajah otomatis (face match). Selfie hanya bukti visual (OQ-007).

## 6. Asumsi

| ID | Asumsi | Diverifikasi di |
|---|---|---|
| A-1 | Jumlah pegawai ≤ 150, lokasi ≤ 15, pengguna web bersamaan ≤ 20 | OQ-001 |
| A-2 | Semua pegawai lapangan punya HP Android 8.0+ dengan kamera depan & GPS | OQ-006 |
| A-3 | Sebagian pegawai tidak punya email. Reset password mereka lewat admin | OQ-004 |
| A-4 | Server produksi VPS di data center Indonesia, bukan shared hosting | `03` §8 |
| A-5 | Zona waktu operasional Asia/Jakarta | Setting `general.timezone` |
