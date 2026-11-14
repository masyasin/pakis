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
| OQ-013 | ~~Semua dependency di `03` §5 kompatibel PHP 8.5?~~ **Terjawab di T-001 (14 Nov 2026): ya.** Laravel 13.42, Spatie 8.5, Sanctum 4.4, OpenSpout 5.13, Pest 5.3, Larastan 3.15 terpasang & teruji di PHP 8.5.12. Tetap PHP 8.5 (ADR-003) | Versi PHP | — | — | Agent |
| OQ-014 | ~~Apakah CI dan produksi juga MariaDB, atau tetap MySQL 8.4?~~ **Terjawab 14 Nov 2026: MariaDB 11.8 di lokal, CI (`mariadb:11.8.9`), dan produksi.** | Service DB di CI, paket server produksi | — | — | Mas |

## Log Keputusan

| Tanggal | Keputusan | Sumber |
|---|---|---|
| 3 Nov 2026 | PAKIS ONE untuk Pakis saja (single-tenant) | Mas |
| 3 Nov 2026 | Semua parameter dinamis (site setting, role, referensi, penomoran) | Mas |
| 3 Nov 2026 | Wajib ubah password & lupa password | Mas |
| 3 Nov 2026 | Laravel 13 + PHP terbaru (8.5) | Mas |
| 3 Nov 2026 | Setiap CRUD wajib paging, pencarian, filter (ditegakkan test kontrak) | Mas |
| 3 Nov 2026 | Fondasi = core HR (pegawai & struktur) + absensi sebagai pintu masuk adopsi, payroll belakangan | Diskusi |
| 14 Nov 2026 | Database memakai **MariaDB 11.8** bawaan XAMPP (port 3307), menggantikan MySQL 8.4 di ADR-012. Berlaku juga untuk CI dan produksi (OQ-014) | Mas |
| 14 Nov 2026 | Kontrak API `05` §2.3 ditambah `BAD_REQUEST` 400, `METHOD_NOT_ALLOWED` 405, `PAYLOAD_TOO_LARGE` 413, `SERVICE_UNAVAILABLE` 503 | Mas (T-003) |
| 14 Nov 2026 | CSP: script wajib nonce; style `'self' 'unsafe-inline'` tanpa nonce, karena progress bar Inertia/Sonner/React menyisipkan `<style>` saat runtime dan terbukti diblokir Chromium dalam mode enforce | Agent (T-003), teknis |
| 14 Nov 2026 | devDependency test frontend: `happy-dom` + `@testing-library/react` (Vitest sudah ada lewat vite-plus) | Mas (T-005) |
| 14 Nov 2026 | Token warna status (`--status-*`, terang/gelap, kontras ≥ 4.5:1 terukur) ditambahkan di `app.css` untuk `StatusBadge` (07 §6). Palet primer hijau Pakis (07 §1.1) belum diterapkan, menunggu `branding.primary_color` (T-008) | Agent (T-005), teknis |
| 14 Nov 2026 | T-008 dibagi dua: registri, penyimpanan, halaman Pengaturan, config publik, validasi & unggah branding sekarang; penerapan branding ke tema (CSS variable, logo di layout/login, shared props) setelah branch desain `feat/ui-desain` digabung. Izin `settings.*` sementara deny-by-default (lokal saja) sampai T-018 | Mas (T-008) |
| 14 Nov 2026 | Izin `audit.view`/`audit.export` sementara deny-by-default (hanya environment `local`) sampai permission Spatie di T-018 | Mas (T-007) |
| 14 Nov 2026 | Larastan **level 7** (bawaan starter kit, sudah 0 error) dipertahankan. NFR-012 menjadi "≥ level 6, saat ini 7" | Mas (T-002) |
| 15 Nov 2026 | T-013 (teknis): sebelum permission Spatie (T-018), BR-017 dicek lewat Gate atas daftar permission menu `06` §8 (`WebAccess`); `dashboard.view` masuk ability sementara deny-by-default (lokal saja) dan test memberi `dashboard.view` lewat `TestCase`. Status pegawai saat login (BR-010) lewat kontrak `EmployeeStatus`, **fail-closed** bila akun menunjuk pegawai tetapi statusnya tidak bisa dipastikan (sebelum T-024). Login pertama kali dicatat `new_device_login` tanpa email (belum ada perangkat pembanding). Akun terkunci yang dicoba dari IP lain mendapat pesan gagal generik, bukan pesan lockout (SEC-003) | Agent (T-013), teknis |
| 15 Nov 2026 | Lookup sumber **`users`** ditambahkan (05 §4.11) untuk filter pelaku/pengguna di Audit & Log keamanan. Hanya pemegang `audit.view` atau `users.view`. Pengecualian BR-006 "hanya aktif": akun nonaktif tetap muncul agar pelaku lama bisa disaring. Parameter `ids[]` ditambahkan untuk mengambil label nilai terpilih (chip) | Mas (T-011) |
| 14 Nov 2026 | Lokal: cache/queue/session memakai driver `database` (PHP XAMPP tanpa ekstensi phpredis, `predis` tidak ditambah). Produksi tetap Redis | Mas |
| 14 Nov 2026 | Fitur starter kit dimatikan: pendaftaran mandiri (05 §1 tanpa `/register`) dan **passkeys** (di luar alur login + lockout + MFA, tabel tidak ada di 04). Mengaktifkan passkey kelak = OQ baru | Mas (T-001) |
| 14 Nov 2026 | Root monorepo = `D:\program\pakis`. Arsip konsep (zip, png, html v1.2, `region.sql`) tetap di `konsep/` dan tidak masuk git | Mas |
