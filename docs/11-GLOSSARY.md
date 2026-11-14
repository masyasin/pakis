# 11 — Glosarium

| Istilah | Arti di PAKIS ONE |
|---|---|
| Pakis / Pakis Jaya Abadi | CV Pakis Jaya Abadi, klien dan satu-satunya organisasi pengguna (single-tenant) |
| PAKIS ONE | Nama sistem keseluruhan (web admin + aplikasi mobile) |
| Pakis Pro | Aplikasi mobile Flutter untuk pegawai (Fase 0,5: absensi; fase berikutnya: sales, driver, gudang) |
| Pakis Mitra | Aplikasi untuk kios/pelanggan (Fase 2, di luar paket ini) |
| Lokasi | Tempat kerja fisik: kantor, gudang, toko |
| Departemen | Unit kerja (Penjualan, Gudang & Logistik, dst.) |
| Jabatan | Posisi dalam struktur (Sales, Kepala Gudang, dst.), punya mode absen default |
| Penugasan | Penempatan pegawai pada jabatan + lokasi + atasan untuk periode tertentu. Riwayat tidak dihapus |
| Penugasan utama | Satu penugasan aktif yang dipakai untuk absensi dan snapshot. Penugasan tambahan = rangkap |
| Mutasi | Mengganti penugasan utama: menutup yang lama, membuat yang baru |
| Offboarding / Nonaktifkan pegawai | Proses pegawai keluar: akses dicabut seketika, riwayat tetap ada |
| Atasan langsung | `supervisor_employee_id` pada penugasan utama, penyetuju koreksi |
| Scope data | Batas data yang terlihat oleh role: semua / lokasi / tim / diri sendiri |
| Mode absen | `location` (di lokasi penugasan), `any_location` (di lokasi Pakis mana pun), `anywhere` (di mana saja, lokasi tetap dicatat) |
| Radius | Jarak maksimal (meter) dari titik lokasi agar absen sah |
| Shift | Jam kerja dengan toleransi telat dan jendela absen |
| Pola jadwal | Shift per hari Senin–Minggu untuk seorang pegawai, berlaku sejak tanggal tertentu |
| Pengecualian jadwal (override) | Shift khusus atau libur untuk satu pegawai pada satu tanggal |
| Hari kerja (`work_date`) | Tanggal shift yang dimiliki sebuah absen. Untuk shift lintas tengah malam, absen pulang setelah 00.00 tetap milik tanggal mulai shift |
| Log absen | Satu kali ketuk absen (masuk/pulang) beserta foto & GPS |
| Absensi harian | Ringkasan per pegawai per tanggal: jadwal, jam masuk/pulang, status |
| Koreksi absen | Pengajuan perubahan jam absen karena lupa/gangguan, disetujui atasan |
| Absen offline | Absen yang disimpan di HP saat tidak ada sinyal dan dikirim belakangan |
| Lokasi palsu (mock location) | Koordinat dari aplikasi pemalsu GPS, ditolak sistem |
| Perangkat terdaftar | HP yang terikat ke satu akun. Satu akun satu HP |
| Site setting | Parameter sistem yang bisa diubah admin dari layar tanpa developer |
| Registri setting | Daftar resmi kunci setting beserta tipe, default, dan validasinya (di kode) |
| Penomoran | Pola nomor dokumen otomatis (nomor pegawai, nomor koreksi, kelak kwitansi) |
| Referensi | Daftar pilihan dinamis (tipe lokasi, status kerja, alasan koreksi, alasan keluar) |
| Listing / daftar | Halaman/endpoint yang menampilkan banyak baris, wajib paging, pencarian, filter, sort |
| MFA | Verifikasi dua langkah memakai aplikasi authenticator (kode 6 digit) |
| Maker-checker | Pembuat tidak boleh menyetujui buatannya sendiri |
| IDOR | Celah mengakses data orang lain dengan mengganti ID di URL/API |
| UU PDP | UU Nomor 27 Tahun 2022 tentang Pelindungan Data Pribadi |
| OQ | Open Question, keputusan yang belum final (`12-OPEN-QUESTIONS.md`) |
| ADR | Architecture Decision Record (`03` §6) |
