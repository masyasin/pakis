# 07 — Spesifikasi UI (Fase 0 + 0,5)

## 1. Arah Visual

Produk internal perusahaan distribusi pertanian: **tenang, rapi, terpercaya, cepat dipindai**. Warna hijau daun sebagai identitas dan amber sebagai aksen peringatan. Dasarnya sama dengan prototipe `PAKIS_ONE_Prototipe_Mobile_v1.0.html`.

### 1.1 Token (CSS variables, terang / gelap)

| Token | Terang | Gelap |
|---|---|---|
| `--primary` | `#2F6B3B` | `#79B98B` |
| `--primary-strong` (header) | `#24552F` | `#2C5A37` |
| `--accent` | `#A9661A` | `#E0AC63` |
| `--bg` / `--surface` | `#F3F6F2` / `#FFFFFF` | `#121814` / `#1A221D` |
| `--border` | `#D8E2DA` | `#2F3C33` |
| `--text` / `--muted` | `#16241B` / `#56675B` | `#E5EDE7` / `#A3B3A8` |
| `--danger` / `--info` / `--success` | `#B3261E` / `#24609B` / `#2E7D4F` | `#F08A80` / `#85B7E8` / `#6FC28D` |

Warna primer bisa diganti lewat setting `branding.primary_color` (diterapkan sebagai `--primary`, dengan varian terang/gelap dihitung otomatis dan kontras dicek ≥ 4.5:1 terhadap putih. Kalau gagal, setting ditolak, BR-021). Font: sistem (`Inter` bila tersedia lokal, tanpa CDN). Ikon: Lucide (sudah ada di starter kit). Radius 12 px, spacing kelipatan 4. Tema mengikuti `prefers-color-scheme`, dengan toggle yang diingat dan diterapkan sebelum render.

### 1.2 Layout web
Sidebar kiri (seksi berjudul HRIS/Sistem berisi grup menu yang bisa dibuka-tutup, sesuai `06` §8; bisa diciutkan, grup menjadi ikon dengan menu melayang), header (judul halaman, breadcrumb, lonceng notifikasi + jumlah belum dibaca, menu unduhan, toggle tema, avatar → Profil/Keluar). Konten maks 1440 px. Di layar < 1024 px, sidebar menjadi drawer.

## 2. Pola Komponen Bersama (`resources/js/components/pakis/`)

| Komponen | Fungsi |
|---|---|
| `DataTable` | Daftar standar (§3). Menerima `rows`, `listing`, `columns`, `rowActions`, `bulkActions`. |
| `FilterBar` / `FilterSheet` | Dibuat otomatis dari `listing.spec.filters` |
| `PageHeader` | Judul, deskripsi singkat, tombol aksi utama (mis. "Tambah Pegawai") |
| `FormSection`, `Field` | Label, bantuan, galat inline, format lokal |
| `SettingField` | Merender satu kunci setting sesuai tipe registri |
| `ConfirmDialog` | Aksi berisiko: konsekuensi jelas, tombol merah, opsional ketik nama untuk konfirmasi |
| `PasswordConfirmDialog` | Konfirmasi ulang password (`06` §1.6) |
| `MaskedValue` | Menampilkan NIK `3319********0001` + tombol "Lihat" (bila berhak) |
| `StatusBadge` | Status absensi/koreksi/perangkat/pegawai dengan warna & label konsisten (§6) |
| `EmptyState`, `ErrorState`, `TableSkeleton` | Empty, galat, dan loading |
| `LocationDiagram` | Diagram SVG titik presensi vs titik lokasi + lingkaran radius, jarak & arah mata angin, tombol "Buka di peta" (OpenStreetMap, tab baru, hanya bila diklik). **Tanpa library & tanpa tile** (keputusan Mas 15 Nov 2026, menggantikan `MapPin` Leaflet/OSM): koordinat pegawai tidak terkirim otomatis ke pihak luar. Hanya di detail presensi untuk pemegang `attendance.view_photo` |

## 3. Standar Halaman Daftar (WAJIB untuk setiap CRUD)

### 3.1 Anatomi

```
┌─ PageHeader: Judul · deskripsi · [Ekspor] [+ Tambah ...]
├─ Toolbar: [🔍 Cari ...........] [Filter (2)] [Urutkan ▾] [Reset]
├─ Chip filter aktif: Status: Telat ✕ · Lokasi: Gudang Undaan ✕
├─ (bila ada pilihan) Bar aksi massal: 3 dipilih · [Aksi ...]
├─ Tabel: header bisa diklik untuk sort (ikon arah) · baris bisa diklik ke detail · menu aksi baris (⋯)
└─ Footer: Menampilkan 26–50 dari 132 · [10|25|50|100 per halaman] · « ‹ 2 3 4 › »
```

### 3.2 Perilaku

1. **Paging server-side** selalu aktif. Tidak ada daftar yang memuat semua baris ke browser.
2. **Pencarian**: debounce 400 ms, minimal 2 karakter, tombol ✕ untuk menghapus. Placeholder menyebut kolom yang dicari ("Cari nama, nomor pegawai, atau HP").
3. **Filter**: panel samping (desktop) / bottom sheet (mobile). Jumlah filter aktif tampil di tombol. Setiap filter aktif tampil sebagai chip yang bisa dihapus satu-satu. Tombol **Reset** mengembalikan default.
4. **Sort**: klik header kolom yang bisa di-sort, atau pilih di menu "Urutkan" (untuk tampilan mobile).
5. **State di URL**: `q`, `filter[...]`, `sort`, `page`, `per_page` tersinkron ke query string. Tombol back browser dan tautan yang dibagikan menghasilkan tampilan yang sama. Mengubah pencarian/filter mengembalikan ke halaman 1.
6. **Pilihan per halaman** diingat per daftar (localStorage, dibungkus try/catch).
7. **Empty state** dibedakan dua jenis: "Belum ada data" (dengan tombol tambah bila berhak) vs "Tidak ada hasil untuk filter ini" (dengan tombol Reset filter).
8. **Loading**: skeleton baris. Data lama tetap tampil redup saat memuat ulang (Inertia `preserveState`).
9. **Ekspor** (bila `spec.exportable` dan berhak): memakai filter & pencarian yang sedang aktif, dengan teks tombol "Ekspor (132 baris)".
10. **Mobile web (< 768 px)**: tabel menjadi daftar kartu, dan filter memakai bottom sheet.
11. **Aksesibilitas**: tabel `<table>` semantik, `aria-sort` di header, fokus keyboard pada baris & aksi, pengumuman jumlah hasil lewat `aria-live`.

### 3.3 Checklist per CRUD (disalin ke setiap task yang membuat daftar)

- [ ] `*Listing` class + `ListingSpec` (cari, filter, sort, default sort, per_page)
- [ ] Controller `index` memakai `ListingQuery` dan scope data
- [ ] Halaman memakai `DataTable` (bukan tabel buatan sendiri)
- [ ] Pencarian, filter, sort, paging berfungsi dan tersinkron URL
- [ ] Empty state dua jenis + loading skeleton
- [ ] Ekspor sesuai filter (bila ada di katalog)
- [ ] Test kontrak listing hijau + test fitur pencarian, satu filter, dan sort

### 3.4 Katalog Listing (sumber kebenaran)

Notasi filter: **S** select, **MS** multi select, **B** boolean, **DR** rentang tanggal, **NR** rentang angka, **R** relasi (opsi dari lookup). Default per halaman 25 (web) / 20 (mobile).

| Kunci listing | Route | Kolom | Cari di | Filter | Sort (default **tebal**) | Ekspor |
|---|---|---|---|---|---|---|
| `notifications` | `admin.notifications.index` | Waktu, judul, isi singkat, status baca | judul, isi | status baca (S: semua/belum/sudah), jenis (MS), waktu (DR) | **-created_at** | ❌ |
| `sequences` | `admin.sequences.index` | Kode, nama, pola, periode reset, nomor terakhir, contoh berikut | kode, nama | periode reset (MS) | **code**, name | ❌ |
| `references` | `admin.references.index` | Urutan, kode, nama, deskripsi, aktif, sistem | kode, nama | grup (S, wajib), aktif (B), sistem (B) | **sort_order**, name, code | ❌ |
| `users` | `admin.users.index` | Nama, username, email, role, pegawai terkait, aktif, MFA, login terakhir | nama, username, email, HP | role (MS), aktif (B), MFA aktif (B), punya pegawai (B), login terakhir (DR) | **name**, username, last_login_at | ✅ |
| `roles` | `admin.roles.index` | Nama (+ kode), deskripsi, scope, wajib MFA, jumlah permission, jumlah pengguna, sistem | nama (`label`), kode, deskripsi | scope (MS), wajib MFA (B), sistem (B) | **sort_order**, label | ❌ |
| `audit_logs` | `admin.audit.index` | Waktu, pelaku, event, objek, IP | event, objek (tipe#id), IP, nama pelaku | pelaku (R users), event (MS), modul (MS), waktu (DR, default 7 hari) | **-occurred_at** | ✅ |
| `security_events` | `admin.security-events.index` | Waktu, event, pengguna/identifier, kanal, IP, perangkat | identifier, IP, nama pengguna | event (MS), kanal (MS), pengguna (R users), waktu (DR, default 7 hari) | **-occurred_at** | ✅ |
| `regions` | `admin.regions.index` | Kode desa, desa/kelurahan, kecamatan, kab/kota, provinsi | nama desa, kode | provinsi (R), kab/kota (R, bergantung provinsi), kecamatan (R, bergantung kab/kota) | **code**, name | ❌ |
| `locations` | `admin.locations.index` | Kode, nama, tipe, desa/kec, kepala, radius, punya koordinat, jumlah pegawai, aktif | kode, nama, alamat | tipe (MS), aktif (B), punya koordinat (B), kab/kota (R) | **name**, code, employees_count | ✅ |
| `departments` | `admin.departments.index` | Kode, nama, induk, jumlah jabatan, jumlah pegawai, aktif | kode, nama | induk (R), aktif (B) | **name**, code | ✅ |
| `positions` | `admin.positions.index` | Kode, nama, departemen, atasan, level, mode absen, jumlah pegawai, aktif | kode, nama | departemen (R), mode absen (MS), level (NR), aktif (B) | **name**, code, level | ✅ |
| `employees` | `admin.employees.index` | No. pegawai, nama, jabatan, lokasi, status kerja, HP, tgl masuk, akun, status | nama, no. pegawai, HP, email | status (S, default aktif), status kerja (MS), lokasi (R), departemen (R), jabatan (R), punya akun (B), tgl masuk (DR) | **full_name**, employee_number, join_date | ✅ |
| `assignments` | `admin.assignments.index` | Pegawai, jabatan, lokasi, atasan, mode absen, mulai, selesai, utama | nama/no. pegawai | aktif (B, default ya), lokasi (R), departemen (R), jabatan (R), atasan (R employees), mulai (DR) | **-start_date**, employee_name | ✅ |
| `import_batches` | `admin.imports.index` | Waktu, nama berkas, jenis data, status, total, valid, galat, dibuat, diperbarui, oleh | nama berkas | jenis data (MS, T-085), status (MS), oleh (R users), waktu (DR) | **-created_at** | ❌ |
| `import_rows` | `admin.imports.rows.index` | Baris, data utama (nama, no. pegawai, NIK termasking), status, pesan galat | nama, no. pegawai | status (MS, default invalid bila ada) | **row_number** | ✅ (unduh galat) |
| `import_profiles` | `admin.import-profiles.index` | Nama, jumlah kolom, dibuat oleh, terakhir dipakai | nama | oleh (R users) | **name**, -updated_at | ❌ |
| `export_files` | `admin.exports.index` | Waktu, daftar, jumlah baris, status, kedaluwarsa | nama daftar | status (MS) | **-created_at** | ❌ |
| `devices` | `admin.devices.index` | Pengguna, pegawai, model, OS, versi app, status, didaftarkan, terakhir aktif | nama, username, model | status (MS, default pending+active), lokasi (R), versi app (S), terakhir aktif (DR) | **-created_at**, last_seen_at | ✅ |
| `shifts` | `admin.shifts.index` | Kode, nama, jam, lintas hari, toleransi, jumlah pegawai, aktif | kode, nama | aktif (B), lintas hari (B) | **start_time**, name | ❌ |
| `schedules` | `admin.schedules.index` | Pegawai, lokasi, jabatan, pola Sen–Min (kode shift), berlaku sejak | nama/no. pegawai | lokasi (R), departemen (R), jabatan (R), shift (R, dipakai di pola), tanpa jadwal (B) | **employee_name**, -effective_from | ❌ |
| `schedule_overrides` | `admin.schedule-overrides.index` | Tanggal, pegawai, shift pengganti / Libur, catatan | nama/no. pegawai, catatan | tanggal (DR, default bulan ini), lokasi (R), shift (R), libur (B) | **-date**, employee_name | ✅ |
| `field_trips` | `admin.field-trips.index` | Nomor, tanggal (rentang + jumlah hari), tujuan, pegawai (ringkas), aturan lokasi, status | nomor, tujuan, nama pegawai | status (MS, default disetujui + menunggu), tanggal (DR, default bulan ini; tugas yang beririsan), lokasi (R; penugasan utama pegawai), aturan (S) | **-start_date**, number | ❌ |
| `holidays` | `admin.holidays.index` | Tanggal (rentang + jumlah hari), nama + keterangan, nasional, berlaku | nama, keterangan | tahun (S, default tahun ini; libur yang beririsan dengan tahun itu), nasional (B), lokasi (R; libur yang berlaku di lokasi itu = semua lokasi + khusus lokasi itu) | **date** (tanggal mulai), name | ✅ |
| `attendance_days` | `admin.attendance.index` | Tanggal, pegawai, lokasi, shift, masuk, pulang, telat (mnt), status, penanda (offline/koreksi/anomali) | nama/no. pegawai | tanggal (DR, default hari ini), status (MS), lokasi (R), departemen (R), jabatan (R), offline (B), dikoreksi (B), anomali (B) | **-work_date**, employee_name, check_in_at, late_minutes | ✅ |
| `attendance_summary` | `admin.attendance-summary.index` | Pegawai, lokasi, hari kerja, hadir, telat, total menit telat, pulang cepat, tidak absen pulang, tidak hadir, libur, % kehadiran (definisi BR-072) | nama/no. pegawai | bulan (S `YYYY-MM`, wajib, default bulan ini), lokasi (R), departemen (R), jabatan (R), % kehadiran (NR) | **employee_name**, attendance_pct, late_count | ✅ |
| `attendance_corrections` | `admin.attendance-corrections.index` | Nomor, diajukan, pegawai, tanggal kerja, jenis, jam usulan, alasan, status, penyetuju | nomor, nama/no. pegawai | status (MS, default pending), tanggal kerja (DR), alasan (MS), lokasi (R), penyetuju (R employees) | **-created_at**, work_date | ✅ |
| `my_attendance_days` | `api.v1.attendance.index` | (mobile) tanggal, shift, masuk, pulang, status | nama shift | bulan (S), status (MS) | **-work_date** | — |
| `my_corrections` | `api.v1.attendance-corrections.index` | (mobile) nomor, tanggal, jenis, status | nomor | status (MS), bulan (S) | **-created_at** | — |
| `approval_corrections` | `api.v1.approvals.attendance-corrections.index` | (mobile) pegawai, tanggal, jenis, jam usulan, alasan | nama/no. pegawai, nomor | status (MS, default pending), tanggal kerja (DR) | **created_at** (terlama dulu) | — |
| `my_field_trips` | `api.v1.field-trips.index` | (mobile) nomor, tanggal, tujuan, aturan, status | nomor, tujuan | status (MS), bulan (S; beririsan) | **-start_date** | — |
| `approval_field_trips` | `api.v1.approvals.field-trips.index` | (mobile) pegawai, tanggal, tujuan, aturan | nama/no. pegawai, nomor | status (MS, default pending) | **start_date** (terdekat dulu), created_at | — |
| `my_notifications` | `api.v1.notifications.index` | (mobile) waktu, judul, isi | judul, isi | status baca (S) | **-created_at** | — |
| `approvals` | `admin.approvals.index` | Jenis dokumen, ringkasan (nomor · pihak · nominal), alasan aturan, pengaju, langkah (n/total), umur menunggu, status, penanda eskalasi | ringkasan (nomor dokumen), nama pengaju | tab (S, wajib, default `mine`: Menunggu saya · Saya ajukan · Semua; dua terakhir butuh `approvals.view`, PA-27), jenis dokumen (S), status (MS), diajukan (DR `created_at`, PA-30) | **-created_at**, amount | ❌ |
| `approval_rules` | `admin.approval-rules.index` | Jenis dokumen, nama, rentang nominal, kondisi, prioritas, rantai langkah, aktif, pernah dipakai | nama | jenis dokumen (S), aktif (B) | **document_type** (lalu prioritas), priority, name | ❌ |
| `approval_delegations` | `admin.approval-delegations.index` | Pemberi, penerima, mulai–selesai, jenis dokumen (semua/daftar), alasan, status (berlaku/terjadwal/lewat/dicabut) | nama pemberi/penerima | berlaku pada (DR; beririsan dengan `start_date`–`end_date`, PA-30), belum dicabut (B) | **-start_date**, end_date | ❌ |
| `product_categories` | `admin.product-categories.index` | Kode, nama, induk, jenis, penanda (batch/ED/seri/izin edar), zona rak, urutan, aktif | kode, nama | jenis (S), induk (R), aktif (B) | **sort_order**, name, code | ❌ |
| `products` | `admin.products.index` | Foto, SKU, nama, kategori & jenis, bahan aktif, kemasan, satuan dasar, terbatas, status | SKU (awal), nama, bahan aktif, barcode (persis) | kategori (R), jenis (S), terbatas (B), status (S) izin edar (S: berlaku/akan habis/habis/belum ada, T-071), prinsipal (R) | **name**, sku | ✅ `products.view` |
| `product_registrations` | `admin.product-registrations.index` | Produk & SKU, nomor izin, pemegang, terbit, berlaku sampai (+ sisa hari), status, berkas | nomor izin (awal), nama produk, SKU (awal) | status (MS, bawaan `active`), berlaku sampai (DR, bawaan ≤ hari ini + `inventory.registration_reminder_days`), prinsipal (R) | **valid_until**, registration_no | — |
| `principals` | `admin.principals.index` | Kode, nama, kontak, telepon, jumlah supplier, status | kode (awal), nama | aktif (B) | **name**, code | — |
| `suppliers` | `admin.suppliers.index` | Kode, nama, prinsipal, NPWP, kontak, tempo bayar, jumlah produk dipasok, status (rekening tidak ditampilkan, SEC-108) | kode (awal), nama, NPWP (awal) | prinsipal (R), aktif (B) | **name**, code | — |
| `price_lists` | `admin.price-lists.index` | Kode, nama, jenis & target (area/toko), versi berlaku, status | kode (awal), nama | jenis (S), aktif (B) | **kind**, name | — |
| `price_list_items` | `admin.price-lists.items.index` | SKU & produk, satuan, jumlah minimal, harga | SKU (awal), nama produk | versi (R, wajib — diisi dari rute), kategori (R) | **name** (nama produk) | ✅ `prices.view` |
| `promotions` | `admin.promotions.index` | Kode & nama, tipe, periode, prioritas & gabung, sumber dana, status | kode (awal), nama | tipe (S), status (MS), sumber dana (S), berlaku pada (DR) | **-starts_at**, priority | — |
| `purchase_orders` | `admin.purchase-orders.index` | Nomor, tanggal, supplier, gudang tujuan, perkiraan datang (tanda terlambat), total, status | nomor (awal), nama supplier | status (MS), supplier (R), gudang tujuan (R), tanggal PO (DR), terlambat (B) | **-order_date**, number, grand_total | ✅ `purchase_orders.view` |
| `goods_receipts` | `admin.goods-receipts.index` | Nomor, waktu terima, supplier, lokasi stok, nomor PO, nomor surat jalan, status | nomor (awal), nomor SJ supplier (awal), nomor PO (awal) | status (MS), supplier (R), lokasi stok (R), waktu terima (DR, hari lokal) | **-received_at**, number | ✅ `goods_receipts.view` (tanpa kolom biaya, SEC-107) (T-080) |
| `purchase_returns` | `admin.purchase-returns.index` | Nomor, dibuat, supplier, lokasi stok, alasan, jumlah baris, nilai (hanya pemegang harga beli, SEC-107), status | nomor (awal) | status (MS), supplier (R), lokasi stok (R) | **-created_at**, number, total_cost | ❌ (T-081) |
| `serial_numbers` | `admin.serials.index` | Nomor seri, produk & SKU, lokasi · rak, status, cara input | nomor seri (persis & awal), SKU (awal) | produk (R), status (MS), lokasi stok (R, lewat batch) | **-created_at**, serial_no | ✅ `stock.view` (T-080; hanya yang sudah diposting, PA-65) |
| `stock_locations` | `admin.stock-locations.index` | Kode, nama, tipe, lokasi / kendaraan (plat), jumlah rak (tautan ke daftar rak), bisa dijual, status (+ penanda Sistem untuk TRANSIT) | kode, nama | tipe (MS), lokasi (R `locations`), aktif (B) | **code**, name, type | ❌ |
| `stock_bins` | `admin.stock-bins.index` | Kode (gembok untuk Q-01), nama, zona, status | kode | lokasi stok (R `stock_locations` gudang/toko, **wajib**; default gudang/toko aktif pertama dalam scope), zona (MS), aktif (B) | **code**, zone | ❌ |
| `stock_transfers` | `admin.stock-transfers.index` | Nomor, dari → ke, jumlah baris, dikirim pada (tanda terlambat), diterima pada, status | nomor (awal) | status (MS), dari (R `stock_locations`), ke (R `stock_locations`), tanggal kirim (DR), terlambat (B) | **-created_at**, number, shipped_at | ❌ (T-082; scope Stok asal **atau** tujuan) |
| `stock_opnames` | `admin.stock-opnames.index` | Nomor, lokasi, cakupan, hitung buta, jumlah baris, status, dibuat, diposting | nomor (awal) | status (MS), lokasi (R `stock_locations`), cakupan (MS) | **-created_at**, number | ❌ (T-083) |
| `stock_opname_lines` | `admin.stock-opnames.lines.index` | Produk & SKU, batch & ED, rak, dihitung (+ hitung ulang), penghitung; sistem/ekspektasi/selisih/alasan hanya setelah tinjau atau bila bukan hitung buta (SEC-103) | SKU & batch (awal), nama produk | rak (R `stock_bins`), sudah dihitung (B), ada selisih (B; tidak tersedia saat hitung buta & `counting`) | **bin**, product | ❌ (T-083; opname dari parameter rute) |
| `stock_batches` | `admin.stock-batches.index` | Produk & SKU, batch & ED, lokasi · rak (zona), status, on hand, tersedia, aksi Karantina / Pindah Rak / Lepas (penanda "menunggu lepas") | SKU & batch (awal), nama produk | lokasi (R `stock_locations`), status (MS tanpa `disposed`), rak (R `stock_bins`) | **expiry_date**, product, batch_no | ❌ (T-084 halaman Karantina, PA-69; hanya gudang/toko, qty > 0) |
| `disposal_reports` | `admin.disposal-reports.index` | Nomor, lokasi, metode, jumlah batch, nilai (HPP, hanya `stock.view_cost` — SEC-107, PA-72), rencana, dilaksanakan, status | nomor (awal) | status (MS), metode (MS), lokasi (R `stock_locations`) | **-created_at**, number, total_cost | ❌ (T-084) |
| `stock_positions` | `admin.stock.index` | Produk & SKU, lokasi (· rak), batch & ED + status (per batch) / ED terdekat + karantina · ED lewat (per produk), fisik, tersedia, nilai & HPP rata-rata (hanya `stock.view_cost`, SEC-107) | SKU (awal), nama produk, nomor batch (awal) | tampilan (S **wajib**: `per_product` bawaan / `per_batch`), lokasi (R `stock_locations`), kategori (R), prinsipal (R), status batch (MS), ED sampai (DR), di bawah minimum (B), zona rak (MS) | **product**, sku | ✅ `stock.export` (selalu per batch; kolom HPP/nilai hanya `stock.view_cost`) (T-087) |
| `stock_movements` | `admin.stock-movements.index` | Waktu, produk & SKU · batch, lokasi, jenis · dokumen sumber (tautan), qty (±), saldo, biaya satuan (hanya `stock.view_cost`), oleh | nomor dokumen sumber (awal), nomor batch (awal) | tanggal (DR **wajib**, bawaan bulan berjalan, maks 366 hari), produk (R), lokasi (R `stock_locations`), jenis mutasi (MS) | **-moved_at**, business_date | ✅ `stock.export` (biaya hanya `stock.view_cost`) (T-087) |
| `stock_levels` | `admin.stock-levels.index` | Produk & SKU, stok lokasi (merah bila di bawah minimum), minimum, maksimum, aksi Ubah (`stock_locations.manage`) | SKU (awal), nama produk | lokasi stok (R **wajib**; bawaan gudang/toko aktif pertama dalam scope), kategori (R), di bawah minimum (B) | **product**, sku | ❌ (T-087; semua produk aktif, termasuk yang belum diberi batas) |
| `stock_batches_trace` | `admin.stock.trace` | Lokasi · rak, produk & batch & ED, diterima, status, sisa, tautan kartu stok | nomor batch (awal, **wajib**) | produk (R **wajib**) | **location**, received_date | ❌ (T-087; tanpa produk + batch = kosong; termasuk saldo 0) |
| `stock_fefo_violations` | `admin.stock-audit.fefo.index` | Dokumen (tautan), tanggal · pengirim, asal → tujuan, produk & batch dipakai & ED, qty | nomor dokumen (awal), SKU (awal), nomor batch (awal) | lokasi asal (R `stock_locations`), produk (R), tanggal dokumen (DR, hari lokal) | **-created_at**, number | ❌ (T-087, PA-72 `stock.audit_reports`; Paket A = baris mutasi `fefo_override`, Paket B menambah penjualan) |
| `stock_opname_counters` | `admin.stock-audit.counters.index` | Penghitung, opname (tautan) & lokasi, mulai hitung, status, baris dihitung, baris selisih, total selisih (mutlak), hitung ulang beda | nomor opname (awal) | lokasi (R `stock_locations`), penghitung (R `users`), mulai hitung (DR, hari lokal) | **-frozen_at**, variance_lines | ❌ (T-087, PA-72 `stock.audit_reports`; baris = opname × penghitung pertama) |
| `vehicles` | `admin.vehicles.index` | Plat, nama, jenis, lokasi asal, canvas (+ kode lokasi stok), status | plat (per kata, tanpa spasi), nama | jenis (MS), canvas (B), lokasi asal (R `locations`), aktif (B) | **plate_no**, name | ❌ |
| `sales_areas` | `admin.sales-areas.index` | Kode, nama, induk, supervisor, gudang asal, jumlah kecamatan, jumlah kios, status | kode (awal), nama | induk (R `sales_areas`), supervisor (R `sales_employees`), aktif (B) | **code**, name | ❌ |
| `customers` | `admin.customers.index` | Kode, nama toko, pemilik, HP (penuh untuk `customers.manage`, selain itu termasking `081•••••789`), jenis, area, sales, desa, status, terverifikasi | kode (awal), nama toko, nama pemilik, HP (persis, dinormalisasi `62…`) | status (MS, bawaan semua kecuali `merged` — T-074), area (R `sales_areas`), sales (R `sales_employees`), jenis (MS), terverifikasi (B), kecamatan (R `regions` level district; awalan kode desa) — `price_list_id` ditambah T-075 | **store_name**, code, created_at | ✅ `customers.export` (HP selalu termasking, SEC-108) |
| `customer_verifications` | `admin.customer-verifications.index` | Diajukan, kios (kode · nama toko · pemilik), area, jenis (aktivasi/ganti HP), bukti nomor (OTP WhatsApp/telepon kantor), HP tujuan (termasking kecuali `customers.manage`), limit diminta, pengaju, status | nama toko | status (MS, default `pending`), bukti nomor (MS `method`), area (R `sales_areas`) | **created_at** (terlama dulu) | ❌ |

Halaman non-daftar yang tetap butuh paging/cari/filter: **Kalender Jadwal** (`admin.schedules.calendar`: paging per 25 pegawai, cari nama, filter lokasi/departemen/jabatan, pilih bulan) dan **tab Riwayat** di halaman detail (paging 10, filter event).

## 4. Halaman Web

| Halaman | Isi pokok |
|---|---|
| Login | Logo & nama dari setting, field "Username atau email", password (tombol lihat), tombol "Masuk", tautan "Lupa password?". Galat generik "Username/email atau password salah." |
| Lupa password | Field "Username atau email" + pilihan kanal (hanya kanal aktif). Pesan tetap: "Jika akun ditemukan, kami sudah mengirim petunjuk reset." Akun tanpa email: "Hubungi admin/HRD untuk reset password." |
| Wajib ganti password | Tidak bisa ditutup. Password baru + konfirmasi + indikator aturan dari setting. |
| Dasbor | Kartu angka: hadir, telat, belum absen, libur/off hari ini (per lokasi bila scope). Grafik (Recharts, keputusan Mas 16 Nov 2026), semua dalam scope dan hanya untuk pemegang `attendance.view`: **filter rentang** satu baris di atas grafik (pilihan 7 hari / 30 hari / Bulan ini + tanggal dari–sampai, URL `?dari=YYYY-MM-DD&sampai=YYYY-MM-DD`, bawaan 7 hari terakhir; nilai rusak/terbalik/masa depan/lebih panjang dari `ui.dashboard_max_range_days` dirapikan server) berlaku untuk tiga grafik rentang: **kehadiran per hari** (batang bertumpuk tepat waktu/telat/tidak hadir), **sebaran jam masuk** per 15 menit jam lokal (pencilan ≤ 2% presensi di tiap ujung dipangkas dan jumlahnya disebut), **hadir per lokasi** (jumlah hari hadir; hanya bila scope > 1 lokasi). **Komposisi hari ini** (donat tepat waktu/telat/belum presensi + % kehadiran = hadir ÷ (hadir + belum presensi)) tetap hari ini seperti kartu angka. Tiap grafik punya legenda, tooltip, dan tabel tersembunyi untuk pembaca layar; warna seri dari token `--viz-*` (divalidasi buta warna). Data dimuat ulang otomatis tiap `ui.dashboard_refresh_seconds` (partial reload, berhenti saat tab tersembunyi) dengan penanda "Diperbarui pukul …" yang juga tombol muat ulang. Daftar: koreksi menunggu, perangkat menunggu, pegawai tanpa jadwal, pegawai tanpa akun. |
| Pengaturan | Tab per grup registri (§ `08` §2). Tiap field: label, bantuan, nilai default ("Kembalikan default"). Tombol "Simpan perubahan" per tab. Perubahan grup `security` meminta konfirmasi password. |
| Penomoran | Daftar + dialog ubah pola dengan pratinjau 3 nomor berikutnya dan daftar token yang tersedia. |
| Referensi | Pilih grup (tab/select) → daftar + drag untuk urutan. Item sistem berikon gembok. |
| Pengguna (form) | Nama, username (huruf kecil), email (opsional), HP, role (multi), tautkan pegawai (autocomplete, hanya yang belum punya akun), aktif. Password awal: dibuat otomatis & ditampilkan sekali, **atau** "kirim tautan atur password" bila ada email. |
| Role (form) | Nama, deskripsi, scope (radio dengan penjelasan), wajib MFA. Matriks permission dikelompokkan per modul, dengan centang semua per modul. Ringkasan "N permission dipilih". |
| Pegawai (detail) | Header: foto, nama, no. pegawai, status, jabatan & lokasi aktif. Tab: Data Pribadi (NIK termasking), Penugasan (riwayat + tombol Mutasi/Akhiri), Akun & Perangkat, Jadwal, Absensi (daftar mini 30 hari), Riwayat (audit). |
| Nonaktifkan pegawai | Dialog: tanggal keluar, alasan (referensi), catatan, ringkasan dampak ("Akun dinonaktifkan, 2 sesi dan 1 perangkat dicabut"), ketik nama pegawai untuk konfirmasi, konfirmasi password. |
| Impor pegawai | Wizard 4 langkah: Unggah (templat bisa diunduh) → Pemetaan kolom (kolom berkas ↔ field sistem, saran otomatis dari profil/kemiripan nama, simpan sebagai profil) → Pratinjau & validasi (ringkasan valid/galat, tabel baris dengan sel galat merah + pesan) → Proses (progres, laporan akhir). Impor master (T-085) memakai wizard yang sama. **Saldo awal stok (T-086):** dialog Proses menampilkan ringkasan (baris valid, total qty per lokasi, total nilai) dan isian **Tanggal cutover** (bawaan hari ini, harus sesudah tanggal kunci akuntansi); tanpa opsi "Perbarui data yang sudah ada". Setelah saldo awal ditutup, tombol "Impor Saldo Awal Stok" tidak ditampilkan. |
| Absensi harian (detail) | Data jadwal & hasil, dua kartu log (masuk/pulang): waktu, sumber (online/offline), akurasi, jarak, lokasi cocok, dalam/luar radius, penanda. **Foto, koordinat presisi, dan `LocationDiagram` hanya untuk `attendance.view_photo`** (keputusan Mas 15 Nov 2026); foto lewat URL bertanda tangan 5 menit. Riwayat koreksi. Label web memakai istilah **Presensi** ("Presensi Harian"), path tetap `/absensi`. |
| Rekap bulanan | Tabel per pegawai. Klik baris → kalender bulan pegawai itu dengan warna status. |
| Koreksi (detail) | Data pengajuan, absensi asli vs usulan, lampiran, tombol Setujui / Tolak (alasan wajib) untuk yang berhak. |
| Isi presensi manual | Dialog dari daftar Koreksi Presensi dan detail Presensi Harian (pegawai & tanggal terisi), hanya untuk pemegang `attendance_corrections.approve`: pegawai (lookup ber-scope), tanggal (batas BR-069), yang diisi (masuk & pulang / masuk / pulang), jam, alasan (`correction_reason` aktif), catatan. Tombol "Simpan Presensi". Hasil = koreksi disetujui bertanda "Presensi manual". |
| Tugas Luar Kota | Daftar + form (tanggal, tujuan, keperluan, pegawai banyak, aturan lokasi: di mana saja / radius titik tujuan (1–10 titik, tiap titik: nama tempat + tempel tautan Google Maps + radius) / lokasi Pakis mana pun). Batalkan dengan konfirmasi. Tab "Tugas Luar Kota" di detail pegawai (grup Kehadiran). |
| Jadwal | Daftar pola + aksi massal "Tetapkan pola" (pilih pegawai via filter/centang, berlaku mulai, shift per hari). Kalender: grid pegawai × tanggal, klik sel untuk override. |
| Perangkat | Daftar dengan aksi baris Setujui/Tolak/Cabut. Pending disorot. |
| Profil & Keamanan | Data diri, ubah password, MFA (QR + kode konfirmasi + kode pemulihan sekali tampil), daftar sesi aktif + perangkat mobile, "Keluarkan semua sesi lain". |

## 5. Pakis Pro (mobile)

Struktur dan gaya mengikuti prototipe (header hijau, kartu, tab bawah). Teks besar & target sentuh ≥ 48 dp.

| Layar | Elemen & perilaku |
|---|---|
| Splash / cek versi | Ambil `/config`. Bila versi < min → layar "Perbarui aplikasi" (tombol unduh, tanpa jalan pintas). Bila offline → pakai config terakhir. |
| Login | Logo, "Username atau email", password, "Masuk", "Lupa password?". Bila `MFA_REQUIRED` → field kode 6 digit. Bila `DEVICE_PENDING` → layar "Menunggu persetujuan perangkat" + tombol "Coba lagi". |
| Lupa password | Field login + kanal. Kanal email membuka konfirmasi "cek email". Kanal WA (bila aktif) → layar OTP + password baru. |
| Wajib ganti password | Penuh layar, tidak bisa dilewati. |
| Absen (beranda) | Kartu shift hari ini (jam, toleransi). Status: belum presensi / sudah masuk HH.mm / selesai. Tombol bulat besar "Masuk"/"Pulang" (keputusan Mas 16 Nov 2026). Indikator GPS (akurasi, jarak ke lokasi) + indikator antrean offline ("2 presensi menunggu sinyal"). |
| Alur absen | Ketuk tombol → kamera depan (bingkai wajah, tanpa galeri) → ambil → cek GPS (spinner ≤ 15 s, kalau akurasi buruk: "Pindah ke tempat terbuka") → kirim → hasil (berhasil/telat N menit/galat dengan arahan). Offline: "Tersimpan di HP, akan dikirim otomatis". |
| Riwayat | Daftar paging (infinite scroll 20), filter bulan & status (bottom sheet), pencarian, chip filter aktif. Header rekap bulan (hadir, telat, alpa). |
| Koreksi saya | Daftar + tombol "Ajukan koreksi" → form (tanggal maks `correction_max_days` ke belakang, jenis, jam, alasan, catatan, lampiran foto). |
| Persetujuan | (bila berhak) daftar menunggu, detail, Setujui/Tolak (alasan wajib). |
| Notifikasi | Daftar paging, tandai dibaca. |
| Profil | Data diri, ubah password, info perangkat, "Keluar dari sesi web lain", Keluar. |

## 6. Label Status (konsisten web & mobile)

| Nilai | Label | Warna |
|---|---|---|
| `present` | Hadir | success |
| `late` | Telat | accent |
| `early_leave` | Pulang cepat | accent |
| `no_checkout` | Tidak absen pulang | accent |
| `absent` | Tidak hadir | danger |
| `holiday` | Libur | info |
| `off` | Tidak dijadwalkan | muted |
| Koreksi `pending`/`approved`/`rejected`/`cancelled` | Menunggu / Disetujui / Ditolak / Dibatalkan | accent / success / danger / muted |
| Tugas luar kota `pending`/`approved`/`rejected`/`cancelled` | Menunggu / Disetujui / Ditolak / Dibatalkan | accent / success / danger / muted |
| Perangkat `pending`/`active`/`revoked`/`replaced`/`rejected` | Menunggu / Aktif / Dicabut / Diganti / Ditolak | accent / success / danger / muted / danger |
| Pegawai `active`/`inactive` | Aktif / Nonaktif | success / muted |
| Persetujuan `pending`/`approved`/`rejected`/`cancelled` | Menunggu / Disetujui / Ditolak / Dibatalkan | accent / success / danger / muted |
| Langkah persetujuan `waiting`/`pending`/`approved`/`rejected`/`skipped` | Belum giliran / Menunggu / Disetujui / Ditolak / Dilewati | muted / accent / success / danger / muted |
| Delegasi `active`/`scheduled`/`expired`/`revoked` | Berlaku / Terjadwal / Berakhir / Dicabut | success / info / muted / danger |
| Lokasi stok aktif/nonaktif · lokasi sistem (TRANSIT) | Aktif / Nonaktif · Sistem | success / muted · info |
| Zona rak `general`/`pesticide`/`restricted`/`quarantine` | Umum / Pestisida / Terbatas / Karantina | muted / accent / danger / info |
| Rak & kendaraan aktif/nonaktif | Aktif / Nonaktif | success / muted |

## 7. Format & Copy

- Tanggal: `3 Nov 2026`, panjang `Selasa, 3 November 2026`. Jam: `07.41` (format Indonesia). Angka: `1.250`. Rupiah: `Rp. 1.250.000` (titik setelah "Rp" + spasi; sen `Rp. 12.500,50`; keputusan Mas 17 Nov 2026), dari `formatRupiah` (`lib/money.ts`) di web dan `Rupiah::format` di server.
- Tombol memakai kata kerja jelas: "Simpan Pegawai", "Nonaktifkan Pegawai", "Setujui Koreksi", "Masuk" / "Pulang" (presensi).
- Galat validasi menjelaskan cara memperbaiki: "NIK harus 16 digit angka (sekarang 15)."
- Pesan sukses singkat (toast 3 detik) + tetap di halaman yang masuk akal.
