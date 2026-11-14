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
```

CI: `../.github/workflows/mobile.yml` (format, analyze, test, `pub outdated`, APK debug sebagai artefak). Berjalan bila `mobile-pro/**` atau `docs/05-API-CONTRACT.md` berubah.

## Paket (03 §5 + persetujuan Mas 15 Nov 2026)

Terpasang di T-030 (versi di `pubspec.lock`): `dio` 5.11.1, `flutter_riverpod` 3.5.0, `go_router` 18.1.4, `flutter_secure_storage` 11.2.0, `package_info_plus` 10.2.2, `uuid` 4.6.0, `intl` 0.20.3, **`material_ui` 1.11.0** (disetujui: `package:flutter/material.dart` deprecated sejak Flutter 3.47). Dev: `flutter_lints` 6.0.0.

- Import Material **selalu** `package:material_ui/material_ui.dart`, bukan `package:flutter/material.dart`. Lokalisasi: `GlobalMaterialLocalizations.delegates` dari `material_ui` (bukan `flutter_localizations`).
- Menyusul di task pemakainya (agar izin kamera/lokasi tidak ikut sebelum dipakai): `camera`, `geolocator`, `flutter_image_compress`, `connectivity_plus` (T-042), `device_info_plus` (model/OS untuk login, T-032).
- `drift` + enkripsi ditunda ke **T-042** (antrean offline `queue_db.dart`): `sqlcipher_flutter_libs` sudah EOL (0.7.0+eol, "pakai `package:sqlite3` 3.x"). Putuskan pengganti bersama Mas sebelum T-042.
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
│   ├── storage/           # SecureStore + StoreKeys
│   ├── theme/             # token 07 §1.1, BrandColor (port BrandTheme.php), AppTheme
│   ├── format.dart        # tanggal/jam/angka Indonesia (07 §7)
│   └── providers.dart     # provider inti (override di main() & test)
├── features/<fitur>/      # startup (splash, wajib update), auth (T-032), attendance, …
└── shared/widgets/        # PagedList, FilterSheet, EmptyState, StatusBadge
```

Nama berkas snake_case (lint `file_names`); kelas tetap seperti di TASKS (`ApiClient`, `DeviceId`, …).

## Aturan & pola

- **Semua request lewat `ApiClient`** (`apiClientProvider`). Jangan membuat `Dio` lain: header `X-Device-Id`/`X-App-Version`/`X-Request-Id` dan penanganan 426 ada di sana. Layar hanya menangkap `ApiError` dan menampilkan `error.message`; galat field dari `error.fieldErrors`.
- **Kode galat baru di server** → tambah di `ApiErrorCode` (pesan Indonesia). `api_error_contract_test.dart` membaca tabel `docs/05-API-CONTRACT.md` §2.3 dua arah dan akan merah bila lupa.
- **Setting bisnis dari `/config`** (`currentConfigProvider`), tidak pernah konstanta. `AppEnv` hanya untuk nilai build/teknis (URL API, timeout jaringan).
- **Gerbang versi (BR-027):** `requiredLocation()` di `app/router.dart` memaksa `/perbarui` bila versi < `min_version` (config awal atau tersimpan) atau ada 426 dari endpoint mana pun (`updateGateProvider`). Rute baru otomatis ikut terkunci; jangan menambah jalan keluar dari layar itu.
- **Daftar mobile** wajib `PagedList` (+ `FilterSheet` otomatis dari `meta.listing.spec`). Layar cukup memberi `fetch: (q) => api.get(path, query: q.toQueryParameters(spec)).then((r) => ListingPage.fromResponse(r, Model.fromJson))` dan `itemBuilder`. Filter ber-`options_source` belum bisa diedit di HP (belum ada lookup di API v1).
- **Status** pakai `StatusBadge(status: …)` (peta 07 §6). **Warna** dari `context.tokens` (`PakisTokens`), bukan `Color(0x…)` di layar.
- **Penyimpanan:** token/install id/config di `SecureStore` (`StoreKeys`). Jangan log token, device_uid, atau koordinat.
- **Android:** `allowBackup=false` + `data_extraction_rules.xml`, cleartext mati di build rilis (hanya `src/debug` mengizinkan HTTP lokal). minSdk 26. `applicationId` `com.pakisjayaabadi.pakispro` — konfirmasi ke Mas sebelum rilis pertama (T-047).
- **Test:** `test/helpers/` punya `FakeAdapter` (server palsu), `FakeNativeDevice`, `MemorySecureStore`, `pumpPakisPro()` (aplikasi utuh, layar 360×800 dp). Di `testWidgets`, jangan `await` request Dio langsung (zona fake-async menggantung): picu lalu `pumpAndSettle()`.
