# 12 — Pertanyaan Terbuka & Asumsi

Agent **tidak boleh** menebak jawaban OQ. Task yang terblokir harus menunggu, atau dikerjakan dengan asumsi di kolom "Asumsi sementara" bila task tidak tercantum di kolom "Task terblokir".

| ID | Pertanyaan | Dampak | Asumsi sementara | Task terblokir | Penanggung jawab |
|---|---|---|---|---|---|
| OQ-001 | Berapa jumlah pegawai, lokasi, dan pengguna web Pakis saat ini? Boleh minta contoh format Excel daftar pegawai (data disamarkan)? | Kapasitas, sinonim header import | ≤ 150 pegawai, ≤ 15 lokasi. Sinonim umum | — | Mas → Pakis |
| OQ-002 | Penyedia VPS Indonesia, domain produksi, lokasi backup di luar server, kontak darurat & SLA dukungan Sintech | Deploy produksi, backup, playbook insiden | Staging di Hetzner Sintech dengan data dummy | T-046, T-047 | Mas + Owner Pakis |
| OQ-003 | ~~Struktur SQL master wilayah milik Sintech~~ **Terjawab 14 Nov 2026.** Sumber: `konsep/region.sql` — 4 tabel `regionprovince(id,name)` 38 baris, `regioncity(id,provinceId,name)` 514, `regiondistrict(id,cityId,name)` 7.265, `regionvillage(id,districtId,name,postalCode)` 83.202. Kode **tanpa titik** (`33`, `3319`, `331904`, `3319042001`) → `regions:import` menyisipkan titik setelah digit ke-2/4/6. `postalCode` diabaikan (tidak ada kolomnya di `04`). Tahun data belum diketahui | Command `regions:import` | — | — | Mas |
| OQ-004 | Penyedia WhatsApp Business API (untuk OTP sekarang dan kwitansi WA di Fase 1), nomor resmi Pakis, template pesan | Kanal lupa password WA | Hanya kanal email + reset admin. Adapter `log` | Driver WA nyata (di luar paket ini) | Mas + Pakis |
| OQ-005 | Push notification (Firebase project milik Pakis)? | Notifikasi real-time di HP | Notifikasi dalam aplikasi (polling saat app dibuka & tiap 2 menit saat aktif) | — | Fase 1 |
| OQ-006 | Distribusi APK: Play Store (akun developer atas nama Pakis) atau unduh langsung? Ada pegawai pakai iPhone? Versi Android terendah HP pegawai? | Rilis mobile, minSdk | APK langsung via `mobile.download_url`, Android 8+ | T-047 (distribusi) | Pakis |
| OQ-007 | Perlu pencocokan wajah otomatis? | Kompleksitas, biaya, data biometrik | Tidak. Selfie hanya bukti visual | — | Fase 3 |
| OQ-008 | Kalender libur & cuti bersama yang diikuti Pakis | Status `holiday` | HRD menginput sendiri. Seeder hanya contoh | — | HRD Pakis |
| OQ-009 | Tinjauan hukum: teks pemberitahuan pemrosesan data pegawai (selfie, lokasi saat absen, perangkat), klasifikasi selfie menurut UU PDP, masa simpan, SOP kebocoran data | Kepatuhan go-live | Retensi selfie 365 hari, pemberitahuan tertulis sebelum dipakai | T-047 (go-live) | Owner Pakis + penasihat hukum |
| OQ-010 | HP yang di-root / emulator: tolak absen atau cukup tandai anomali? | Kebijakan anti-curang | Tandai anomali (`has_anomaly`), tidak menolak | — | Owner Pakis |
| OQ-011 | Aturan kerja nyata: jam shift per lokasi, toleransi telat, apakah sales absen dari mana saja, hari kerja Sabtu | Konfigurasi awal (bukan kode) | Seperti seeder demo. Diubah HRD saat UAT | — | HRD Pakis |
| OQ-012 | Domain email & SMTP untuk kirim reset password/notifikasi (mis. layanan email bisnis Pakis) | FR-005 di produksi | Mailpit di lokal, SMTP Sintech di staging | T-046 | Pakis |
| OQ-013 | Semua dependency di `03` §5 kompatibel PHP 8.5? | Versi PHP | Ya. Bila tidak, turun 8.4 dan catat di ADR-003 | — (diputuskan di T-001) | Agent di T-001 → laporkan |
| OQ-014 | Lokal memakai MariaDB 11.8 (bawaan XAMPP). Apakah CI (T-002) dan produksi (T-046) juga MariaDB, atau tetap MySQL 8.4? | Service DB di CI, paket server produksi, perilaku trigger/JSON | CI & produksi mengikuti MariaDB 11.8 agar sama dengan lokal. Fitur khusus MySQL 8.4 dihindari | T-002 (service DB), T-046 | Mas |

## Log Keputusan

| Tanggal | Keputusan | Sumber |
|---|---|---|
| 3 Nov 2026 | PAKIS ONE untuk Pakis saja (single-tenant) | Mas |
| 3 Nov 2026 | Semua parameter dinamis (site setting, role, referensi, penomoran) | Mas |
| 3 Nov 2026 | Wajib ubah password & lupa password | Mas |
| 3 Nov 2026 | Laravel 13 + PHP terbaru (8.5) | Mas |
| 3 Nov 2026 | Setiap CRUD wajib paging, pencarian, filter (ditegakkan test kontrak) | Mas |
| 3 Nov 2026 | Fondasi = core HR (pegawai & struktur) + absensi sebagai pintu masuk adopsi, payroll belakangan | Diskusi |
| 14 Nov 2026 | Database memakai **MariaDB 11.8** bawaan XAMPP (port 3307), menggantikan MySQL 8.4 di ADR-012. Cakupan CI/produksi: OQ-014 | Mas |
| 14 Nov 2026 | Lokal: cache/queue/session memakai driver `database` (PHP XAMPP tanpa ekstensi phpredis, `predis` tidak ditambah). Produksi tetap Redis | Mas |
| 14 Nov 2026 | Root monorepo = `D:\program\pakis`. Arsip konsep (zip, png, html v1.2, `region.sql`) tetap di `konsep/` dan tidak masuk git | Mas |
