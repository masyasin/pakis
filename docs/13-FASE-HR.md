# 13 — Fase HR: Kepegawaian Lengkap

> **Status: arah DISETUJUI Mas (15 Nov 2026), dikerjakan SETELAH Fase 0,5 (absensi) selesai.**
> Dokumen ini sumber kebenaran Fase HR (T-049 s.d. T-066) **sampai** tiap task memindahkan bagiannya ke
> dokumen inti (`04` DDL, `05` route, `06` permission/menu, `07` UI/katalog listing, `08` BR/setting,
> `09` SEC, `10` test) beserta test-nya. Jangan menambah tabel/route/permission/setting Fase HR ke dokumen
> inti sebelum task-nya dikerjakan: `PermissionCatalog`, `SettingRegistryTest`, dan katalog listing
> membandingkan dokumen dengan kode.

## 0. Keputusan (Mas, 15 Nov 2026)

1. **Lengkap**: semua bagian dibangun karena PAKIS ONE akan menjadi **produk Sintech** yang ditawarkan ke
   perusahaan lain (OQ-015).
2. Bagian yang mungkin tidak dipakai Pakis (Capeg, pangkat, sanksi, dll.) **tetap ada**, karena perusahaan
   lain bisa memakainya (OQ-016).
3. **Modul bisa dinyalakan/dimatikan per perusahaan** dari Pengaturan, dan daftar pilihannya diatur sendiri
   lewat Referensi (jenis status kerja, pangkat, jenis sanksi, kategori dokumen, dst.).
4. **Satu instalasi per perusahaan** (ADR-001 single-tenant tetap; tiap klien punya server, database,
   branding, dan pengaturan sendiri).
5. **Data keluarga** dikumpulkan (OQ-017), **kesehatan & psikotes** wajib tersedia (OQ-018).
6. **Cetak CV menghasilkan PDF** (OQ-019). Library PDF di luar `03` §5 → minta persetujuan di T-058.
7. Urutan: **selesaikan absensi (Fase 0,5) dulu**, Fase HR sesudahnya.
8. **Fase HR dibuat komplit** (Mas: "yang belum ada buat komplit saja"): selain 21 bagian detail pegawai,
   termasuk data administrasi (NPWP, BPJS, rekening, kontak darurat), **cuti & izin**, **lembur**, **kasbon**,
   **payroll + BPJS + PPh 21 + slip gaji**, dan **penilaian kinerja** (§8). Item ini sebelumnya tercatat
   "Fase 3" di `01-PRD.md`.

## 1. Posisi saat ini

| Jenis riwayat | Status |
|---|---|
| Riwayat perubahan data (audit) | ✅ Tab **Riwayat Perubahan** (T-007/T-024) |
| Riwayat penugasan & jabatan | ✅ Tabel `assignments` (T-024); mutasi/akhiri T-025 |
| Jadwal & presensi | 🕒 T-035, T-038 |
| Status kerja, pangkat, pendidikan, pengalaman, keluarga, diklat, penghargaan, sanksi, kesehatan, psikotes, dokumen elektronik, cetak CV | 📋 Fase HR (dokumen ini) |
| Cuti & izin, lembur, kasbon, payroll + slip gaji, penilaian kinerja, data administrasi | 📋 Fase HR §8 |

Detail pegawai sudah memakai grup tab + sub-tab (83db385): modul Fase HR cukup menambah sub-tab di grupnya.

## 2. Susunan tab

| Grup | Sub-tab (kunci `?tab=`) | Modul (`hr.modules`) |
|---|---|---|
| **Data Diri** | Profil `profil` · Alamat `alamat` · Administrasi `administrasi` · Keluarga `keluarga` · Pendidikan `pendidikan` · Pengalaman `pengalaman` | — · — · `admin_data` · `family` · `education` · `experience` |
| **Kepegawaian** | Status Kerja `status-kerja` · Penugasan & Jabatan `penugasan` · Pangkat `pangkat` | `employment_history` · — · `rank` |
| **Pengembangan** | Diklat `diklat` · Penghargaan `penghargaan` · Sanksi `sanksi` · Penilaian Kinerja `kinerja` | `training` · `award` · `sanction` · `appraisal` |
| **Kesehatan & Asesmen** | Kesehatan `kesehatan` · Psikotes `psikotes` | `health` · `psych_test` |
| **Kehadiran & Gaji** | Jadwal `jadwal` · Presensi `presensi` · Cuti & Izin `cuti` · Lembur `lembur` · Kasbon `kasbon` · Gaji & Slip `gaji` | — · — · `leave` · `overtime` · `loan` · `payroll` |
| **Dokumen** | Dokumen Elektronik `dokumen` | `documents` |
| *(tetap)* | Akun & Perangkat `akun` · Riwayat Perubahan `riwayat` | — |

Header detail pegawai mendapat tombol **Cetak CV (PDF)** bila modul `cv` aktif dan pelaku berhak.
Modul nonaktif: sub-tab tidak tampil, route-nya 404, dan datanya tidak bisa diisi. Grup tanpa sub-tab aktif
tidak tampil. Mematikan modul **tidak menghapus** data yang sudah ada (bisa dinyalakan lagi).

## 3. Pengaturan & referensi baru (calon `08` §2 dan `08` §3)

| Kunci setting | Tipe | Default | Keterangan |
|---|---|---|---|
| `hr.modules` | multi_enum | `employment_history`, `education`, `experience`, `training`, `award`, `documents`, `cv`, `admin_data`, `leave`, `overtime` | Modul aktif. `family`, `sanction`, `health`, `psych_test`, `rank`, `loan`, `payroll`, `appraisal` **mati** sampai perusahaan menyalakannya |
| `hr.contract_reminder_days` | int (1–180) | 30 | Pengingat kontrak berakhir (notifikasi T-029) |
| `hr.document_reminder_days` | int (1–180) | 30 | Pengingat dokumen kedaluwarsa |
| `hr.sensitive_retention_days` | int (365–3650) | 1825 | Masa simpan data kelas C setelah pegawai keluar, lalu dihapus permanen terjadwal |

Grup referensi baru (diatur admin, bukan enum): `education_level`, `rank`, `sanction_type`,
`document_category`, `health_record_type`, `training_type`. Grup `employment_status` ditambah meta
`requires_end_date` (kontrak/capeg) dan `is_permanent` (tetap); Capeg = item referensi biasa.

## 4. Kelas data & perlindungan

| Kelas | Isi | Aturan |
|---|---|---|
| **A — kepegawaian umum** | Status kerja, pangkat, pendidikan, pengalaman, diklat, penghargaan, dokumen kategori umum | Baca: `employees.view` + scope. Ubah: `employees.manage_history`. Ekspor lintas pegawai boleh |
| **B — pribadi terbatas** | Keluarga, sanksi | Baca: `employees.view_family` / `employees.view_disciplinary`. Ubah diaudit. Tidak ikut ekspor umum |
| **C — data pribadi spesifik (UU 27/2022 Pasal 4)** | Kesehatan, psikotes, data **anak** (di keluarga), NIK anggota keluarga | Isi terenkripsi (pola `NikCipher`); baca `employees.view_health` (anak & NIK keluarga: `employees.view_family` + reveal tercatat); **setiap pembukaan** dicatat `sensitive_data_viewed`; kesehatan/psikotes hanya bisa disimpan bila ada **persetujuan pegawai** tercatat; dihapus permanen setelah `hr.sensitive_retention_days` sejak keluar |

**SEC-012 direvisi di T-049**: dari "tidak mengumpulkan status nikah/data lain" menjadi "data kelas B/C hanya
dikumpulkan bila modulnya diaktifkan perusahaan, dengan izin terpisah, enkripsi (kelas C), persetujuan, dan
masa simpan". Teks pemberitahuan & dasar hukum tetap tanggung jawab tiap perusahaan; untuk Pakis lewat OQ-009.

Semua lampiran mengikuti SEC-050 (PDF/JPG/PNG, validasi isi, nama acak, disk privat, route ber-auth &
ber-scope).

## 5. Rancangan tabel

Pola umum: `employee_id` (FK; scope data lewat kolom ini), tanggal/rentang, nomor dokumen bila ada,
`document_id` (FK `employee_documents`, nullable), `note`, `[audit]`, soft delete. Tiap sub-tab = daftar mini
(paging 10, urut tanggal terbaru, dialog tambah/ubah/hapus). Daftar lintas pegawai hanya bila disebut
(tunduk `07` §3: paging, cari, filter, sort, katalog, test kontrak).

| Tabel | Kolom pokok | Aturan utama (calon BR) |
|---|---|---|
| `employment_histories` | `employment_status_id`, `start_date`, `end_date`, `contract_number`, `document_id` | Tidak tumpang tindih; status ber-`requires_end_date` wajib `end_date`; `employees.employment_status_id` = riwayat yang berlaku hari ini (diset Action); data lama: satu baris awal dari `join_date` + status sekarang (migrasi data) |
| `rank_histories` | `rank_id`, `effective_date`, `decree_number`, `document_id` | Pangkat berlaku = terakhir ≤ hari ini |
| `educations` | `education_level_id`, `institution`, `major`, `start_year`, `end_year`, `certificate_number`, `document_id` | Pendidikan terakhir = jenjang tertinggi (urutan referensi) |
| `work_experiences` | `company`, `position`, `start_date`, `end_date`, `leaving_reason` | `end_date ≥ start_date` |
| `family_members` | `relation` (`spouse`/`child`/`father`/`mother`), `full_name`, `gender`, `birth_date`, `nik_enc`/`nik_hash` (opsional), `occupation`, `is_dependent`, `marriage_date` (pasangan) | Status nikah diturunkan dari baris pasangan aktif; NIK keluarga termasking, reveal tercatat; anak = kelas C |
| `trainings` | `training_type_id`, `title`, `organizer`, `start_date`, `end_date`, `hours`, `certificate_number`, `document_id` | — |
| `awards` | `title`, `awarded_on`, `giver`, `document_id` | — |
| `sanctions` | `sanction_type_id`, `number`, `issued_on`, `valid_until`, `reason`, `document_id`, `cancelled_at`, `cancel_reason` | Tidak dihapus, hanya **dibatalkan** dengan alasan; status berlaku/kedaluwarsa/dibatalkan |
| `health_records` | `health_record_type_id`, `checked_on`, `provider`, `summary_enc`, `document_id` | Kelas C; wajib persetujuan `health` |
| `psych_tests` | `tested_on`, `provider`, `purpose`, `result_enc`, `document_id` | Kelas C; wajib persetujuan `psych_test` |
| `employee_consents` | `type` (`health`/`psych_test`/`family`), `given_at`, `text_version`, `document_id`, `withdrawn_at` | Persetujuan ditarik → data baru ditolak; data lama mengikuti masa simpan |
| `employee_documents` | `document_category_id`, `title`, `file_path`, `original_name`, `mime`, `size`, `issued_on`, `expires_on`, `sensitivity` (`A`/`B`/`C`) | Dokumen kelas C ikut aturan kelas C; pengingat kedaluwarsa |

## 6. Permission baru (calon `06` §3–§4)

| Permission | Owner | Admin | HRD | Kepala Lokasi | Supervisor | Pegawai |
|---|---|---|---|---|---|---|
| `employees.manage_history` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `employees.view_family` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `employees.view_disciplinary` | ✅ | ❌ | ✅ | 🔸 (bawahan) | 🔸 (bawahan) | ❌ |
| `employees.view_health` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `employees.manage_documents` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `employees.print_cv` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `leave.request` (diri sendiri, web & Pakis Pro) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `leave.approve` | ✅ | ❌ | ✅ | 🔸 | 🔸 | ❌ |
| `overtime.manage` (perintah & persetujuan) | ✅ | ❌ | ✅ | 🔸 | 🔸 | ❌ |
| `loans.manage` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `payroll.view` | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `payroll.process` (hitung, kunci, rilis slip) | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `payslips.view_own` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `appraisals.manage` | ✅ | ❌ | ✅ | 🔸 | 🔸 | ❌ |

🔸 = sesuai scope data. Semua dapat diubah per perusahaan lewat layar Role (T-018). Pegawai melihat riwayat
miliknya sendiri di Pakis Pro = di luar Fase HR (usulan lanjutan).

## 7. Task (urutan setelah Fase 0,5)

| Task | Isi | Dependensi |
|---|---|---|
| T-049 | Fondasi: `hr.modules` + setting lain, grup referensi baru, permission baru, revisi SEC-012, kerangka sub-tab dinamis per modul, komponen daftar riwayat bersama | T-024, T-025 |
| T-050 | Dokumen elektronik + pengingat kedaluwarsa | T-049, T-029 |
| T-051 | Riwayat status kerja (kontrak/capeg/tetap) + pengingat kontrak + migrasi data awal | T-049, T-050 |
| T-052 | Pendidikan & pengalaman kerja | T-049, T-050 |
| T-053 | Pangkat/golongan | T-049, T-050 |
| T-054 | Diklat & penghargaan | T-049, T-050 |
| T-055 | Sanksi (berlaku/kedaluwarsa/dibatalkan) | T-049, T-050 |
| T-056 | Keluarga (NIK keluarga terenkripsi, status nikah turunan) | T-049, T-050 |
| T-057 | Kesehatan & psikotes + persetujuan pegawai + masa simpan | T-049, T-050 |
| T-058 | Cetak CV PDF (library PDF perlu persetujuan) | T-051, T-052 |

| T-059 | Data administrasi: NPWP, BPJS Kes/TK, rekening bank (terenkripsi), kontak darurat | T-049 |
| T-060 | Cuti & izin (+ Pakis Pro, persetujuan, status harian `leave`) | T-049, T-038, T-041 |
| T-061 | Lembur (perintah/pengajuan, persetujuan, jam dari absensi) | T-049, T-038 |
| T-062 | Kasbon (pinjaman, cicilan potong gaji) | T-049 |
| T-063 | Komponen & struktur gaji | T-049, T-059 |
| T-064 | Proses payroll + BPJS + PPh 21 | T-063, T-060, T-061, T-062 |
| T-065 | Slip gaji PDF (web & Pakis Pro) + laporan payroll | T-064, T-058 |
| T-066 | Penilaian kinerja | T-049 |

Rincian kriteria penerimaan ada di `TASKS.md` bagian Fase HR.

## 8. Modul operasional HR (agar komplit)

Semua modul ikut `hr.modules` (bisa dimatikan per perusahaan) dan semua angka/aturan dibaca dari Pengaturan
atau Referensi, karena tiap perusahaan dan tiap tahun aturannya bisa berbeda.

| Modul | Isi pokok | Catatan desain |
|---|---|---|
| **Data administrasi** (`admin_data`) | NPWP, no. BPJS Kesehatan & Ketenagakerjaan, status PTKP, rekening bank (bank, nomor, atas nama), kontak darurat | Kelas B; nomor rekening & NPWP terenkripsi + termasking (pola NIK); sumber data payroll |
| **Cuti & izin** (`leave`) | Jenis cuti/izin (Referensi: tahunan, sakit, melahirkan, menikah, duka, izin tidak dibayar, dst.), hak & saldo tahunan per pegawai, pengajuan (web & Pakis Pro), persetujuan atasan/HRD (pola BR-069), lampiran (surat dokter), kalender cuti | Status harian absensi baru `leave` (resolver BR-068 diperluas); cuti tidak dihitung `absent`; saldo tidak boleh minus kecuali jenis mengizinkan |
| **Lembur** (`overtime`) | Surat perintah/pengajuan lembur, persetujuan, jam lembur aktual dari absensi, rekap per periode | Rumus upah lembur (PP 35/2021) di Pengaturan, hasilnya masuk payroll |
| **Kasbon** (`loan`) | Pengajuan pinjaman, persetujuan, jadwal cicilan, sisa pinjaman | Cicilan otomatis menjadi potongan payroll; tidak boleh melewati batas % gaji (setting) |
| **Payroll** (`payroll`) | Komponen gaji (Referensi: pokok, tunjangan tetap/tidak tetap, potongan), struktur gaji per pegawai berlaku per tanggal, periode gaji, proses hitung (absensi, cuti tidak dibayar, lembur, kasbon), **BPJS** (Kes, JHT, JP, JKK, JKM; tarif & batas upah di Pengaturan), **PPh 21** (metode & tarif efektif di tabel yang bisa diperbarui), kunci periode, laporan rekap & ekspor bank | Periode yang sudah dikunci tidak bisa diubah (koreksi lewat periode berikutnya); nominal disimpan integer rupiah; tarif pajak/BPJS **tidak** ditulis sebagai konstanta |
| **Slip gaji** | PDF per pegawai per periode (web & Pakis Pro), dikunci setelah dirilis | Kelas B; pegawai hanya melihat slipnya sendiri; buka slip orang lain tercatat |
| **Penilaian kinerja** (`appraisal`) | Periode penilaian, kriteria/bobot (Referensi), penilaian atasan (+ penilaian diri opsional), nilai akhir, catatan | Hanya atasan langsung/HRD; hasil kelas B |

Pertanyaan rinci per perusahaan (komponen gaji, tanggal gajian, metode PPh 21 gross/net/gross-up, jenis cuti &
hak tahunan, aturan lembur) diatur lewat Pengaturan/Referensi saat implementasi; kebutuhan Pakis dicatat di
OQ-020.
