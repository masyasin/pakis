# Runbook Cutover Paket A — Master & Saldo Awal Stok

Panduan pindah dari pencatatan lama ke PAKIS ONE untuk modul gudang dan penjualan (T-086, BR-170, UAT-A19). Ditulis untuk tim Pakis: Owner, Purchasing, Admin Penjualan, dan Kepala Gudang.

**Prinsip:** hanya ada satu hari cutover. Sebelum hari itu, sistem lama tetap dipakai. Sesudah saldo awal ditutup, semua transaksi stok hanya dicatat di PAKIS ONE.

## Siapa mengerjakan apa

| Peran | Tugas |
|---|---|
| Owner | Memutuskan tanggal cutover, menyetujui angka akhir, **menutup saldo awal** |
| Purchasing | Impor produk, harga, dan saldo awal stok (punya izin melihat biaya) |
| Admin Penjualan | Impor kios |
| Kepala Gudang | Opname fisik, mengisi lembar stok per gudang/toko |
| Staf keuangan | Mencocokkan total nilai dengan pembukuan lama |

## H-7 sampai H-1: persiapan

1. **Tentukan tanggal cutover.** Pilih hari tutup buku (mis. akhir bulan). Tanggal ini menjadi tanggal mutasi dan jurnal saldo awal, jadi harus **sesudah** tanggal kunci akuntansi di Pengaturan.
2. **Impor data master** lewat menu Master › Impor Master, urutannya:
   1. **Produk** (templat "Produk"). SKU dari berkas dipakai apa adanya.
   2. **Kios** (templat "Kios"). Kios yang sudah diverifikasi bisa langsung aktif bila pengimpor memegang izin verifikasi dan mengonfirmasi password.
   3. **Harga** (templat "Harga"). Masuk ke versi draf. Terbitkan dari menu Daftar Harga setelah dicek.
   4. **Stok minimum** (opsional).
3. **Cek lokasi stok & rak.** Setiap gudang/toko dan rak yang akan diisi harus sudah ada dan aktif di Master › Lokasi Stok.
4. **Jangan mencatat transaksi stok apa pun di PAKIS ONE** untuk gudang/toko yang akan diisi saldo awal (penerimaan, mutasi, opname). Lokasi yang sudah bertransaksi akan menolak saldo awal (`LOCATION_HAS_MOVEMENTS`).
5. **Uji coba di staging** dengan sebagian berkas. Pastikan ringkasan dan total nilai sesuai.

**Cara mundur:** data master bisa diperbaiki lewat halaman masing-masing atau impor ulang dengan centang "Perbarui data yang sudah ada" (kios tidak pernah ditimpa).

## Hari H

### 1. Bekukan transaksi lama
- Hentikan penerimaan, pengiriman, dan mutasi barang di sistem lama sejak jam yang disepakati.
- Barang yang datang selama pembekuan dicatat manual di kertas, lalu dimasukkan sebagai penerimaan barang di PAKIS ONE **setelah** saldo awal ditutup.

### 2. Opname fisik
- Kepala Gudang menghitung semua barang per gudang/toko: SKU, no. batch, tanggal ED, rak, jumlah (satuan dasar, mis. botol, bukan dus).
- Barang rusak atau kedaluwarsa tetap dicatat, di rak karantina bila ada.
- **Produk bernomor seri** (mis. alat semprot) tidak masuk saldo awal. Catat lewat Penerimaan Barang beserta nomor serinya setelah cutover (PA-71).

### 3. Isi templat saldo awal
- Unduh templat dari Master › Impor Master › **Impor Saldo Awal Stok**.
- Kolom: Kode Lokasi Stok, SKU, No. Batch, Tanggal ED, Kode Rak, Qty, Biaya per Satuan.
- **Biaya per Satuan** = harga pokok per satuan dasar dari pembukuan lama. Biaya ini menjadi HPP awal (rata-rata tertimbang bila satu produk ada di beberapa baris).
- Batas satu berkas 30.000 baris (pengaturan "Baris impor saldo awal stok maks"). Bila lebih, bagi per gudang.

### 4. Impor & periksa ringkasan
1. Unggah berkas, cek pemetaan kolom, lalu tunggu validasi.
2. Perbaiki baris galat: unduh berkas galat, betulkan, lalu unggah ulang **hanya** baris itu atau seluruh berkas. Baris yang sudah masuk akan dilewati otomatis, jadi tidak ada data ganda.
3. Sebelum menekan **Proses**, baca ringkasan: jumlah baris valid, total qty per lokasi, dan **total nilai**.
4. Isi **Tanggal cutover**, lalu Proses.

### 5. Cocokkan dengan pembukuan lama
- Bandingkan total nilai di ringkasan (dan laporan posisi stok bila sudah tersedia) dengan saldo persediaan di pembukuan lama per tanggal cutover.
- Selisih dicari sumbernya: salah satuan (dus vs botol), biaya salah, baris tertinggal.
- **Baris tertinggal:** impor baris itu sebelum saldo awal ditutup.
- **Qty salah:** baris yang sudah masuk tidak bisa ditimpa lewat impor. Koreksi lewat **Stock Opname** di lokasi itu setelah semua baris saldo awalnya masuk. Opname membuat lokasi itu tidak bisa menerima saldo awal lagi, jadi lakukan paling akhir.
- **Biaya salah:** biaya (HPP) yang sudah masuk tidak bisa dikoreksi lewat impor. Karena itu periksa kolom biaya dan total nilai di ringkasan **sebelum** menekan Proses. Uji dulu di staging.

### 6. Tutup saldo awal (Owner)
- Pengaturan › **Gudang & stok** › matikan **"Saldo awal stok masih dibuka"**, lalu simpan. Sistem meminta konfirmasi password.
- Penutupan dicatat di Audit Log (`inventory.opening_balance_closed`) beserta jumlah mutasi dan total nilainya.
- **Tidak bisa dibuka lagi.** Stok berikutnya hanya lewat penerimaan barang, mutasi, atau opname.

### 7. Mulai transaksi di PAKIS ONE
- Catat barang yang datang selama pembekuan (langkah 1) sebagai Penerimaan Barang.
- Mulai penjualan, mutasi, dan opname hanya di PAKIS ONE.

## Daftar cek akhir (UAT-A19)

- [ ] Total nilai saldo awal = saldo persediaan pembukuan lama per tanggal cutover
- [ ] Setiap gudang/toko yang dipakai sudah terisi; tidak ada lokasi yang tertinggal
- [ ] Impor ulang berkas yang sama memberi 0 baris baru
- [ ] Saldo awal ditutup, dan impor saldo awal berikutnya ditolak ("Saldo awal stok sudah ditutup")
- [ ] Produk bernomor seri sudah dicatat lewat Penerimaan Barang
- [ ] Sistem lama berhenti dipakai untuk transaksi stok
