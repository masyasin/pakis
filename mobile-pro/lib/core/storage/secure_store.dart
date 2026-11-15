import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Kunci penyimpanan aman. Semua data lokal yang bukan antrean offline ada di sini.
abstract final class StoreKeys {
  /// UUID acak sekali per pemasangan, bahan `device_uid` (lihat `DeviceId`).
  static const installId = 'device.install_id';

  /// JSON `/config` terakhir yang berhasil, dipakai saat offline (07 §5 splash).
  static const lastConfig = 'config.last';

  /// Pilihan tema pengguna: `system` | `light` | `dark` (07 §1.1).
  static const themeMode = 'ui.theme_mode';

  /// Token Sanctum (SEC-020). Diisi oleh alur login T-032.
  static const authToken = 'auth.token';

  /// JSON pengguna terakhir dari login / `GET /me` (05 §5.3), agar aplikasi bisa dibuka offline
  /// tanpa login ulang. Tidak memuat token atau password.
  static const authUser = 'auth.user';

  /// Kunci SQLCipher antrean offline (64 hex = 256 bit), dibuat sekali (T-042).
  static const queueDbKey = 'offline.queue_key';

  /// Selisih detik jam server − jam HP terakhir, dari header `Date` (05 §5.4, BR-067).
  static const clockOffset = 'clock.offset_s';
}

/// Penyimpanan kunci-nilai terenkripsi.
///
/// Antarmuka sendiri (bukan `FlutterSecureStorage` langsung) agar test memakai
/// [MemorySecureStore] tanpa kanal platform.
abstract interface class SecureStore {
  Future<String?> read(String key);

  Future<void> write(String key, String value);

  Future<void> delete(String key);
}

/// Implementasi Android: `flutter_secure_storage` (kunci di Android Keystore, SEC-020).
/// Data hilang saat aplikasi dihapus, dan `allowBackup=false` mencegahnya dipulihkan ke HP lain.
class FlutterSecureStore implements SecureStore {
  FlutterSecureStore([FlutterSecureStorage? storage]) : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String value) => _storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);
}

/// Untuk test dan pratinjau.
class MemorySecureStore implements SecureStore {
  MemorySecureStore([Map<String, String>? initial]) : values = {...?initial};

  final Map<String, String> values;

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async => values[key] = value;

  @override
  Future<void> delete(String key) async => values.remove(key);
}
