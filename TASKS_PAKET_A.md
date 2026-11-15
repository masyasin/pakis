# TASKS — Paket A: Master & Gudang (T-067–T-087)

> Siap eksekusi **setelah** Fase 0 selesai (exit gate `Implementasi_Plan.md`) dan checklist penyelarasan `docs/15-PAKET-A-MASTER-GUDANG.md` §0 dicentang. Format sama dengan `TASKS.md`. Spesifikasi sumber: `docs/15-PAKET-A-MASTER-GUDANG.md` (disingkat **15**), kerangka `docs/14-ROADMAP-LENGKAP.md` (**14**). Path relatif ke `backend/`.
>
> **Aturan khusus paket ini**
> - Domain baru: `app/Domain/{Approval,Catalog,Customer,Pricing,Purchasing,Inventory,Fleet}` dengan struktur sama seperti Fase 0 (`Models/`, `Actions/`, `Policies/`, `Enums/`, `Events/`, `Listings/`).
> - Uang & qty memakai value object `App\Support\Money\{Money,Qty,UnitCost}` berbasis `brick/math`. Dependency baru paket ini **hanya** `brick/math` (langsung, T-069) dan `leaflet` 1.9.x (T-073), keduanya dicatat di `03` §5. Dependency lain → berhenti dan tanyakan.
> - Setiap tabel di **15** §4 dibuat oleh task yang disebut di judul sub-bagiannya. Kolom yang tidak ada di **15** tidak boleh ditambahkan tanpa mencatat di **15** dulu.
> - Setiap listing baru: kelas listing + `#[UsesListing]` + `DataTable` + kunci di `07` §3.4 + `tests/Fixtures/listing_catalog.php` (**15** §7.3).
> - Setiap setting baru: `SettingRegistry` + `08` §2 + test registri (**15** §9).
> - Setiap permission baru sudah dibuat T-067. Task lain hanya memakai, tidak menambah.

| Ringkasan | Nilai |
|---|---|
| Task | 21 (T-067–T-087) |
| Estimasi | 160 jam (rentang 135–200) |
| Exit gate | **15** §10.2 checklist rilis + UAT-A01–A21 lulus + `ReconcileStockLedger` = 0 selisih di data UAT |

## Urutan & dependensi

```mermaid
flowchart LR
  F0([Fase 0 selesai]) --> T067 & T068 & T069 & T073
  T069 --> T070 --> T071 & T072
  T070 --> T075
  T073 --> T074
  T067 --> T074
  T073 --> T075 --> T076
  T068 --> T077
  T070 --> T077
  T077 --> T078
  T078 --> T080
  T078 --> T082
  T067 --> T082
  T081 --> T084
  T077 --> T085
  T069 --> T079
  T071 --> T078
  T072 --> T079
  T067 --> T079 --> T080
  T077 --> T080 --> T081
  T077 --> T082
  T077 --> T083
  T067 --> T083
  T078 --> T084
  T074 --> T085
  T075 --> T085 --> T086
  T077 --> T086
  T078 & T079 & T080 & T081 & T082 & T083 & T084 & T085 & T086 --> T087([T-087 Laporan, hardening, UAT])
```

Jalur kritis: T-069 → T-070 → T-077 → T-080 → T-081 → T-087. T-067, T-068, T-073 bisa paralel di awal (perhatikan file bersama: `PermissionCatalog`, `listing_catalog.php`, `routes/admin.php`, `Implementasi_Plan.md`).

---

### T-067 — Mesin approval generik + role & permission Paket A
- Paket: A | Prioritas: P0 | Estimasi: 10 jam
- Memenuhi: FR-A01, FR-A02, BR-153, SEC-101, SEC-110 (aturan & delegasi), ADR-105
- Dependensi: Fase 0 (T-019 penugasan & atasan, T-021 lokasi, T-017 notifikasi, T-010 setting)
- Konteks wajib: **15** §4.2, §4.2.1, §5 (baris `approvals`, `approval-rules`, `approval-delegations`), §6.1–§6.5, §8.2 BR-153, §9 (`approval.*`), §10.2 SEC-101, §11.2–§11.3; **14** §4 ADR-105; `06` §2–§4
- File: migrations `approval_rules`, `approval_rule_steps`, `approval_requests`, `approval_steps`, `approval_delegations`; `app/Domain/Approval/{Models/*,Enums/{ApprovalStatus,StepStatus,ApproverType},Contracts/Approvable.php,ApprovalEngine.php,ApproverResolver.php,RuleMatcher.php,Actions/{RequestApproval,DecideApprovalStep,CancelApproval,SaveApprovalRule,PreviewApprovalChain,CreateDelegation,RevokeDelegation},Events/*,Listings/{ApprovalListing,ApprovalRuleListing,ApprovalDelegationListing},Policies/*}`; `app/Http/Controllers/Admin/{ApprovalController,ApprovalRuleController,ApprovalDelegationController}.php`; `app/Console/Commands/EscalateApprovals.php`; `database/seeders/{PaketARoleSeeder,ApprovalRuleSeeder}.php`; `app/Domain/Access/PermissionCatalog.php` (+53); `resources/js/pages/Approvals/{Index,Show}.tsx`, `resources/js/pages/Settings/ApprovalRules/{Index,Form}.tsx`, `resources/js/pages/Approvals/Delegations.tsx`, `components/pakis/ApprovalTimeline.tsx`; `tests/Feature/Approval/*`, `tests/Feature/Access/PaketAMatrixTest.php`; update `06` §2–§4 & §8, `08` §2
- Deskripsi: Bangun kontrak `Approvable` (method `approvalDocumentType()`, `approvalAmount()`, `approvalSummary()`, `approvalContext()`, `approvalScopeLocationId()`, `approvalScopeEmployeeId()`, `onApproved()`, `onRejected()`, `creatorUserId()`). `ApprovalEngine::request(Approvable, User)` memilih aturan, membuat snapshot, mengaktifkan langkah 1; `decide()` mengunci baris permintaan (`lockForUpdate`), memvalidasi calon penyetuju & maker-checker, memajukan langkah, memanggil `onApproved/onRejected` di transaksi yang sama. Kotak masuk "Menunggu saya" dihitung dari langkah `pending` yang calon penyetujunya termasuk pengguna (via permission + scope, atasan, kepala lokasi, delegasi aktif, atau eskalasi). Seed 4 role baru, 53 permission, matriks **15** §6.4, dan aturan default **15** §4.2.1. Tambahkan menu **15** §6.5 (grup tampil hanya bila ada item).
- Kriteria penerimaan:
  - [ ] Given aturan PO `<25 jt` dan `≥25 jt`, When PO Rp 24.999.999,99 dan Rp 25.000.000 diajukan, Then masing-masing memakai aturan 1 langkah dan 2 langkah (batas bawah inklusif, atas eksklusif)
  - [ ] Given tidak ada aturan cocok dan `approval.auto_approve_when_no_rule=false`, Then `422 APPROVAL_RULE_MISSING`
  - [ ] Pengaju, pembuat dokumen, dan delegasi pengaju tidak bisa memutuskan (`403 APPROVAL_SELF_DECISION`) — test BR-153(4)
  - [ ] Langkah `direct_supervisor` tanpa atasan langsung → langsung `escalated`, pemegang `escalation_permission` bisa memutuskan
  - [ ] Dua request approve bersamaan pada langkah yang sama → tepat satu sukses, satu `409 APPROVAL_NOT_ACTIONABLE`
  - [ ] Perubahan aturan setelah permintaan dibuat tidak mengubah rantai permintaan itu (snapshot)
  - [ ] Delegasi di luar tanggal berlaku tidak memberi hak; delegasi ke diri sendiri ditolak
  - [ ] Pengguna yang sudah memutuskan langkah 1 tidak bisa memutuskan langkah 2 pada permintaan yang sama (BR-153(11))
  - [ ] Nominal ≥ `approval.reauth_amount` mewajibkan konfirmasi password
  - [ ] `PaketAMatrixTest` memverifikasi setiap sel ✅/❌ **15** §6.4 untuk 10 role (data-driven)
  - [ ] 3 listing lulus `ListingContractTest`; audit tercatat untuk simpan aturan & delegasi
- Verifikasi: `php artisan test --filter=Approval` · `php artisan test --filter=PaketAMatrix` · `./vendor/bin/phpstan`
- Di luar lingkup: dokumen yang memakai engine (task masing-masing); notifikasi WhatsApp

### T-068 — Lokasi stok, rak, kendaraan minimal
- Paket: A | Prioritas: P0 | Estimasi: 6 jam
- Memenuhi: FR-A03, FR-A04, FR-A05, ADR-106 (dimensi stok), ADR-107
- Dependensi: T-021 (lokasi Fase 0), T-067 (permission)
- Konteks wajib: **15** §4.3, §5 (`stock-locations`, `stock-bins`, `vehicles`), §6.2 (dimensi stok), §7.3 (`stock_locations`, `stock_bins`, `vehicles`); **14** ADR-106, ADR-107
- File: migrations `vehicles`, `stock_locations`, `stock_bins` + seeder `TransitStockLocationSeeder`; `app/Domain/Inventory/{Models/{StockLocation,StockBin},Enums/{StockLocationType,BinZone},Actions/{SaveStockLocation,SaveStockBin},Listings/{StockLocationListing,StockBinListing},Policies/*}`; `app/Domain/Fleet/{Models/Vehicle,Actions/SaveVehicle,Listings/VehicleListing,Policies/VehiclePolicy}`; `app/Domain/Access/DataScope.php` (tambah `forStock()`); controllers `Admin/{StockLocationController,StockBinController,VehicleController}`; lookup `stock_locations`, `stock_bins`; pages `StockLocations/*`, `Vehicles/*`; tests
- Deskripsi: CRUD lokasi stok (warehouse/store terikat lokasi Fase 0; vehicle dibuat otomatis saat kendaraan ditandai canvas; `TRANSIT` sistem). Bin karantina `Q-01` dibuat otomatis untuk warehouse/store. `DataScope::forStock(User, Builder, column)` sesuai **15** §6.2.
- Kriteria penerimaan:
  - [ ] CHECK `sl_type_chk` menolak kombinasi tidak valid (test DB langsung)
  - [ ] `TRANSIT` tidak bisa diubah/nonaktif/hapus (403)
  - [ ] Kendaraan `is_canvas=true` → tepat satu lokasi stok `vehicle`; dinonaktifkan → lokasi stok ikut nonaktif bila saldo 0, ditolak bila saldo > 0 (setelah T-077, test ditandai `->todo()` sampai T-077)
  - [ ] Plat dinormalisasi huruf besar tanpa spasi, unik
  - [ ] Staf gudang scope `location` hanya melihat lokasi stok lokasinya; akses ID lain → 404 (IDOR)
  - [ ] 3 listing lulus kontrak
- Verifikasi: `php artisan test --filter='StockLocation|StockBin|Vehicle'`
- Di luar lingkup: data armada (Paket D), stok (T-077)

### T-069 — Kategori produk, satuan & konversi
- Paket: A | Prioritas: P0 | Estimasi: 5 jam
- Memenuhi: FR-A06, FR-A07, BR-150, BR-151 (kategori), ADR-101, ADR-102
- Dependensi: T-010 (referensi), T-067
- Konteks wajib: **15** §4.1 (grup `unit`, `formulation`), §4.4 (`product_categories`, `product_units`), §8.2 BR-150, BR-151; **14** ADR-101, ADR-102
- File: `composer require brick/math` (kunci versi di lockfile, catat di `03` §5); seeder referensi (`unit` dengan meta `integer_only`, `formulation`); migration `product_categories`; `app/Support/Money/{Money,Qty,UnitCost}.php` (bila belum ada di Fase 0); `app/Domain/Catalog/{Models/ProductCategory,Enums/CategoryKind,Actions/SaveProductCategory,Listings/ProductCategoryListing,Policies/*,Units/UnitConverter.php}`; controller `Admin/ProductCategoryController`; page `ProductCategories/Index.tsx` (form di dialog); `components/pakis/QtyInput.tsx`; tests `tests/Unit/Support/{QtyTest,MoneyTest}.php`, `tests/Unit/Catalog/UnitConverterTest.php`, arch test `NoFloatInDomainTest`
- Deskripsi: Kategori bertingkat (maks 3 level) dengan `kind` dan penanda perilaku. `UnitConverter::toBase(Qty, ProductUnit)` dan `fromBase()`, pembulatan half-up 3 desimal, validasi `integer_only`. `QtyInput` menampilkan "= 240 botol" di bawah input. Tabel `product_units` dibuat di T-070 (butuh `products`); converter diuji dengan model in-memory.
- Kriteria penerimaan:
  - [ ] `Qty::of('2.5')->times('24')` = `60.000` tanpa float (arch test: tidak ada tipe `float` di `App\Domain\{Catalog,Inventory,Pricing,Purchasing}`)
  - [ ] Satuan `integer_only` menolak `1,5` (`422 QTY_NOT_INTEGER`)
  - [ ] Kategori level 4 ditolak; kategori dengan produk tidak bisa dihapus (nonaktif saja)
  - [ ] Listing `product_categories` lulus kontrak
- Verifikasi: `php artisan test --filter='Qty|Money|UnitConverter|ProductCategory'`
- Di luar lingkup: produk (T-070)

### T-070 — Produk, satuan produk, barcode
- Paket: A | Prioritas: P0 | Estimasi: 8 jam
- Memenuhi: FR-A07 (per produk), FR-A08, FR-A09, BR-112 (penanda), BR-150, BR-151, SEC-109 (foto)
- Dependensi: T-069
- Konteks wajib: **15** §4.4 (`products`, `product_units`, `product_barcodes`), §5 (`products.*`), §7.1 (detail bertab), §7.3 (`products`), §8.1 BR-112, §8.2 BR-150–151, §9 (`product.sku_mode`)
- File: migrations `products`, `product_units`, `product_barcodes`; sequence `product`; `app/Domain/Catalog/{Models/{Product,ProductUnit,ProductBarcode},Enums/ProductStatus,Actions/{CreateProduct,UpdateProduct,SaveProductUnit,AddBarcode,RemoveBarcode},Listings/ProductListing,Policies/ProductPolicy,Resources/ProductResource}`; controller `Admin/ProductController`, `Admin/ProductUnitController`, `Admin/ProductBarcodeController`; lookup `products`, `product_units`; export `ProductExport`; pages `Products/{Index,Form,Show}.tsx`; tests
- Deskripsi: Produk dengan penanda yang disalin dari kategori (BR-151). Satuan dasar dibuat otomatis. Barcode unik global, pencarian listing mendukung scan barcode exact. Foto produk di disk publik ter-resize (thumbnail 400 px) — **bukan** data pribadi; tetap validasi unggahan Fase 0. Tab "Harga" dan "Stok" di halaman detail diisi placeholder komponen yang diisi T-075/T-087.
- Kriteria penerimaan:
  - [ ] SKU mode `auto` memakai `NumberGenerator('product')`; mode `manual` validasi unik huruf besar `[A-Z0-9-]{3,30}`
  - [ ] Melonggarkan penanda kategori tanpa alasan ditolak; dengan alasan tercatat audit
  - [ ] Faktor satuan yang sudah dipakai PO/mutasi → `409 UNIT_FACTOR_LOCKED` (test memakai fixture baris PO setelah T-079; sebelumnya `->todo()`)
  - [ ] Maks satu default jual & satu default beli per produk
  - [ ] Barcode duplikat ditolak; pencarian listing `q=<barcode>` menemukan produk
  - [ ] Listing `products` + ekspor lulus kontrak; ekspor escape formula
- Verifikasi: `php artisan test --filter=Product`
- Di luar lingkup: izin edar (T-071), prinsipal FK (T-072), harga (T-075)

### T-071 — Izin edar produk
- Paket: A | Prioritas: P0 | Estimasi: 5 jam
- Memenuhi: FR-A10, BR-152, SEC-109
- Dependensi: T-070
- Konteks wajib: **15** §4.4 (`product_registrations`), §5 (`product-registrations`), §7.3, §7.4 (widget izin), §8.2 BR-152, §9 (`inventory.registration_reminder_days`), §11.1 (`RefreshRegistrationStatus`)
- File: migrations `product_registrations`, `reminder_logs` (**15** §4.1); `app/Domain/Catalog/{Models/ProductRegistration,Enums/RegistrationStatus,Actions/{SaveRegistration,RevokeRegistration},Queries/ActiveRegistration.php,Listings/ProductRegistrationListing}`; command `catalog:refresh-registrations` + jadwal; controller; pages `ProductRegistrations/Index.tsx`, tab izin di `Products/Show.tsx`; dashboard widget; tests
- Deskripsi: `ActiveRegistration::for(Product, date)` dipakai FEFO (T-078). Berkas izin di disk privat, akses signed URL 5 menit. Pengingat via notifikasi in-app Fase 0 sekali per izin per ambang.
- Kriteria penerimaan:
  - [ ] Izin `valid_until` kemarin → job → `expired`; produk wajib izin tanpa izin `active` → `ActiveRegistration` null
  - [ ] Perpanjangan (izin baru) membuat produk berlaku lagi tanpa mengubah izin lama
  - [ ] Pengingat H-60/30/7/0 tidak terkirim dua kali walau job dijalankan berulang
  - [ ] Filter `registration_status=expiring` benar pada batas hari ke-60
- Verifikasi: `php artisan test --filter=Registration`
- Di luar lingkup: blokir alokasi (diuji di T-078)

### T-072 — Prinsipal & supplier
- Paket: A | Prioritas: P1 | Estimasi: 5 jam
- Memenuhi: FR-A11, SEC-108
- Dependensi: T-070
- Konteks wajib: **15** §4.5, §5 (`principals`, `suppliers`), §7.3, §10.2 SEC-108
- File: migrations `principals`, `suppliers`, `supplier_products`, alter `products` add FK `principal_id`; `app/Domain/Purchasing/{Models/{Principal,Supplier,SupplierProduct},Actions/*,Listings/{PrincipalListing,SupplierListing},Policies/*}`; controllers; lookup `principals`, `suppliers`; pages `Principals/Index.tsx`, `Suppliers/{Index,Form,Show}.tsx`; tests
- Deskripsi: Supplier dengan syarat bayar default, rekening terenkripsi & termasking, daftar produk dipasok (harga beli terakhir diperbarui saat penerimaan, lead time).
- Kriteria penerimaan:
  - [ ] Rekening tidak pernah muncul plaintext di respons/log/audit; `reveal-account` mencatat `sensitive_data_viewed`
  - [ ] Supplier nonaktif tidak muncul di lookup PO
  - [ ] 2 listing lulus kontrak
- Verifikasi: `php artisan test --filter='Supplier|Principal'`
- Di luar lingkup: faktur & pembayaran supplier (Paket F)

### T-073 — Area penjualan, kios inti & penugasan
- Paket: A | Prioritas: P0 | Estimasi: 6 jam
- Memenuhi: FR-A12, FR-A13 (data inti), FR-A17, BR-167, ADR-106 (dimensi pelanggan)
- Dependensi: T-019 (penugasan pegawai), T-067
- Konteks wajib: **15** §4.1 (`customer_type`, sequence `customer`), §4.6 (`sales_areas`, `sales_area_districts`, `customers`, `customer_assignments`), §5 (`sales-areas`, `customers.index/create/store/edit/update/reassign`), §6.2, §7.3, §8.2 BR-167, §9 (`customer.default_term_days`, `customer.code_mode`, `map.*`)
- File: migrations; `app/Domain/Customer/{Models/{SalesArea,Customer,CustomerAssignment},Enums/CustomerStatus,Actions/{SaveSalesArea,CreateCustomer,UpdateCustomer,ReassignCustomers},Listings/{SalesAreaListing,CustomerListing},Policies/*,Support/PhoneNormalizer.php}`; `DataScope::forCustomer()`; command `customers:apply-assignments`; controllers; lookup `sales_areas`, `customers`; pages `SalesAreas/Index.tsx`, `Customers/{Index,Form,Show}.tsx`; `components/pakis/MapPointPicker.tsx` (`npm i leaflet@1.9`, catat di `03` §5); tests
- Deskripsi: Data kios inti tanpa verifikasi (T-074 menambah verifikasi, kontak, duplikat, HP, limit). Status awal `prospect`, `credit_limit_active=false`. `PhoneNormalizer` dipakai juga oleh T-074. Pengalihan massal ≤ 500 kios. Peta Leaflet + tile dari `map.tile_url` (tambahkan domain tile ke CSP `img-src`).
- Kriteria penerimaan:
  - [ ] Unik satu penugasan aktif (test DB); pengalihan menutup lama & membuka baru dalam satu transaksi
  - [ ] Pengalihan bertanggal masa depan diterapkan job pada hari H
  - [ ] Supervisor (scope team) hanya melihat kios area yang ia supervisi atau milik timnya; Sales (Paket B) belum ada — test memakai role uji `self`
  - [ ] IDOR: kios di luar scope → 404 di show/edit/update
  - [ ] CSP tetap enforce dengan tile peta tampil
  - [ ] 2 listing lulus kontrak
- Verifikasi: `php artisan test --filter='SalesArea|Customer'`
- Di luar lingkup: verifikasi & limit (T-074), price list FK (T-075)

### T-074 — Verifikasi kios, duplikat, ganti HP, limit
- Paket: A | Prioritas: P0 | Estimasi: 10 jam
- Memenuhi: FR-A13 (lengkap), FR-A14, FR-A15, FR-A16, FR-A18, BR-125, BR-126, BR-165, BR-166, SEC-105, SEC-108 (HP), SEC-109
- Dependensi: T-073, T-067
- Konteks wajib: **15** §4.6 (`customer_contacts`, `customer_verifications`), §4.2.1 (`customer_*`), §5 (`customers.duplicates/verify/otp/change-phone/credit-limit/merge`, `customer-verifications`), §8.1 BR-125–126, §8.2 BR-165–166, §9 (`customer.*`), §10.2 SEC-105; **14** §12 `WhatsAppChannel`
- File: migrations `customer_contacts`, `customer_verifications`, `customer_otp_codes` (CHECK `customers_active_limit_chk` sudah dibuat T-073); `app/Domain/Customer/{Actions/{SubmitVerification,RecordOfficeCall,SendCustomerOtp,CheckCustomerOtp,RequestPhoneChange,RequestCreditLimit,RequestMerge},Approvables/{CustomerActivation,CustomerPhoneChange,CustomerCreditLimit,CustomerMerge},Duplicates/DuplicateFinder.php,Listings/CustomerVerificationListing}`; `app/Support/Channels/WhatsApp/{WhatsAppChannel,LogWhatsAppChannel}.php` (kontrak + driver `log`); controllers; pages `Customers/Verify.tsx`, `CustomerVerifications/Index.tsx`, dialog duplikat; tests
- Deskripsi: Implementasi BR-165/166 lengkap dengan engine approval T-067. `DuplicateFinder` memakai normalisasi nama + `similar_text` (tanpa dependency baru) + jarak Haversine. Nomor HP pegawai aktif dibandingkan setelah normalisasi. OTP: 6 digit acak `random_int`, simpan hash, 5 menit, 5 percobaan, throttle 3/jam per nomor & 20/jam per pengguna.
- Kriteria penerimaan:
  - [ ] `credit_limit_active=true` tanpa `verified_at` ditolak DB (CHECK) dan Policy
  - [ ] HP sama dengan kios aktif → `CUSTOMER_PHONE_DUPLICATE`; HP pegawai aktif → `PHONE_BELONGS_TO_EMPLOYEE`
  - [ ] Nama "Toko Tani Makmur" vs "TANI MAKMUR" berjarak 120 m → kandidat duplikat; simpan tanpa konfirmasi "bukan duplikat" ditolak
  - [ ] `customer.otp_enabled=false` → endpoint OTP `503 CHANNEL_UNAVAILABLE`; jalur telepon kantor oleh pengguna ≠ pengaju berhasil; oleh pengaju ditolak
  - [ ] OTP salah 5x → `429 OTP_TOO_MANY_ATTEMPTS`; kode tidak pernah muncul di log (driver `log` mencatat "OTP terkirim ke 6281•••789" tanpa kode)
  - [ ] Ganti HP butuh approval Owner; notifikasi Owner berisi nomor termasking; audit nilai lama/baru
  - [ ] Gabung kios: sumber `merged` + `merged_into_id`, listing default tidak menampilkan `merged`
  - [ ] Limit ≥ ambang memakai rantai 2 langkah
- Verifikasi: `php artisan test --filter='CustomerVerification|CustomerDuplicate|CustomerPhone|CreditLimit|CustomerOtp'`
- Di luar lingkup: kelayakan pembeli terbatas dipakai di dokumen jual (Paket B); OTP lewat WhatsApp nyata (Paket C)

### T-075 — Price list berversi
- Paket: A | Prioritas: P0 | Estimasi: 8 jam
- Memenuhi: FR-A19, BR-168, SEC-106, ADR-108
- Dependensi: T-070, T-073, T-067
- Konteks wajib: **15** §4.7 (`price_lists`, `price_list_versions`, `price_list_items`), §5 (`price-lists.*`), §7.3, §8.2 BR-168, §9 (`pricing.*`), §10.2 SEC-106, §11.1 (`ActivatePriceVersions`); **14** ADR-108
- File: migrations + FK `customers.price_list_id` + trigger `price_list_items_lock`; `app/Domain/Pricing/{Models/*,Enums/PriceVersionStatus,Actions/{SavePriceList,CreatePriceVersion,UpsertPriceItems,SubmitPriceVersion},Approvables/PriceListPublish,PriceResolver.php,Data/ResolvedPrice.php,Listings/{PriceListListing,PriceListItemListing}}`; command `pricing:activate-versions`; controllers; pages `PriceLists/{Index,Show,Items}.tsx`; tests
- Deskripsi: Versi baru bisa kosong, salin versi lain, atau salin dengan persen naik/turun (dibulatkan sesuai `pricing.rounding`). Editor item: tabel dengan input harga per satuan dan baris tier `min_qty`. `PriceResolver` mengembalikan `ResolvedPrice{price, price_list_version_id, source_list_kind, min_qty}`.
- Kriteria penerimaan:
  - [ ] Ubah item versi `published` ditolak di Policy **dan** trigger DB (`PRICE_VERSION_LOCKED`)
  - [ ] Resolusi 4 tingkat (store→kios→area→default) dengan fallback per produk (test tabel kasus)
  - [ ] `min_qty` 1/10/50: qty 49 → harga tier 10
  - [ ] Versi berlaku besok tidak dipakai hari ini; job men-supersede versi lama tepat waktu
  - [ ] Penerbitan oleh pemegang `prices.publish` langsung terbit; pengguna lain → approval
  - [ ] 2 listing lulus kontrak
- Verifikasi: `php artisan test --filter='Price'`
- Di luar lingkup: snapshot di dokumen jual (Paket B)

### T-076 — Promo
- Paket: A | Prioritas: P1 | Estimasi: 8 jam
- Memenuhi: FR-A20, BR-169
- Dependensi: T-075
- Konteks wajib: **15** §4.7 (`promotions`, `promotion_targets`, `promotion_scopes`, `promotion_tiers`), §5 (`promotions.*`), §7.3, §8.2 BR-169, §11.1 (`EndPromotions`)
- File: migrations; `app/Domain/Pricing/{Models/Promotion*,Enums/{PromotionType,PromotionStatus},Actions/{SavePromotion,SubmitPromotion,EndPromotion},Approvables/PromotionActivate,PromotionEngine.php,Data/{PricedLine,AppliedPromotion}.php,Listings/PromotionListing}`; controller + `simulate`; pages `Promotions/{Index,Form,Simulate}.tsx`; tests
- Deskripsi: Lima tipe promo. `PromotionEngine::apply()` murni (tanpa side effect) agar bisa dipakai simulasi, Paket B (web), dan C (offline — logika yang sama diporting ke Dart; sediakan fixture kasus uji JSON `tests/Fixtures/promotion_cases.json` yang dipakai kedua sisi).
- Kriteria penerimaan:
  - [ ] Fixture ≥ 15 kasus (tiap tipe, stackable vs tidak, prioritas, harga tidak negatif, pembulatan) lulus
  - [ ] Promo dana prinsipal wajib `principal_id`; `shared` wajib `principal_share_pct`
  - [ ] Promo lewat `ends_at` → `ended` oleh job; promo `active` tidak bisa diubah selain diakhiri
  - [ ] Simulasi tidak menulis apa pun ke DB (test query log)
- Verifikasi: `php artisan test --filter=Promotion`
- Di luar lingkup: pemakaian anggaran & bonus barang (Paket B)

### T-077 — Inti stok: StockLedger, batch, mutasi, reservasi, HPP, outbox
- Paket: A | Prioritas: P0 | Estimasi: 12 jam
- Memenuhi: FR-A21, BR-101, BR-114, BR-154, BR-155, BR-159, SEC-102, NFR-A02, NFR-A03, ADR-103, ADR-104
- Dependensi: T-068, T-070
- Konteks wajib: **15** §4.8 (semua tabel), §8.1 BR-101, BR-114, §8.2 BR-154, BR-155, BR-159, §9 (`inventory.negative_stock_allowed`, `inventory.reservation_ttl_hours`), §10.2 SEC-102, §11.1 (`ReconcileStockLedger`, `ReleaseExpiredReservations`), §11.3; **14** ADR-103, ADR-104, §7
- File: migrations `stock_batches`, `stock_movements` (+ trigger append-only), `stock_reservations`, `product_costs`, `product_stock_levels`, `stock_batch_status_logs`, `accounting_events` (+ trigger: hanya `status`, `journal_entry_id`, `error` yang boleh di-UPDATE, DELETE ditolak); `app/Domain/Inventory/Ledger/{StockLedger.php,StockLine.php,MovementType.php,LedgerResult.php,BatchKey.php,CostCalculator.php,Exceptions/{InsufficientStock,StockConflict,BatchNotAvailable}.php}`; `app/Domain/Inventory/Models/{StockBatch,StockMovement,StockReservation,ProductCost,ProductStockLevel,StockBatchStatusLog}.php`; `app/Domain/Accounting/Outbox/{AccountingOutbox.php,Models/AccountingEvent.php,EventCode.php}`; commands `inventory:reconcile`, `inventory:release-reservations`, `inventory:stress` (hanya env testing/CI); tests `tests/Feature/Inventory/Ledger/*`, `tests/Arch/StockLedgerArchTest.php`, `tests/Concurrency/LastUnitTest.php`
- Deskripsi: API publik `StockLedger`:
  - `receive(StockLocation, Product, BatchKey{batch_no, expiry, bin}, Qty base, UnitCost, Source, businessDate)`
  - `issue(StockBatch|list, Qty, Source, MovementType)` — mengurangi, memakai `avg_cost`
  - `move(StockBatch, toLocation|toBin, Qty, Source, typeOut, typeIn)` — dua mutasi dalam satu transaksi, batch tujuan dibuat bila belum ada dengan nomor & ED sama
  - `reserve(StockBatch, Qty, Source, ttl)`, `consume(Reservation)`, `release(Reservation)`
  - `adjust(StockBatch|BatchKey, signed Qty, Source, reasonId)`
  - `setStatus(StockBatch, status, reasonId, user)`

  Semua method menerima `Source{type, id, line_id}` dan wajib dipanggil di dalam `DB::transaction` milik Action pemanggil (method melempar bila tidak ada transaksi aktif). Kunci baris `stock_batches` urut `id` naik, retry deadlock maks 3x. `AccountingOutbox::record(code, source, businessDate, payload)` ditulis Action pemanggil di transaksi yang sama (payload: peran akun + nilai string desimal).
- Kriteria penerimaan:
  - [ ] Arch test: hanya `App\Domain\Inventory\Ledger` yang memanggil `update/insert` pada `stock_batches.qty_*` dan `stock_movements`
  - [ ] UPDATE/DELETE `stock_movements` ditolak trigger (test SQL langsung)
  - [ ] `LastUnitTest`: 20 proses mengambil 1 unit terakhir → 1 sukses, 19 `INSUFFICIENT_STOCK`, saldo 0, tanpa deadlock tak tertangani (CI MariaDB)
  - [ ] HPP: kasus BR-159 (stok 0; 10@1.000 + 10@1.200 = 1.100,0000; presisi 4 desimal; total lintas lokasi termasuk TRANSIT & karantina)
  - [ ] `qty_after` setiap mutasi = saldo batch setelahnya; `inventory:reconcile` pada data acak 1.000 mutasi = 0 selisih
  - [ ] Pemanggilan di luar transaksi melempar `LogicException`
  - [ ] Setting `inventory.negative_stock_allowed` hanya bisa `false` (test registri)
  - [ ] Seeder volume (`InventoryVolumeSeeder`, testing saja) 1 juta mutasi; query kartu stok per batch & per produk-lokasi-periode memakai index (assert `EXPLAIN` tanpa `ALL`)
- Verifikasi: `php artisan test --filter='Ledger|StockLedgerArch|LastUnit'` · `php artisan inventory:stress --workers=20` (CI)
- Di luar lingkup: dokumen pemakai (T-080–T-086), mesin jurnal (Paket F)

### T-078 — FEFO, kedaluwarsa otomatis, alert ED
- Paket: A | Prioritas: P0 | Estimasi: 6 jam
- Memenuhi: FR-A22, FR-A23, BR-102, BR-152 (blokir), BR-156, BR-157
- Dependensi: T-077, T-071
- Konteks wajib: **15** §5 (lookup `available_batches`), §7.2 (`BatchPicker`, `ExpiryBadge`), §7.4 (widget ED), §8.1 BR-102, §8.2 BR-156, BR-157, §9 (`inventory.near_expiry_days`, `inventory.min_sell_days_before_expiry`), §11.1 (`ExpireBatches`, `NearExpiryAlerts`)
- File: `app/Domain/Inventory/Allocation/{FefoAllocator.php,Allocation.php}`; `app/Domain/Inventory/Actions/ExpireBatches.php`; commands `inventory:expire-batches`, `inventory:near-expiry-alerts` (memakai `reminder_logs`, kunci `ed90`/`ed60`/`ed30`); lookup `available_batches`; `components/pakis/{BatchPicker,ExpiryBadge,StockStatusBadge}.tsx`; widget dashboard; tests
- Deskripsi: `FefoAllocator::allocate()` sesuai BR-102 (read-only, tanpa kunci); pemanggil mengunci saat eksekusi lewat `StockLedger`. Validasi ulang ketersediaan saat eksekusi (alokasi bisa basi). `BatchPicker` menandai batch bukan-FEFO dan meminta konfirmasi.
- Kriteria penerimaan:
  - [ ] Urutan: ED terdekat, NULL terakhir, `received_date`, `id` (test tabel kasus)
  - [ ] Batch `quarantine`/`expired`, ED ≤ hari ini + `min_sell_days`, dan produk tanpa izin aktif tidak dialokasikan (`PRODUCT_REGISTRATION_INACTIVE`)
  - [ ] Alokasi 1 produk pada 200 batch < 100 ms (NFR-A02, test benchmark longgar)
  - [ ] Job ED: batch kemarin → `expired`, reservasi aktif dilepas, log status tercatat dengan user NULL; dijalankan dua kali tidak menggandakan log
  - [ ] Alert per ambang terkirim sekali per batch
- Verifikasi: `php artisan test --filter='Fefo|ExpireBatches|NearExpiry'`
- Di luar lingkup: alokasi pesanan penjualan (Paket B)

### T-079 — Purchase order + persetujuan
- Paket: A | Prioritas: P0 | Estimasi: 8 jam
- Memenuhi: FR-A24, BR-160
- Dependensi: T-072, T-067, T-069
- Konteks wajib: **15** §4.9 (`purchase_orders`, `purchase_order_lines`), §4.2.1 (`purchase_order`), §5 (`purchase-orders.*`), §6.6 (PO), §7.1 (dokumen bergaris), §7.3, §7.4 (PO terlambat), §8.2 BR-160, §9 (`purchasing.default_payment_term_days`); **14** OQ-109 (PDF)
- File: migrations; sequence `purchase_order`; `app/Domain/Purchasing/{Models/{PurchaseOrder,PurchaseOrderLine},Enums/PurchaseOrderStatus,Actions/{SavePurchaseOrder,SubmitPurchaseOrder,CancelPurchaseOrder,ClosePurchaseOrder,RecalculatePurchaseOrder},Approvables/PurchaseOrderApproval,Listings/PurchaseOrderListing,Policies/PurchaseOrderPolicy,Resources/PurchaseOrderResource}`; controller; pages `Purchasing/PurchaseOrders/{Index,Form,Show,Print}.tsx`; `components/pakis/{DocumentHeader,DocumentLines,MoneyInput}.tsx`; tests
- Deskripsi: Editor baris (produk via lookup, satuan default beli, harga dari `supplier_products` terakhir sebagai saran). Total dihitung server dengan `Money`. Halaman cetak HTML sementara (PDF menunggu OQ-109/ADR-114).
- Kriteria penerimaan:
  - [ ] Total dari klien diabaikan; server menghitung ulang (test kirim total salah)
  - [ ] PO `approved` tidak bisa diubah (403); tutup wajib alasan
  - [ ] Purchasing tidak bisa menyetujui PO buatannya walau diberi `purchase_orders.approve` (maker-checker)
  - [ ] Staf gudang (scope location) hanya melihat PO ke lokasinya; IDOR → 404
  - [ ] Listing + ekspor lulus kontrak; filter `overdue` benar
- Verifikasi: `php artisan test --filter=PurchaseOrder`
- Di luar lingkup: penerimaan (T-080), PPN (Paket F, OQ-101)

### T-080 — Penerimaan barang + nomor seri
- Paket: A | Prioritas: P0 | Estimasi: 10 jam
- Memenuhi: FR-A25, BR-103, BR-144, BR-158, BR-159, SEC-104, SEC-109
- Dependensi: T-079, T-077, T-078
- Konteks wajib: **15** §4.9 (`goods_receipts`, `goods_receipt_lines`, `serial_numbers`), §4.2.1 (`goods_receipt_short_shelf_life`), §5 (`goods-receipts.*`, `serials.index`), §6.6, §7.1 (pemindai), §8.1 BR-103, BR-144, §8.2 BR-158, BR-159, §9 (`purchasing.*`, `inventory.min_shelf_life_months`), §10.2 SEC-104
- File: migrations; sequence `goods_receipt`; `app/Domain/Purchasing/{Models/{GoodsReceipt,GoodsReceiptLine},Actions/{SaveGoodsReceipt,CheckGoodsReceipt,PostGoodsReceipt,SaveReceiptSerials},Approvables/ShortShelfLifeApproval,Listings/GoodsReceiptListing}`; `app/Domain/Inventory/{Models/SerialNumber,Enums/SerialStatus,Listings/SerialNumberListing}`; controllers; pages `Purchasing/GoodsReceipts/{Index,Form,Show}.tsx`, `Inventory/Serials/Index.tsx`; `components/pakis/{ScanField,PhotoCapture}.tsx`; tests
- Deskripsi: Alur `draft → checked → (pending_approval) → posted`. Saat periksa: hitung sisa umur, cocokkan nomor pendaftaran, tentukan `line_status`. Posting: satu transaksi per **15** BR-158(6) termasuk perbarui `supplier_products.last_price`. Baris `registration_mismatch` diposting ke bin karantina dengan status `quarantine`. Nomor seri: scan satu per satu atau tempel daftar (satu per baris); input manual wajib foto label.
- Kriteria penerimaan:
  - [ ] ED ≤ tanggal terima → `422 BATCH_EXPIRED_ON_RECEIPT`
  - [ ] Sisa umur 4 bulan, batas 6 → approval; ditolak → penerimaan `rejected`, stok tidak berubah
  - [ ] Over-receipt di atas toleransi → `422 OVER_RECEIPT`; `PO_NOT_APPROVED` untuk PO belum disetujui
  - [ ] Pemeriksa = pemosting saat `receipt_two_person=true` → `403 RECEIPT_SAME_PERSON`
  - [ ] Klik posting dua kali (dua request bersamaan) → satu posting, satu `409`
  - [ ] Jumlah nomor seri ≠ qty → `SERIAL_COUNT_MISMATCH`; duplikat → `SERIAL_DUPLICATE`
  - [ ] Outbox `inventory.goods_received` berisi D `inventory` = K `goods_received_not_invoiced` = Σ qty × biaya
  - [ ] Harga beli hanya tampil untuk pemegang `purchase_orders.view` / `stock.view_cost` (SEC-107)
  - [ ] 2 listing lulus kontrak
- Verifikasi: `php artisan test --filter='GoodsReceipt|Serial'`
- Di luar lingkup: faktur pembelian (Paket F), nomor seri saat jual (Paket B)

### T-081 — Retur ke prinsipal
- Paket: A | Prioritas: P1 | Estimasi: 5 jam
- Memenuhi: FR-A26, BR-155, BR-159 (biaya retur)
- Dependensi: T-080
- Konteks wajib: **15** §4.9 (`purchase_returns`, `purchase_return_lines`), §4.2.1 (`purchase_return`), §5 (`purchase-returns.*`), §8.2 BR-155, BR-159, BR-163 (metode `return_to_principal`)
- File: migrations; sequence `purchase_return`; `app/Domain/Purchasing/{Models/{PurchaseReturn,PurchaseReturnLine},Actions/{SavePurchaseReturn,SubmitPurchaseReturn,ShipPurchaseReturn,ClosePurchaseReturn},Approvables/PurchaseReturnApproval,Listings/PurchaseReturnListing}`; controller; pages `Purchasing/PurchaseReturns/*`; tests
- Deskripsi: Pilih batch (termasuk karantina/ED). Disetujui → reservasi; dikirim → `issue` tipe `purchase_return`, outbox `inventory.purchase_returned` (dua nilai: biaya batch & HPP rata-rata, selisih ke peran `inventory_variance`). Tutup setelah prinsipal mengonfirmasi (catatan + lampiran opsional).
- Kriteria penerimaan:
  - [ ] Retur melebihi qty tersedia batch → `INSUFFICIENT_STOCK`
  - [ ] Ditolak → reservasi dilepas; dibatalkan setelah approved → reservasi dilepas
  - [ ] Payload outbox seimbang (D = K) dengan selisih biaya ke `inventory_variance`
- Verifikasi: `php artisan test --filter=PurchaseReturn`
- Di luar lingkup: nota debit/pemotongan hutang (Paket F)

### T-082 — Mutasi antar lokasi
- Paket: A | Prioritas: P0 | Estimasi: 7 jam
- Memenuhi: FR-A27, BR-155, BR-156, BR-161
- Dependensi: T-077, T-078, T-067
- Konteks wajib: **15** §4.10 (`stock_transfers`, `stock_transfer_lines`), §4.2.1 (`stock_transfer_variance`), §5 (`stock-transfers.*`), §6.6, §7.4 (mutasi belum diterima), §8.2 BR-161, §9 (`inventory.transfer_receive_reminder_hours`), §11.1
- File: migrations; sequence `stock_transfer`; `app/Domain/Inventory/{Models/{StockTransfer,StockTransferLine},Enums/StockTransferStatus,Actions/{SaveStockTransfer,ShipStockTransfer,ReceiveStockTransfer,CancelStockTransfer},Approvables/TransferVarianceApproval,Listings/StockTransferListing}`; command `inventory:transfer-reminders`; controller; pages `Inventory/Transfers/{Index,Form,Show,Receive}.tsx`; tests
- Deskripsi: Draft (batch dipilih → reservasi, atau FEFO saat kirim) → kirim (asal → TRANSIT) → terima per baris (TRANSIT → tujuan & rak) → selisih via approval. Scope: pengguna asal melihat & mengirim, pengguna tujuan melihat & menerima.
- Kriteria penerimaan:
  - [ ] Setelah kirim, stok asal berkurang dan TRANSIT bertambah dengan batch, ED, biaya sama
  - [ ] Terima 48 dari 50 → status `received_with_variance`, 2 tetap di TRANSIT sampai approval; setelah disetujui `adjustment_out` + outbox `inventory.transfer_variance`
  - [ ] Terima > kirim → `TRANSFER_OVER_RECEIPT`; penerima = pengirim → `TRANSFER_SAME_PERSON`
  - [ ] Staf lokasi lain (bukan asal/tujuan) → 404
  - [ ] Pengingat terkirim sekali per hari setelah ambang
- Verifikasi: `php artisan test --filter=StockTransfer`
- Di luar lingkup: muat/bongkar canvas (Paket C)

### T-083 — Stock opname
- Paket: A | Prioritas: P0 | Estimasi: 8 jam
- Memenuhi: FR-A28, BR-162, SEC-103
- Dependensi: T-077, T-067
- Konteks wajib: **15** §4.10 (`stock_opnames`, `stock_opname_lines`), §4.2.1 (`stock_opname_variance`), §5 (`stock-opnames.*`), §6.6, §7.1 (lembar hitung), §7.3 (`stock_opnames`, `stock_opname_lines`), §8.2 BR-162, §9 (`inventory.opname_*`), §10.2 SEC-103
- File: migrations; sequence `stock_opname`; `app/Domain/Inventory/{Models/{StockOpname,StockOpnameLine},Enums/StockOpnameStatus,Actions/{CreateStockOpname,StartStockOpname,RecordCount,RecordRecount,FinishCounting,SubmitStockOpname,PostStockOpname,CancelStockOpname},Approvables/OpnameVarianceApproval,Listings/{StockOpnameListing,StockOpnameLineListing},Resources/StockOpnameLineResource}`; controller; pages `Inventory/Opnames/{Index,Form,Show,CountSheet}.tsx`; tests
- Deskripsi: Lembar hitung ramah tablet + scan. Resource baris tidak menyertakan `qty_system_base`/selisih saat blind & `counting`. Selisih dihitung dengan memperhitungkan mutasi setelah `frozen_at` (BR-162(2)).
- Kriteria penerimaan:
  - [ ] Respons JSON lembar hitung blind tidak memuat `qty_system_base` (SEC-103)
  - [ ] Penjualan/mutasi 5 unit setelah freeze tidak muncul sebagai selisih
  - [ ] Baris selisih ≥ ambang tidak bisa `review` sebelum hitung ulang oleh pengguna lain (`OPNAME_RECOUNT_REQUIRED`)
  - [ ] Batch yang sedang di opname lain → `OPNAME_BATCH_LOCKED`
  - [ ] Selisih Rp 1,2 jt → rantai 2 langkah; posting menulis `adjustment_*` dan outbox `inventory.adjusted` seimbang
  - [ ] Ditolak → kembali `review` (bukan `cancelled`)
- Verifikasi: `php artisan test --filter=StockOpname`
- Di luar lingkup: opname canvas (Paket C)

### T-084 — Karantina manual, lepas karantina, pemusnahan & berita acara
- Paket: A | Prioritas: P1 | Estimasi: 6 jam
- Memenuhi: FR-A29, BR-163, BR-164, SEC-109
- Dependensi: T-078, T-081 (metode `return_to_principal`), T-067
- Konteks wajib: **15** §4.10 (`disposal_reports`, `disposal_report_lines`), §4.2.1 (`stock_disposal`, `stock_quarantine_release`), §5 (`stock.quarantine/release/bin-move`, `disposal-reports.*`), §6.6 (batch, pemusnahan), §8.2 BR-163, BR-164; **14** OQ-115
- File: migrations; sequence `disposal_report`; `app/Domain/Inventory/{Actions/{QuarantineStock,ReleaseQuarantine,MoveBin,SaveDisposalReport,SubmitDisposalReport,ExecuteDisposalReport},Approvables/{QuarantineReleaseApproval,DisposalApproval},Models/{DisposalReport,DisposalReportLine},Listings/DisposalReportListing}`; template cetak berita acara (HTML print); controller; pages `Inventory/Quarantine/*`, `Inventory/Disposals/*`; tests
- Deskripsi: Karantina sebagian qty → bin karantina (batch baru berstatus `quarantine`). Lepas → approval → bin pilihan. Berita acara: draft dengan batch karantina/ED → approval → cetak BA (daftar batch, qty, nilai, kolom tanda tangan saksi) → eksekusi dengan unggah scan + foto + saksi.
- Kriteria penerimaan:
  - [ ] Batch di bin zona karantina tidak pernah dialokasikan FEFO
  - [ ] Batch `expired` tidak bisa dilepas karantina
  - [ ] Eksekusi tanpa scan BA / tanpa foto / < 2 saksi ditolak; CHECK `bap_exec_chk` di DB
  - [ ] Saksi tidak menerima field NIK (validasi menolak kunci tak dikenal)
  - [ ] Eksekusi → qty 0, status `disposed`, outbox `inventory.written_off`
  - [ ] Metode `return_to_principal` membuat retur prinsipal (T-081), bukan mutasi `disposal`
- Verifikasi: `php artisan test --filter='Quarantine|Disposal'`
- Di luar lingkup: prosedur resmi dinas (OQ-115) — template BA generik dulu

### T-085 — Impor master: produk, kios, harga, stok minimum
- Paket: A | Prioritas: P0 | Estimasi: 8 jam
- Memenuhi: FR-A30 (impor master), BR-170
- Dependensi: T-070, T-074, T-075, T-077 (stok minimum)
- Konteks wajib: **15** §5 (`master-imports.*`), §8.2 BR-170; `05`/`08` kerangka impor Fase 0; **14** OQ-112
- File: `app/Domain/Catalog/Imports/ProductImporter.php`, `app/Domain/Customer/Imports/CustomerImporter.php`, `app/Domain/Pricing/Imports/PriceItemImporter.php`, `app/Domain/Inventory/Imports/StockLevelImporter.php` (implementasi kontrak importer Fase 0); template unduhan `.xlsx` per tipe; registrasi tipe impor; tests dengan fixture Excel dummy (header acak, angka "1.250.000", tanggal "17/08/2026")
- Deskripsi: Empat importer memakai staging & pemetaan kolom Fase 0. Kios hasil impor berstatus `active` **hanya** bila kolom `sudah_terverifikasi=ya` dan pengimpor punya `customers.verify` + konfirmasi password (cutover); selain itu `prospect`. Harga diimpor ke versi `draft`.
- Kriteria penerimaan:
  - [ ] Impor ulang file yang sama → 0 baris baru, 0 perubahan (idempoten)
  - [ ] Error per sel tampil dengan nomor baris & kolom asli
  - [ ] HP kios dinormalisasi; duplikat dalam file dan terhadap DB dilaporkan
  - [ ] Nilai diawali `=`/`+`/`-`/`@` diperlakukan sebagai teks (tidak dieksekusi)
  - [ ] Impor kios aktif-terverifikasi tercatat audit dengan jumlah baris
- Verifikasi: `php artisan test --filter=Import`
- Di luar lingkup: saldo awal stok (T-086), piutang awal (Paket B)

### T-086 — Saldo awal stok & prosedur cutover
- Paket: A | Prioritas: P0 | Estimasi: 6 jam
- Memenuhi: FR-A30 (saldo awal), BR-170
- Dependensi: T-077, T-085
- Konteks wajib: **15** §8.2 BR-170, §9 (`inventory.opening_balance_open`), §11.3 (`OPENING_BALANCE_CLOSED`, `LOCATION_HAS_MOVEMENTS`); **14** §7 (`inventory.opening_balance`)
- File: `app/Domain/Inventory/Imports/OpeningStockImporter.php`; `app/Domain/Inventory/Actions/CloseOpeningBalance.php`; `docs/runbooks/CUTOVER-PAKET-A.md` (baru); tests
- Deskripsi: Impor saldo awal per lokasi + SKU + batch + ED + rak + qty + biaya. Ringkasan sebelum konfirmasi: jumlah baris, total qty per lokasi, total nilai. Runbook cutover: freeze transaksi manual lama, opname fisik, impor, cocokkan nilai dengan pembukuan lama, tutup saldo awal, mulai transaksi di sistem.
- Kriteria penerimaan:
  - [ ] Saldo awal ke lokasi yang punya mutasi selain `opening_balance` → `LOCATION_HAS_MOVEMENTS`
  - [ ] Setelah ditutup → `OPENING_BALANCE_CLOSED`; setting tidak bisa dibuka lagi (`false → true` ditolak)
  - [ ] `product_costs.avg_cost` = rata-rata tertimbang biaya file; outbox `inventory.opening_balance` total = Σ qty × biaya
  - [ ] Runbook dapat diikuti tim Pakis (dicek saat UAT-A19)
- Verifikasi: `php artisan test --filter=OpeningStock`
- Di luar lingkup: saldo awal piutang (T-112)

### T-087 — Laporan stok, hardening & UAT Paket A
- Paket: A | Prioritas: P0 | Estimasi: 13 jam
- Memenuhi: FR-A30 (laporan), NFR-A01, NFR-A04, SEC-107, SEC-109, SEC-110, exit gate Paket A
- Dependensi: T-078–T-086
- Konteks wajib: **15** §5 (`stock.*`, `stock-movements.*`, `stock-levels.*`, `stock.trace`), §7.3 (`stock_positions`, `stock_movements`, `stock_levels`, `stock_batches_trace`), §7.4, §10 (semua), §12; `09` §6; `10` §4
- File: `app/Domain/Inventory/{Listings/{StockPositionListing,StockMovementListing,StockLevelListing},Queries/{StockTrace,StockCard}.php,Actions/UpsertStockLevels,Exports/{StockPositionExport,StockMovementExport}}`; controller `Admin/StockReportController`, `Admin/StockLevelController`; pages `Inventory/{Positions,Card,Levels,Trace}.tsx`; tab "Stok" di `Products/Show.tsx` & tab "Harga" (versi berlaku); widget dashboard sisa; `tests/Feature/Security/PaketAIdorTest.php`, `tests/Feature/Security/CostVisibilityTest.php`, `tests/Feature/Audit/PaketAAuditTest.php`; `docs/uat/UAT-PAKET-A.xlsx` (dari **15** §12.2, format `10` §4); update `09` §6 checklist
- Deskripsi: Posisi stok per produk/per batch, kartu stok, stok minimum, telusur batch. Kolom biaya mengikuti SEC-107 di resource & ekspor. Jalankan seeder volume, ukur p95 listing (NFR-A04), perbaiki index bila perlu (catat di **15** §4.11). Sapu IDOR untuk semua route Paket A ber-scope. Siapkan data UAT dummy dan berkas UAT.
- Kriteria penerimaan:
  - [ ] `PaketAIdorTest` mencakup setiap route `show/edit/update/aksi` ber-scope stok & pelanggan (daftar route diambil dari router, bukan manual — route baru tanpa test membuat test merah)
  - [ ] Admin Penjualan tidak menerima kolom biaya di posisi stok, kartu stok, dan ekspor
  - [ ] Kartu stok 1 juta mutasi, filter 1 produk 1 lokasi 1 bulan: p95 < 500 ms (lokal, dicatat di PR)
  - [ ] Semua aksi SEC-110 tercatat audit (test data-driven)
  - [ ] 58 listing lulus `ListingContractTest`
  - [ ] Checklist rilis **15** §10.2 dicentang di `Implementasi_Plan.md`
  - [ ] UAT-A01–A21 dijalankan bersama Pakis, hasil di berkas UAT, temuan P0/P1 diperbaiki
- Verifikasi: `php artisan test` · `./vendor/bin/phpstan` · `npm run lint && npm run typecheck` · `npx playwright test tests/e2e/paket-a`
- Di luar lingkup: laporan penjualan & margin (Paket B)

---

## Ringkasan estimasi

| Kelompok | Task | Jam |
|---|---|---|
| Fondasi (approval, lokasi, satuan) | T-067–T-069 | 21 |
| Master (produk, izin, supplier, kios, harga, promo) | T-070–T-076 | 50 |
| Stok inti & FEFO | T-077–T-078 | 18 |
| Pembelian | T-079–T-081 | 23 |
| Operasi gudang | T-082–T-084 | 21 |
| Impor & cutover | T-085–T-086 | 14 |
| Laporan, hardening, UAT | T-087 | 13 |
| **Total** | 21 task | **160** |

Rentang: optimis 135 jam (master Pakis rapi, OQ terjawab cepat) · pesimis 200 jam (format Excel sangat beragam, aturan approval berubah saat UAT).

## Pertanyaan terbuka yang memblokir

| OQ | Memblokir | Bisa jalan dengan default? |
|---|---|---|
| OQ-112 | T-085 (fixture nyata), T-086 | Ya, dengan fixture dummy; UAT-A18/A19 menunggu |
| OQ-113 | Seed approval final | Ya (angka contoh) |
| OQ-115 | Template BA final T-084 | Ya (BA generik) |
| OQ-116 | BR-159 landed cost | Ya (tidak masuk HPP) |
| OQ-118, OQ-119 | Seeder UAT | Ya (dummy) |
| OQ-101 | `tax_total` PO | Ya (0, tanpa PPN) |
