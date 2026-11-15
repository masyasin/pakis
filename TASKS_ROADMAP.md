# TASKS_ROADMAP — Outline Paket A–G (T-067 s.d. T-192)

> **Ini outline, bukan task siap eksekusi.** Agent dilarang mengerjakan task dari file ini. Setiap paket dirinci menjadi `TASKS_PAKET_X.md` (format penuh seperti `TASKS.md`: konteks wajib, file, kriteria penerimaan, verifikasi) dan dokumen `docs/1x-PAKET-X-….md` sebelum dikerjakan. Saat dirinci, satu baris outline boleh dipecah atau digabung asalkan nomor tetap dalam rentang paketnya.

Sumber kebenaran kerangka: `docs/14-ROADMAP-LENGKAP.md`. Kode modul = konsep v1.4 §5. BR-1NN = BR-NN konsep (§13). Estimasi = jam efektif developer + Claude Code, kasar.

**Aturan umum setiap paket saat dirinci:**
- Task pertama paket menambahkan role & permission paket itu ke `06` §2–§4 + `PermissionCatalog` + test matriks.
- Setiap daftar mengikuti standar listing (`07` §3.3) dan masuk katalog + test kontrak.
- Setiap transaksi bernilai uang/stok memancarkan event `accounting_events` sesuai `14` §7 (event stok mulai Paket A, event penjualan & uang mulai Paket B).
- Task terakhir paket = hardening, uji keamanan, UAT, dan pilot.

## Ringkasan

| Paket | Rentang | Jumlah | Estimasi | Status |
|---|---|---|---|---|
| A — Master & Gudang | T-067–T-087 | 21 | ±160 jam | Outline. Dirinci berikutnya (Lapis 2) |
| B — Penjualan & Uang Aman | T-088–T-113 | 26 | ±197 jam | Outline. Dirinci berikutnya (Lapis 2) |
| C — Lapangan & Mitra | T-114–T-140 | 27 | ±244 jam | Outline |
| D — Armada & BBM | T-141–T-153 | 13 | ±101 jam | Outline |
| E — SDM Lanjut & Payroll | T-049–T-066 (ada) + T-154–T-158 | 18 + 5 | ±132 + ±35 jam | T-049–T-066 sudah detail di `TASKS.md` (ditunda). T-154–T-158 outline |
| F — Keuangan & Prinsipal | T-159–T-178 | 20 | ±172 jam | Outline |
| G — Cerdas & Kinerja | T-179–T-192 | 14 | ±142 jam | Outline |
| **Total sisa** | | **126 baru** | **±1.050 jam** + Fase HR ±132 jam | |

---

## Paket A — Master & Gudang (T-067–T-087)

> ✅ **Sudah dirinci:** `TASKS_PAKET_A.md` + `docs/15-PAKET-A-MASTER-GUDANG.md`. Baris di bawah hanya ringkasan; bila berbeda, yang berlaku file rinci.

Tujuan: data produk, kios, harga, dan stok berbasis batch yang benar sebelum penjualan dibangun.
Exit gate: stok tidak pernah minus pada uji konkurensi, FEFO teruji, kartu stok cocok dengan saldo, master hasil import tervalidasi.

| ID | Task | Modul | Aturan / ADR | Est | Dependensi |
|---|---|---|---|---|---|
| T-067 | Mesin approval generik (aturan per jenis dokumen + ambang + rantai role, maker-checker, delegasi, eskalasi) + role & permission Paket A | CORE-03 | ADR-105 | 10 | Fase 0 |
| T-068 | Lokasi stok & rak: `stock_locations` (gudang, toko, kendaraan, in_transit), `stock_bins`, zona pestisida, kendaraan minimal | CORE-02, INV-01 | ADR-107 | 6 | T-021 |
| T-069 | Kategori produk, satuan & konversi satuan | MST-01 | ADR-102 | 5 | T-010 |
| T-070 | Produk: data, bahan aktif, foto, barcode, penanda produk terbatas & produk bernomor seri | MST-01 | BR-112 | 8 | T-069 |
| T-071 | Izin edar produk: nomor pendaftaran, masa berlaku, blokir jual otomatis, pengingat | MST-02 | BR-102 | 5 | T-070 |
| T-072 | Prinsipal & supplier, syarat bayar, produk yang dipasok | MST-03 | — | 5 | T-070 |
| T-073 | Area penjualan & penugasan sales ke kios (dimensi scope `customer`) | MST-04 | ADR-106 | 6 | T-019 |
| T-074 | Pelanggan/kios: data, titik peta, verifikasi (foto, lokasi, OTP pemilik), deteksi duplikat & gabung, ubah nomor HP terkontrol, limit & tempo | MST-04 | BR-125, BR-126 | 10 | T-073, T-067 |
| T-075 | Price list berversi per tier/area, tanggal berlaku | MST-05 | ADR-108 | 8 | T-070, T-073 |
| T-076 | Promo & diskon bertingkat, bundling, promo musiman | MST-05 | — | 8 | T-075 |
| T-077 | Inti stok: `StockLedger`, `stock_batches`, `stock_movements`, reservasi, row lock, HPP moving average (`product_costs`), tabel outbox `accounting_events` | INV-01, INV-02 | BR-101, BR-114, ADR-103, ADR-104 | 12 | T-068, T-070 |
| T-078 | Alokasi FEFO, karantina otomatis (ED/izin habis), alert mendekati ED | INV-02 | BR-102 | 6 | T-077, T-071 |
| T-079 | Purchase order + persetujuan | PRC-01 | — | 8 | T-072, T-067 |
| T-080 | Penerimaan barang: batch, ED, nomor pendaftaran, selisih terima, sisa umur di bawah batas, nomor seri alat | PRC-01, GAR-01 | BR-103, BR-144 | 10 | T-079, T-077 |
| T-081 | Retur ke prinsipal | PRC-01 | — | 5 | T-080 |
| T-082 | Mutasi stok antar lokasi (kirim–terima, in_transit, selisih) | INV-03 | — | 7 | T-077 |
| T-083 | Stock opname hitung buta + persetujuan selisih | INV-04 | — | 8 | T-077, T-067 |
| T-084 | Barang rusak, karantina manual, pemusnahan & berita acara | INV-05 | OQ-115 | 6 | T-078 |
| T-085 | Import master: produk, kios, harga (pemetaan kolom, validasi, idempoten) | CORE-06 | — | 8 | T-070, T-074, T-075 |
| T-086 | Saldo awal stok per batch & prosedur cutover | CORE-06 | — | 6 | T-077, T-085 |
| T-087 | Laporan stok (kartu stok, posisi per lokasi, ED, telusur batch & recall) + hardening & UAT Paket A | INV, BI-01 | — | 13 | T-078–T-086 |

## Paket B — Penjualan & Uang Aman (T-088–T-113)

> ✅ **Sudah dirinci:** `TASKS_PAKET_B.md` + `docs/16-PAKET-B-PENJUALAN-UANG.md` (211 jam setelah dirinci). Baris di bawah hanya ringkasan; bila berbeda, yang berlaku file rinci.

Tujuan: pesanan sampai uang masuk di web, kontrol anti-penggelapan dasar, POS toko.
Exit gate: faktur = jumlah diterima, alokasi pembayaran teruji, kwitansi tidak bisa diedit, setoran H+1 & plafon tunai menegakkan blokir, konfirmasi saldo terkirim, event akuntansi tercatat untuk setiap transaksi.

**B-ringan** (opsi percepatan, OQ-110): T-096–T-104 + T-112 bisa dikerjakan setelah T-067, T-073, T-074, T-085 (bagian kios) tanpa menunggu pesanan & gudang selesai.

| ID | Task | Modul | Aturan / ADR | Est | Dependensi |
|---|---|---|---|---|---|
| T-088 | Pesanan penjualan web (kanal kantor): harga & promo terkunci, idempotency, cek izin & produk terbatas + role & permission Paket B | SAL-01 | BR-104, BR-112, ADR-108 | 10 | Paket A |
| T-089 | Kredit & tempo (standar, panen), hold otomatis, approval pengecualian (over limit, tunggakan, diskon) | SAL-02 | BR-105, BR-106 | 8 | T-088, T-067 |
| T-090 | Reservasi, picking FEFO, kirim sebagian & backorder | SAL-01 | BR-101, BR-102 | 7 | T-088, T-078 |
| T-091 | Surat jalan & pengelompokan rute (web) | DLV-01 | — | 7 | T-090 |
| T-092 | Bukti terima diinput di web + selisih/retur saat terima | DLV-01 | BR-107 | 5 | T-091 |
| T-093 | Faktur dari bukti terima, PDF, rekening resmi & peringatan di dokumen | FIN-01 | BR-107, BR-132, ADR-114, OQ-101, OQ-109 | 9 | T-092 |
| T-094 | Retur penjualan & nota kredit (cek batch, retur ED ke karantina) | SAL-03 | — | 8 | T-093 |
| T-095 | Permintaan tak terpenuhi (lost sales) | SAL-05 | — | 3 | T-088 |
| T-096 | Event akuntansi penjualan & uang (pakai outbox dari T-077) + tanggal kunci dokumen keuangan | FIN-02 (persiapan) | BR-113, ADR-104, ADR-115 | 6 | Paket A (bagian kios untuk B-ringan) |
| T-097 | Pembayaran & alokasi otomatis ke faktur tertua, titipan, realokasi dengan persetujuan kedua | FIN-01 | BR-108, BR-130 | 9 | T-093 (atau T-112 untuk B-ringan), T-096 |
| T-098 | Giro: terima, cair, ditolak, tanda risiko pelanggan | FIN-01 | BR-109 | 5 | T-097 |
| T-099 | VA per kios + webhook + daftar pembayaran belum teridentifikasi | FIN-01 | OQ-102 | 10 | T-097 |
| T-100 | Kwitansi bernomor server, tidak bisa diedit, pembatalan dengan persetujuan Finance | FLD-03 | BR-127 | 6 | T-097 |
| T-101 | Plafon tunai per sales + setoran harian + verifikasi kasir pusat, blokir otomatis | FLD-03 | BR-110, BR-129 | 9 | T-100 |
| T-102 | Piutang karyawan dari selisih setoran + alert Owner | FLD-03 | BR-133 | 5 | T-101 |
| T-103 | Konfirmasi saldo: jadwal bulanan + sampel mingguan, halaman respons kios bertoken, laporan "tidak sesuai" ke Finance & Owner (kanal WA via kontrak adapter) | AUD-01 | BR-128, BR-131 | 10 | T-097 |
| T-104 | Aging & laporan piutang + dasbor kontrol uang | FIN-01, BI-01 | — | 6 | T-097–T-103 |
| T-105 | Kas kecil imprest: pengajuan dengan foto nota, persetujuan, isi ulang, hitung fisik | FIN-04 | BR-146, BR-147 | 8 | T-067, T-096 |
| T-106 | POS backend: register, shift kasir, penjualan eceran, retur toko | POS-01 | — | 9 | T-077, T-075 |
| T-107 | POS PWA offline + struk | POS-01 | ADR-110 | 12 | T-106 |
| T-108 | Nomor seri saat jual + kartu garansi digital | GAR-01 | BR-144, BR-145 | 5 | T-093, T-080 |
| T-109 | Dasbor owner penjualan & laporan standar (omzet, margin, stok kritis, piutang) | BI-01 | — | 8 | T-093, T-104 |
| T-110 | Katalog publik terhubung master produk (tanpa produk terbatas) | WEB-01 | BR-112, OQ-108 | 7 | T-070 |
| T-111 | Notifikasi transaksi (faktur, kwitansi, status kirim) lewat kanal notifikasi yang ada + template | CORE-04 | BR-128 | 5 | T-093, T-100 |
| T-112 | Import saldo awal piutang (faktur lama) & cutover | CORE-06 | — | 6 | T-074, T-096 |
| T-113 | Hardening, uji keamanan & UAT Paket A + B, pilot go-live | — | `09` §7.2 | 14 | Semua T-088–T-112 |

## Paket C — Lapangan & Mitra (T-114–T-140)

Tujuan: semua peran lapangan bekerja dari Pakis Pro (offline-first) dan kios memesan & membayar dari Pakis Mitra.
Exit gate: sinkron offline tanpa data ganda, kwitansi offline dari blok server, rekonsiliasi canvas teruji, red flag berjalan, IDOR antar kios teruji.

| ID | Task | Modul | Aturan / ADR | Est | Dependensi |
|---|---|---|---|---|---|
| T-114 | Kontrak & mesin sinkron offline (server): snapshot master berversi, outbox klien, konflik + role & permission Paket C | MOB-01 | ADR-111 | 10 | Paket B |
| T-115 | Sinkron offline di Pakis Pro (DB lokal terenkripsi, antrean, backoff) | MOB-01 | ADR-111 | 10 | T-114 |
| T-116 | Rencana kunjungan & rute harian | FLD-01 | — | 7 | T-114 |
| T-117 | Check-in kunjungan (GPS + foto toko, deteksi lokasi palsu, catatan kios) | FLD-01 | BR-143 | 8 | T-116 |
| T-118 | Pesanan dari Pakis Pro (offline, harga terkunci ≤ 24 jam) | SAL-01 | BR-104 | 10 | T-115, T-088 |
| T-119 | Penagihan di Pakis Pro: daftar tagih, kwitansi + blok nomor offline | FLD-03 | BR-127, ADR-112 | 12 | T-115, T-100 |
| T-120 | Plafon tunai & setoran di Pakis Pro | FLD-03 | BR-129 | 6 | T-119, T-101 |
| T-121 | Canvas: muat stok ke kendaraan, jual & nota printer Bluetooth, rekonsiliasi akhir hari | FLD-02 | BR-111, BR-133 | 16 | T-115, T-077 |
| T-122 | Harga & aktivitas pesaing | FLD-04 | — | 4 | T-117 |
| T-123 | Mode driver: daftar antar, navigasi, POD foto + tanda tangan, selisih, COD | DLV-01 | BR-107 | 12 | T-115, T-092 |
| T-124 | Mode gudang: scan terima (batch/ED/seri), picking berpanduan rak, opname | INV-01..04 | BR-102, BR-103 | 12 | T-115, Paket A |
| T-125 | Mode owner: ringkasan harian & persetujuan dari HP | BI-01, CORE-03 | — | 7 | T-115, T-067 |
| T-126 | Driver WhatsApp nyata + template pesan resmi | CORE-04 | OQ-004 | 8 | T-111 |
| T-127 | Push notification Pakis Pro & Pakis Mitra | CORE-04 | OQ-005 | 6 | T-115 |
| T-128 | QRIS | FIN-01 | OQ-102 | 6 | T-099 |
| T-129 | Mesin red flag rule-based + dasbor (indikator konsep §18.5) | AUD-02 | — | 12 | T-117, T-119 |
| T-130 | Kasus investigasi: bekukan hak transaksi, paket bukti, pemulihan saldo kios | AUD-02 | BR-133 | 8 | T-129 |
| T-131 | Target & insentif sales (per sales/area/prinsipal) | SAL-04 | BR-118 | 10 | T-093 |
| T-132 | Promosi & demplot: rencana, persetujuan, realisasi berlokasi, produk keluar sebagai biaya | MKT-01 | BR-148 | 8 | T-067, T-077 |
| T-133 | Servis & klaim garansi (tiket, pemeriksaan, klaim prinsipal, servis berbayar) | GAR-02 | BR-145 | 8 | T-108 |
| T-134 | Rotasi area tagih & cuti wajib sales | FLD-03 | BR-134 | 4 | T-073 |
| T-135 | Pakis Mitra backend: guard `mitra`, akun kios, OTP, perangkat | MOB-02 | ADR-109 | 10 | T-074 |
| T-136 | Pakis Mitra: katalog harga khusus, cari per tanaman/hama/bahan aktif, keranjang, pesan ulang, status pesanan | MOB-02 | BR-112 | 12 | T-135, T-088 |
| T-137 | Pakis Mitra: tagihan, sisa limit, bayar VA/QRIS, unduh faktur & kwitansi, konfirmasi saldo | MOB-02 | BR-131 | 10 | T-136, T-103 |
| T-138 | Pakis Mitra: retur & keluhan berfoto, garansi, promo & poin | MOB-02 | — | 8 | T-136 |
| T-139 | Keamanan API Mitra (IDOR antar kios, rate limit, tanpa HPP di respons) + rilis APK Mitra | MOB-02 | — | 6 | T-135–T-138 |
| T-140 | Hardening & UAT Paket C, pilot lapangan | — | `09` §7.2 | 14 | Semua T-114–T-139 |

## Paket D — Armada & BBM (T-141–T-153)

Tujuan: setiap liter BBM dan kilometer terhubung ke trip nyata.
Exit gate: trip tidak bisa dimulai tanpa kelayakan, cek BBM wajar teruji, km/liter & selisih odometer–GPS menjadi red flag.

| ID | Task | Modul | Aturan / ADR | Est | Dependensi |
|---|---|---|---|---|---|
| T-141 | Kendaraan: kapasitas muat & tangki, jenis BBM, target km/liter, pemegang + role & permission Paket D | FLT-01 | BR-137 | 6 | T-068 |
| T-142 | Dokumen kendaraan (STNK, pajak, KIR, asuransi) & pengingat | FLT-01 | BR-135 | 5 | T-141 |
| T-143 | Cek harian kendaraan (P2H) & kelayakan trip, override tercatat | FLT-03 | BR-135 | 7 | T-142 |
| T-144 | Trip dari surat jalan, odometer awal/akhir, serah terima driver | FLT-03 | — | 8 | T-143, T-091 |
| T-145 | Uang jalan & biaya trip, pertanggungjawaban H+1, selisih ke piutang karyawan | FLT-03 | BR-141, BR-133 | 7 | T-144, T-102 |
| T-146 | Harga BBM berversi & pengisian BBM dengan cek wajar | FLT-04 | BR-136, BR-137 | 10 | T-144 |
| T-147 | Km per liter per trip/bulan & red flag BBM/odometer | FLT-04 | BR-138, BR-139 | 7 | T-146, T-129 |
| T-148 | GPS ponsel driver/sales saat mode trip atau kunjungan | FLT-02 | BR-143 | 8 | T-144 |
| T-149 | Adapter GPS tracker (vendor API atau Traccar) | FLT-02 | OQ-105, ADR-113 | 12 | T-148 |
| T-150 | Geofence gudang & kios, alert di luar jam operasional & berhenti lama | FLT-02 | BR-140 | 8 | T-149 |
| T-151 | Servis & perawatan: jadwal per km/waktu, perintah servis, foto suku cadang, ban | FLT-05 | BR-142 | 9 | T-141, T-067 |
| T-152 | Laporan armada & biaya per km/per pengiriman | FLT | — | 6 | T-147, T-151 |
| T-153 | Hardening & UAT Paket D | — | `09` §7.2 | 8 | Semua T-141–T-152 |

## Paket E — SDM Lanjut & Payroll (T-049–T-066 + T-154–T-158)

Task T-049–T-066 sudah ditulis detail di `TASKS.md` (sumber `13-FASE-HR.md`) dan **ditunda**. T-049 + T-060 boleh dimajukan (`14` §3.2). Aturan konsep BR-115–BR-124 diselaraskan dengan `13-FASE-HR.md` saat paket ini dirinci: BR-115–BR-116 (absensi & koreksi) sudah dipenuhi Fase 0,5 (BR-064–BR-069, lihat `14` §1 P-9), BR-117 → T-061, BR-119 → T-062/T-064, BR-120–BR-121 → T-064. Tambahan:

| ID | Task | Modul | Aturan / ADR | Est | Dependensi |
|---|---|---|---|---|---|
| T-154 | Insentif sales & driver dari periode transaksi tertutup ke payroll, penyesuaian retur di periode berikutnya | HR-05 | BR-118 | 8 | T-131, T-064 |
| T-155 | Masuk pegawai: checklist onboarding, persetujuan kedua untuk pegawai baru & perubahan rekening | HR-08 | BR-122 | 6 | T-059 |
| T-156 | Keluar pegawai: clearance setoran, stok canvas, aset (HP, kendaraan, kunci), kasbon sebelum offboarding | HR-08 | BR-123 | 8 | T-026, T-101, T-121, T-062 |
| T-157 | Prorata masuk/keluar/mutasi di tengah periode gaji | HR-05 | BR-124 | 5 | T-064 |
| T-158 | Hardening & UAT Paket E (uji perhitungan payroll dengan konsultan pajak Pakis) | — | BR-120 | 8 | T-049–T-066, T-154–T-157 |

## Paket F — Keuangan & Prinsipal (T-159–T-178)

Tujuan: jurnal otomatis dari seluruh transaksi, laporan keuangan, pajak, hutang, program prinsipal.
Exit gate: semua event `pending` sejak Paket B terposting seimbang (backfill), neraca seimbang, tutup buku teruji.

| ID | Task | Modul | Aturan / ADR | Est | Dependensi |
|---|---|---|---|---|---|
| T-159 | Bagan akun & pemetaan peran akun (`account_mappings`) + role & permission Paket F | FIN-02 | — | 7 | T-096 |
| T-160 | Mesin posting dari outbox + backfill transaksi lama | FIN-02 | BR-113, ADR-104 | 12 | T-159 |
| T-161 | Jurnal manual & pembalik, periode akuntansi & tutup buku | FIN-02 | ADR-115 | 8 | T-160 |
| T-162 | Rekening bank & impor mutasi (parser per bank) | FIN-02 | OQ-103 | 8 | T-159 |
| T-163 | Rekonsiliasi bank (cocokkan pembayaran & setoran) | FIN-02 | — | 8 | T-162 |
| T-164 | Laporan keuangan: neraca, laba rugi, arus kas | FIN-02 | — | 12 | T-160 |
| T-165 | Laporan HPP & margin per produk/kios/sales/area | FIN-02, BI-01 | BR-114 | 7 | T-160 |
| T-166 | Faktur pembelian & hutang dagang | PRC-03 | — | 9 | T-080, T-160 |
| T-167 | Pembayaran ke supplier + persetujuan | PRC-03 | — | 6 | T-166, T-067 |
| T-168 | Program prinsipal: target, rebate, potongan | PRC-02 | — | 10 | T-166 |
| T-169 | Klaim ke prinsipal & status pencairan | PRC-02 | — | 7 | T-168 |
| T-170 | PPN keluaran/masukan (bila PKP) | FIN-03 | OQ-101 | 10 | T-160 |
| T-171 | Ekspor data faktur pajak ke format sistem DJP yang berlaku | FIN-03 | OQ-101, ADR-113 | 8 | T-170 |
| T-172 | Ekspor jurnal ke software akuntansi luar (alternatif modul F) | FIN-02 | OQ-106 | 6 | T-160 |
| T-173 | Konsinyasi: stok titipan, laporan terjual, tagihan, retur sisa | SAL-06 | — | 12 | T-077, T-093 |
| T-174 | Aset tetap & penyusutan otomatis, mutasi & penghapusan aset | FIN-05 | — | 10 | T-160 |
| T-175 | Saran pembelian: reorder point & stok minimum per musim | INV-06 | — | 9 | T-077, T-095 |
| T-176 | Member petani & poin (data minimal) | POS-02 | — | 8 | T-106 |
| T-177 | Jurnal gaji dari payroll | FIN-02, HR-05 | — | 5 | T-160, T-064 |
| T-178 | Hardening & UAT Paket F + uji tutup buku bulanan | — | `09` §7.2 | 10 | Semua T-159–T-177 |

## Paket G — Cerdas & Kinerja (T-179–T-192)

Tujuan: analitik, AI dengan pengaman, portal & kanal tambahan. Prasyarat: data operasional ±6 bulan.
Exit gate: setiap fitur AI punya alternatif non-AI, golden set lulus, tidak ada data pribadi ke layanan AI luar.

| ID | Task | Modul | Aturan / ADR | Est | Dependensi |
|---|---|---|---|---|---|
| T-179 | Data mart & ringkasan harian + role & permission Paket G | BI-01 | — | 10 | Paket B–D |
| T-180 | Laporan terjadwal & ringkasan mingguan owner (angka dari laporan resmi + tautan sumber) | BI-01 | — | 8 | T-179 |
| T-181 | Basis pengetahuan dari label & data pendaftaran resmi (chunking, metadata, versi) | AI-01 | — | 10 | T-071 |
| T-182 | Asisten Klinik Tani: RAG dengan sitasi, tanpa dosis di luar label, jawaban "tidak tahu" | AI-01 | — | 14 | T-181 |
| T-183 | Evaluasi & guardrail AI: golden set, regression test, batas biaya | AI-01..03 | — | 8 | T-182 |
| T-184 | Prediksi kebutuhan musim (saran PO, keputusan tetap manusia) | AI-02 | — | 14 | T-175, T-179 |
| T-185 | Deteksi anomali yang menjelaskan pola red flag (tanpa mengubah data) | AI-03 | — | 12 | T-129, T-179 |
| T-186 | KPI otomatis dari data operasional + penilaian atasan | HR-07 | — | 10 | T-066, T-179 |
| T-187 | Skor driver & biaya armada, terhubung insentif driver | FLT-06 | — | 8 | T-152, T-154 |
| T-188 | Portal prinsipal (guard `principal`, produk miliknya, angka agregat) | WEB-02 | — | 12 | T-179 |
| T-189 | Data sell-out kios (opt-in, persetujuan kios) | MOB-02 | — | 8 | T-136 |
| T-190 | Marketplace alat pertanian (sinkron stok & pesanan, pestisida dikecualikan) | WEB-03 | BR-112, OQ-107 | 14 | T-088 |
| T-191 | Peta sebaran pelanggan & analitik area | BI-01 | — | 6 | T-179 |
| T-192 | Hardening & UAT Paket G | — | `09` §7.2 | 8 | Semua T-179–T-191 |
