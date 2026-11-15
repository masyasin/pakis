# 13 — USULAN: Riwayat Kepegawaian (HR lanjutan)

> **Status: USULAN, belum disetujui.** Dokumen ini **bukan** sumber kebenaran dan **tidak boleh dibangun**
> sebelum (1) Mas/Pakis menjawab OQ-015 s.d. OQ-019 di `12-OPEN-QUESTIONS.md`, (2) isinya dipindah ke
> dokumen sumber kebenaran (`04`, `06`, `07`, `08`, `09`), dan (3) dibuat task di `TASKS.md`.
> Permintaan Mas, 15 Nov 2026: detail pegawai punya 21 bagian (profil s.d. dokumen elektronik), karena
> "pegawai harus punya riwayat".

## 1. Posisi saat ini

| Jenis riwayat | Status |
|---|---|
| Riwayat perubahan data (siapa mengubah apa) | ✅ Ada — tab **Riwayat Perubahan** (audit log, T-007/T-024) |
| Riwayat penugasan & jabatan | ✅ Tabel `assignments` (T-024); mutasi/akhiri di T-025 |
| Presensi & jadwal | 🕒 Direncanakan — T-035, T-038 |
| Status kerja (kontrak → capeg → tetap), pangkat, pendidikan, keluarga, diklat, penghargaan, sanksi, pengalaman, kesehatan, psikotes, dokumen elektronik, cetak CV | ❌ Belum ada. Status kerja saat ini **satu nilai** (`employees.employment_status_id`) tanpa jejak |
| Slip gaji | ❌ Butuh modul payroll (di luar paket Fase 0 + 0,5, lihat `00-README.md` §1) |

Halaman detail pegawai **sudah** memakai grup tab + sub-tab (83db385), sehingga modul di bawah cukup
menambah sub-tab di grupnya.

## 2. Susunan tab yang diusulkan (21 bagian → 6 grup)

| Grup | Sub-tab | Catatan |
|---|---|---|
| **Data Diri** | Profil · Alamat · Keluarga · Pendidikan · Pengalaman | Keluarga = pasangan (status nikah), anak, orang tua dalam satu sub-tab |
| **Kepegawaian** | Status Kerja · Penugasan & Jabatan · Pangkat | Kontrak, Capeg, Tetap = **satu** riwayat Status Kerja |
| **Pengembangan** | Diklat · Penghargaan · Sanksi | |
| **Kesehatan & Asesmen** | Kesehatan · Psikotes | Data pribadi **spesifik** (UU PDP), izin khusus |
| **Kehadiran & Gaji** | Jadwal · Presensi · Slip Gaji | Slip gaji setelah payroll |
| **Dokumen** | Dokumen Elektronik | Cetak CV = **tombol aksi** di header, bukan tab |
| *(tetap)* | Akun & Perangkat · Riwayat Perubahan | Sudah ada |

## 3. Kelas data & aturan perlindungan

| Kelas | Isi | Aturan |
|---|---|---|
| **A — kepegawaian umum** | Status kerja, pangkat, pendidikan, pengalaman, diklat, penghargaan, dokumen non-sensitif | Izin baca `employees.view` + scope; ubah `employees.manage_history` |
| **B — pribadi terbatas** | Keluarga (pasangan, orang tua), sanksi | Izin baca terpisah (`employees.view_family`, `employees.view_disciplinary`); setiap baca **tidak** dicatat, setiap ubah diaudit |
| **C — spesifik (UU 27/2022 Pasal 4)** | Kesehatan, psikotes, data **anak** | Izin `employees.view_health`; kolom isi terenkripsi (seperti NIK); **setiap pembukaan dicatat** `sensitive_data_viewed`; dasar hukum + persetujuan pegawai + masa simpan (OQ-018, terkait OQ-009) |

**Konflik dengan aturan yang berlaku:** `09` SEC-012 *melarang* mengumpulkan status nikah dan data lain yang
tidak dipakai fitur Fase 0–0,5. Kelas B/C memerlukan perubahan SEC-012 yang disetujui Mas/Pakis lebih dulu.

Semua lampiran mengikuti SEC-050 (allowlist PDF/JPG/PNG, validasi isi, nama acak, disk privat, disajikan lewat
route ber-auth & ber-scope).

## 4. Rancangan tabel (draf)

Pola umum semua tabel riwayat: `employee_id` (FK, scope data lewat kolom ini), rentang/tanggal, nomor dokumen
(SK/kontrak/sertifikat) bila ada, `document_id` (lampiran, FK `employee_documents`), `note`, `[audit]`, soft
delete. Setiap sub-tab berbentuk daftar mini dengan paging & urut tanggal (`07` §3 catatan "tab di halaman
detail"); ekspor lintas pegawai hanya untuk kelas A.

| Tabel | Kolom pokok | Aturan utama (calon BR) |
|---|---|---|
| `employment_histories` | `employment_status_id` (ref `employment_status`), `start_date`, `end_date`, `contract_number`, `document_id` | Tidak tumpang tindih; kontrak wajib `end_date`; `employees.employment_status_id` = status riwayat yang berlaku hari ini (dijaga Action, bukan input bebas); pengingat kontrak habis H-*n* (setting baru) |
| `rank_histories` | `rank_id` (ref grup baru `rank`), `effective_date`, `decree_number`, `document_id` | Pangkat berlaku = riwayat terakhir ≤ hari ini |
| `educations` | `education_level_id` (ref grup baru), `institution`, `major`, `start_year`, `end_year`, `document_id` | Pendidikan terakhir ditandai otomatis |
| `work_experiences` | `company`, `position`, `start_date`, `end_date`, `leaving_reason` | — |
| `family_members` | `relation` (`spouse`/`child`/`father`/`mother`), `full_name`, `gender`, `birth_date`, `occupation`, `is_dependent` | Status nikah diturunkan dari baris pasangan; NIK keluarga **tidak** dikumpulkan kecuali OQ-017 menyatakan perlu |
| `trainings` | `title`, `organizer`, `start_date`, `end_date`, `hours`, `document_id` | — |
| `awards` | `title`, `awarded_on`, `giver`, `document_id` | — |
| `sanctions` | `sanction_type_id` (ref grup baru: teguran, SP1–SP3, skorsing), `number`, `issued_on`, `valid_until`, `reason`, `document_id` | SP berlaku sampai `valid_until`; tidak bisa dihapus, hanya dibatalkan dengan alasan |
| `health_records` | `type` (MCU/vaksin/lainnya), `checked_on`, `summary_enc`, `document_id` | Kelas C |
| `psych_tests` | `tested_on`, `provider`, `result_enc`, `document_id` | Kelas C |
| `employee_documents` | `category_id` (ref grup baru: KTP, KK, NPWP, BPJS, ijazah, kontrak, SK, …), `file_path`, `original_name`, `mime`, `size`, `expires_on`, `sensitivity` (A/B/C) | Dokumen kelas C ikut aturan kelas C; pengingat dokumen kedaluwarsa |

Cetak CV: rangkuman kelas A (+ B bila pelaku berhak) sebagai **halaman cetak** (CSS print, tanpa dependency
baru). Bila Pakis butuh berkas PDF jadi, perlu library PDF di luar `03` §5 → ditanyakan dulu.

## 5. Izin baru (calon `06` §3)

| Permission | Owner | Admin | HRD | Kepala Lokasi | Supervisor |
|---|---|---|---|---|---|
| `employees.manage_history` (ubah kelas A) | ✅ | ❌ | ✅ | ❌ | ❌ |
| `employees.view_family` | ✅ | ❌ | ✅ | ❌ | ❌ |
| `employees.view_disciplinary` | ✅ | ❌ | ✅ | ? (OQ-016) | ? (OQ-016) |
| `employees.view_health` | ✅ | ❌ | ✅ | ❌ | ❌ |
| `employees.manage_documents` | ✅ | ❌ | ✅ | ❌ | ❌ |

## 6. Usulan pentahapan

| Tahap | Isi | Syarat mulai |
|---|---|---|
| **H1 — Kepegawaian inti** | Riwayat Status Kerja (kontrak/capeg/tetap) + pengingat kontrak, Dokumen Elektronik, Pendidikan, Pengalaman, Cetak CV (halaman cetak) | OQ-015, OQ-016, OQ-019 |
| **H2 — Pengembangan** | Pangkat, Diklat, Penghargaan, Sanksi | H1 + daftar pangkat/jenis sanksi dari Pakis |
| **H3 — Data sensitif** | Keluarga, Kesehatan, Psikotes | OQ-017, OQ-018 (tinjauan hukum, terkait OQ-009) + perubahan SEC-012 |
| **Payroll** | Slip Gaji | Paket payroll (fase berikutnya) |
