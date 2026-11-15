# mobile-pro/CLAUDE.md — Aturan khusus Pakis Pro (Flutter)

**Baca root `../CLAUDE.md` lebih dulu.** File ini hanya menambah hal yang khusus `mobile-pro/`.

## Lingkungan lokal (Windows)

- Flutter dikunci lewat FVM: `.fvmrc` = **3.50.0** (stable, Dart 3.14). Selalu `fvm flutter …`, bukan `flutter` global.
- FVM 4.3.2 standalone di `D:\tools\fvm-bin\fvm\fvm.exe` (belum di PATH; tambahkan `D:\tools\fvm-bin\fvm` ke PATH sesi). Cache SDK `D:\tools\fvm` (`fvm config --cache-path`).
- **Developer Mode Windows mati** → symlink butuh admin. Akibatnya `fvm use` gagal membuat `.fvm/versions` (hanya untuk IDE; `fvm flutter` tetap jalan), dan **jangan menambah platform desktop** (`windows/`, `linux/`): plugin desktop butuh symlink. Proyek ini Android saja.
- Android SDK `E:\android-sdk`, JDK = JBR Android Studio 21. Emulator melihat backend lokal di `http://10.0.2.2:8000` (bawaan `AppEnv.apiBaseUrl`).

## Perintah

```powershell
fvm flutter pub get
fvm dart format lib test                 # lebar 120 (analysis_options.yaml)
fvm flutter analyze                      # info pun dihitung gagal
fvm flutter test                         # unit + widget
fvm flutter build apk --debug
fvm flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
fvm flutter widget-preview start          # pratinjau komponen di Chrome (lib/dev/previews.dart)
```

CI: `../.github/workflows/mobile.yml` (format, analyze, test, `pub outdated`, APK debug sebagai artefak). Berjalan bila `mobile-pro/**` atau `docs/05-API-CONTRACT.md` berubah.

## Paket (03 §5 + persetujuan Mas 15 Nov 2026)

Terpasang di T-030 (versi di `pubspec.lock`): `dio` 5.11.1, `flutter_riverpod` 3.5.0, `go_router` 18.1.4, `flutter_secure_storage` 11.2.0, `package_info_plus` 10.2.2, `uuid` 4.6.0, `intl` 0.20.3, **`material_ui` 1.11.0** (disetujui: `package:flutter/material.dart` deprecated sejak Flutter 3.47). Dev: `flutter_lints` 6.0.0.

- Import Material **selalu** `package:material_ui/material_ui.dart`, bukan `package:flutter/material.dart`. Lokalisasi: `GlobalMaterialLocalizations.delegates` dari `material_ui` (bukan `flutter_localizations`).
- **Bagian lokal T-042 (dikerjakan awal, 15 Nov 2026, keputusan Mas):** `camera` 0.12.2, `geolocator` 14.0.1, `flutter_image_compress` 2.5.1, `connectivity_plus` 7.3.2, `sqlite3` 3.9.1 (SQLCipher lewat `hooks:` di pubspec). Antrean memakai `sqlite3` langsung, **tanpa drift/codegen** (keputusan Mas: satu tabel). Model/OS HP untuk login lewat kanal native `deviceInfo` (T-032), tanpa `device_info_plus`. Plugin kamera/kompresi menyisipkan `RECORD_AUDIO` & `READ_EXTERNAL_STORAGE`: dibuang dengan `tools:node="remove"` di manifest; setelah menambah plugin, cek `build/app/intermediates/merged_manifests/debug/processDebugManifest/AndroidManifest.xml`. `libsqlcipher.so` ±5 MB per ABI → APK rilis wajib `--split-per-abi` atau ABI terbatas (T-047, NFR-014).
- Tanpa `crypto`/`url_launcher`: ANDROID_ID, SHA-256, dan membuka URL unduh lewat kanal native `pakis_pro/device` di `android/app/src/main/kotlin/.../MainActivity.kt` (keputusan Mas). Fungsi native baru ditambah di kanal yang sama + `NativeDevice`, bukan paket baru.

## Struktur

```
lib/
├── main.dart              # baca versi app, tema tersimpan, format id_ID → ProviderScope
├── app/                   # app.dart (MaterialApp.router, tema dari config), router.dart (gerbang versi)
├── core/
│   ├── api/               # ApiClient (header 05 §2.4), ApiError (05 §2.3 → pesan Indonesia), ulid
│   ├── config/            # AppConfig (/config), ConfigRepository (cache offline), Semver
│   ├── device/            # DeviceId (SHA-256(ANDROID_ID + install id)), NativeDevice
│   ├── listing/           # ListingSpec/ListingQuery/ListingPage (05 §3)
│   ├── location/          # LocationService (GPS 15 s, akurasi dari config, isMocked → server)
│   ├── offline/           # QueueDb (SQLCipher), SyncService (FIFO, idempoten, backoff), provider
│   ├── storage/           # SecureStore + StoreKeys
│   ├── theme/             # token 07 §1.1, BrandColor (port BrandTheme.php), AppTheme
│   ├── format.dart        # tanggal/jam/angka Indonesia (07 §7)
│   └── providers.dart     # provider inti (override di main() & test)
├── features/<fitur>/      # startup (splash, wajib update), auth (T-032), attendance/capture (selfie), …
└── shared/widgets/        # PagedList, FilterSheet, EmptyState, StatusBadge
```

Nama berkas snake_case (lint `file_names`); kelas tetap seperti di TASKS (`ApiClient`, `DeviceId`, …).

## Aturan & pola

- **Semua request lewat `ApiClient`** (`apiClientProvider`). Jangan membuat `Dio` lain: header `X-Device-Id`/`X-App-Version`/`X-Request-Id` dan penanganan 426 ada di sana. Layar hanya menangkap `ApiError` dan menampilkan `error.message`; galat field dari `error.fieldErrors`.
- **Kode galat baru di server** → tambah di `ApiErrorCode` (pesan Indonesia). `api_error_contract_test.dart` membaca tabel `docs/05-API-CONTRACT.md` §2.3 dua arah dan akan merah bila lupa.
- **Setting bisnis dari `/config`** (`currentConfigProvider`), tidak pernah konstanta. `AppEnv` hanya untuk nilai build/teknis (URL API, timeout jaringan).
- **Sesi (T-032):** `sessionProvider` (`features/auth/session.dart`) = `SignedOut` | `SignedIn(MobileUser)`; token (`StoreKeys.authToken`) + pengguna (`StoreKeys.authUser`) hanya di `SecureStore`. `ApiClient` memanggil `onSessionInvalid` untuk 401 `UNAUTHENTICATED`/`DEVICE_MISMATCH` pada request ber-token (→ keluar + pesan) dan `onPasswordChangeRequired` untuk `PASSWORD_CHANGE_REQUIRED`; layar tidak menangani sendiri. Kerja latar selama sesi (sinkron antrean) lewat `SessionHooks` (`sessionHooksProvider`, di test `FakeSessionHooks`).
- **Gerbang versi & sesi (BR-027, SEC-006):** `requiredLocation()` di `app/router.dart` memaksa `/perbarui` bila versi < `min_version` (config awal atau tersimpan) atau ada 426 dari endpoint mana pun (`updateGateProvider`), lalu menerapkan sesi (`AuthGate`): belum masuk → hanya `Routes.public`; wajib ganti password → hanya `/ganti-password`; sudah masuk → layar sebelum masuk ke `/beranda`. Rute baru otomatis ikut terkunci; jangan menambah jalan keluar dari layar itu.
- **Daftar mobile** wajib `PagedList` (+ `FilterSheet` otomatis dari `meta.listing.spec`). Layar cukup memberi `fetch: (q) => api.get(path, query: q.toQueryParameters(spec)).then((r) => ListingPage.fromResponse(r, Model.fromJson))` dan `itemBuilder`. Filter ber-`options_source` belum bisa diedit di HP (belum ada lookup di API v1).
- **Status** pakai `StatusBadge(status: …)` (peta 07 §6). **Warna** dari `context.tokens` (`PakisTokens`), bukan `Color(0x…)` di layar.
- **Penyimpanan:** token/install id/config di `SecureStore` (`StoreKeys`). Jangan log token, device_uid, atau koordinat.
- **Android:** `allowBackup=false` + `data_extraction_rules.xml`, cleartext mati di build rilis (hanya `src/debug` mengizinkan HTTP lokal). minSdk 26. `applicationId` **`com.pakisjayaabadi.pakispro` final** (dikonfirmasi Mas 15 Nov 2026): jangan diganti, HP yang sudah memasang tidak bisa memperbarui ke id lain.
- **Test:** `test/helpers/` punya `FakeAdapter` (server palsu), `FakeNativeDevice`, `MemorySecureStore`, `pumpPakisPro()` (aplikasi utuh, layar 360×800 dp). Di `testWidgets`, jangan `await` request Dio langsung (zona fake-async menggantung): picu lalu `pumpAndSettle()`.

## Acuan visual: prototipe (07 §1, §5)

`konsep/PAKIS_ONE_Prototipe_Mobile_v1.0.html` (di luar git, minta ke Mas bila tidak ada). Buka langsung di browser; peran **Pegawai** = Pakis Pro Fase 0,5. Peran Sales/Driver/Gudang/Owner dan aplikasi Pakis Mitra adalah Fase 1+: **jangan dibangun tanpa task**.

Sudah sama dengan T-030: semua token warna (= 07 §1.1 = `PakisTokens`), header `primaryStrong` + teks putih, kartu putih radius 12 bergaris tepi, badge pil berlatar lembut (`StatusBadge`).

Pola untuk layar berikutnya:
- **Header:** judul tebal + subjudul kecil (nama · jabatan/lokasi), lonceng notifikasi di kanan (T-043).
- **Beranda Absen (T-042):** kartu shift (jam, `— → —` masuk/pulang, lokasi · radius), tombol bulat besar berikon sidik jari "Absen Masuk"/"Absen Pulang" dengan cincin lembut, kartu status Perangkat / Lokasi (GPS) / Acuan waktu (waktu server, ADR-010).
- **Item daftar (T-043):** ikon dalam kotak radius lembut di kiri (latar warna nada status), judul tebal, keterangan abu-abu, `StatusBadge` di kanan. Judul bagian huruf kapital kecil abu-abu ("MINGGU INI").
- **Token lembut** prototipe belum ada di 07 §1.1: `--primary-soft` `#E2EFE4`/`#21352A`, `--accent-soft` `#FBEFDC`/`#3A2D1A`, `--danger-soft` `#FBE6E4`/`#3A201E`, `--info-soft` `#E4EEF8`/`#1C2A38`. Untuk latar nada status pakai `tokens.statusBg` (sudah AA, sama dengan web); tambah token lain hanya bila perlu, dengan cek kontras.
- **Tab bawah:** tinggi ≥ 52 dp, ikon + label, aktif = `primary` tebal.

Bila prototipe berbeda dari spesifikasi, **dokumen spesifikasi yang berlaku**:
- Label status mengikuti **07 §6** ("Hadir", "Telat"), bukan teks prototipe ("Tepat waktu", "Terlambat 11 mnt"). Selisih menit boleh tampil sebagai keterangan, bukan sebagai label.
- Isi tab bawah mengikuti **07 §5** (Absen, Riwayat, Koreksi, Persetujuan bila berhak, Notifikasi, Profil). "Slip gaji" dan "cuti" di prototipe di luar Fase 0,5.
- Login, lupa password, wajib ganti password, splash, dan wajib update **tidak ada** di prototipe: pakai token + gaya header di atas, perilaku dari 07 §5.

## Absen offline & selfie (bagian lokal T-042)

- **Antrean:** `QueueDb.open()` gagal keras bila SQLCipher tidak aktif (`PRAGMA cipher_version`) atau kunci salah. Kunci 256 bit di `StoreKeys.queueDbKey`; DB di `noBackupFilesDir` (kanal native `noBackupDir`). Selfie disimpan sebagai BLOB di DB terenkripsi, dibuang saat baris `rejected`.
- **Sinkron:** `SyncService.sync()` FIFO, berhenti di baris pertama yang masih backoff (absen pulang tidak mendahului absen masuk). 2xx/`replayed` = selesai; jaringan/5xx/429 = backoff 30 s × 2ⁿ (maks 15 menit, jitter); 401/426/perangkat/wajib ganti password = berhenti tanpa menandai baris; galat lain = `rejected`. `start(online:)` memicu saat koneksi kembali (abaikan backoff) + timer 1 menit (NFR-007). **Belum dinyalakan di `app.dart`**: panggil `start()` setelah login (T-032), `stop()` saat keluar.
- **`device_clock_offset_s`:** `ApiClient.onServerClock` membaca header `Date` setiap respons → `clockOffsetProvider` (disimpan di `SecureStore`).
- **Lokasi:** `LocationService.acquire(maxAccuracyM: config.attendance.maxAccuracyM)`. `isMock` **tidak** ditolak di HP, tetap dikirim agar server mencatat & memberi tahu (BR-065).
- **Selfie:** `SelfieCaptureScreen.open(context)` → JPEG ≤ 100 KB, sisi terpanjang 720 px. `flutter_image_compress` mengecilkan sampai sisi **pendek** = `minWidth`, jadi `SelfieCompressor` menghitung `minSide = 720 × pendek/panjang`.
- **Menunggu T-036:** beranda absen (kartu shift dari `attendance/today`), layar hasil, `AttendanceRecorder` (kirim online dulu, bila galat jaringan → `SyncService.enqueue` dengan `is_offline`), integration test di HP Android 8 nyata.

## Rilis APK (persiapan T-047, SEC-067)

**Distribusi (keputusan Mas 15 Nov 2026): dua APK per ABI**, bukan satu APK gabungan. Ukuran terukur 15 Nov 2026 (obfuscated, R8): `app-arm64-v8a-release.apk` 21,7 MB (HP 64-bit) dan `app-armeabi-v7a-release.apk` 18,3 MB (HP 32-bit / Android Go). Gabungan ARM = 38,9 MiB (40,8 juta byte), nyaris melewati NFR-014, sehingga ditolak. x86_64 tidak dibagikan. **Sisi server (T-046/T-047, bukan sesi mobile):** `mobile.download_url` harus menunjuk halaman unduh dengan dua pilihan ("HP baru 64-bit" / "HP lama 32-bit"), atau tambah setting URL per ABI (perubahan registri `08` §2 + `05` §5.2, tanya Mas).

**Kunci rilis.** `android/app/build.gradle.kts` membaca `android/key.properties` (lokal, di-ignore git: `storeFile`, `storePassword`, `keyAlias`, `keyPassword`) atau env `PAKIS_KEYSTORE_PATH` / `PAKIS_KEYSTORE_PASSWORD` / `PAKIS_KEY_ALIAS` / `PAKIS_KEY_PASSWORD`. Tanpa itu build rilis **gagal** (fail-closed). `PAKIS_DEBUG_SIGN_RELEASE=true` hanya untuk uji/ukur lokal & CI: APK bertanda tangan debug, jangan dibagikan.

**Sekali, oleh Owner (agent tidak membuat/menyimpan keystore produksi):**
1. `"C:\Program Files\Android\Android Studio\jbr\bin\keytool.exe" -genkeypair -v -keystore pakis-release.jks -keyalg RSA -keysize 4096 -validity 10000 -alias pakis-pro`
2. Cadangkan `pakis-release.jks` + kata sandinya offline di ≥ 2 tempat aman milik Owner. **Keystore hilang = HP pegawai tidak bisa memperbarui aplikasi (wajib hapus & pasang ulang).**
3. GitHub → Settings → Environments → `rilis-mobile` (tambahkan reviewer wajib) → secrets `PAKIS_KEYSTORE_BASE64` (`base64 -w0 pakis-release.jks`), `PAKIS_KEYSTORE_PASSWORD`, `PAKIS_KEY_ALIAS`, `PAKIS_KEY_PASSWORD`; variable `PAKIS_API_BASE_URL` (wajib `https://`).

**Setiap rilis:**
1. Naikkan `version:` di `pubspec.yaml` (`x.y.z+N`, N selalu naik; Flutter menambah 1000×ABI ke versionCode per APK).
2. Commit & push ke `main`, CI hijau (job `flutter` sudah memeriksa ukuran APK rilis < 40 MB).
3. Actions → **mobile** → Run workflow (main) → setujui environment `rilis-mobile`. Job `release` memeriksa secret & URL https, membangun `--split-per-abi --target-platform android-arm,android-arm64 --obfuscate --split-debug-info`, menolak tanda tangan debug, mencetak SHA-256 sertifikat & `SHA256SUMS.txt`.
4. Unduh artefak `pakis-pro-release-apk`; simpan `pakis-pro-release-symbols` (simbol Dart + `mapping.txt` R8) **offline per versi**, karena tanpa itu stack trace build obfuscated tidak terbaca.
5. Unggah kedua APK ke server unduh, lalu di Pengaturan → Mobile isi `mobile.latest_version` (dan `mobile.min_version` bila wajib update).
6. Checklist `09` §7.2: APK rilis bertanda tangan, `allowBackup=false` (manifest), cleartext mati (manifest rilis), obfuscation aktif, versi minimum di setting benar.

Deteksi emulator/root (SEC-023 "best effort") **belum** dibuat: bertentangan dengan arah OQ-010 (tandai anomali, tidak menolak). Tunggu jawaban Owner.
