import 'package:flutter/services.dart';

/// Fungsi native kecil di `MainActivity.kt` (kanal `pakis_pro/device`).
///
/// Dipakai sebagai pengganti paket di luar 03 §5 (keputusan Mas, 15 Nov 2026): ANDROID_ID,
/// SHA-256 (`MessageDigest`), dan membuka URL unduh APK (hanya `https`).
abstract interface class NativeDevice {
  /// `Settings.Secure.ANDROID_ID`, atau null bila HP tidak memberikannya.
  Future<String?> androidId();

  /// SHA-256 dari teks UTF-8, heksadesimal huruf kecil (64 karakter).
  Future<String> sha256Hex(String input);

  /// Membuka URL `https` di aplikasi lain (browser/pengunduh). `false` bila gagal.
  Future<bool> openUrl(String url);

  /// Model & versi Android untuk pendaftaran perangkat (04 §2.12): `Samsung SM-A045F`,
  /// `Android 13`. Pengganti `device_info_plus` (keputusan T-032: kanal native yang sama).
  Future<DeviceInfo> deviceInfo();

  /// Folder privat aplikasi yang **tidak** ikut cadangan Android (`noBackupFilesDir`), untuk DB
  /// antrean offline. Pengganti `path_provider` (tidak ada di 03 §5).
  Future<String> privateDir();
}

/// Keterangan HP yang dikirim saat login, hanya untuk dikenali admin di halaman Perangkat.
class DeviceInfo {
  const DeviceInfo({this.model, this.osVersion});

  final String? model;
  final String? osVersion;
}

class MethodChannelNativeDevice implements NativeDevice {
  const MethodChannelNativeDevice([this._channel = const MethodChannel('pakis_pro/device')]);

  final MethodChannel _channel;

  @override
  Future<String?> androidId() => _channel.invokeMethod<String>('androidId');

  @override
  Future<String> sha256Hex(String input) async {
    final hex = await _channel.invokeMethod<String>('sha256Hex', {'input': input});
    if (hex == null) {
      throw PlatformException(code: 'NO_RESULT', message: 'sha256Hex tidak mengembalikan nilai');
    }
    return hex;
  }

  @override
  Future<DeviceInfo> deviceInfo() async {
    try {
      final info = await _channel.invokeMapMethod<String, Object?>('deviceInfo');
      return DeviceInfo(model: info?['model'] as String?, osVersion: info?['os_version'] as String?);
    } on PlatformException {
      // Hanya pelengkap tampilan admin: login tetap jalan tanpa model/OS.
      return const DeviceInfo();
    }
  }

  @override
  Future<String> privateDir() async {
    final dir = await _channel.invokeMethod<String>('noBackupDir');
    if (dir == null || dir.isEmpty) {
      throw PlatformException(code: 'NO_RESULT', message: 'noBackupDir tidak mengembalikan folder');
    }
    return dir;
  }

  @override
  Future<bool> openUrl(String url) async {
    try {
      return await _channel.invokeMethod<bool>('openUrl', {'url': url}) ?? false;
    } on PlatformException {
      return false;
    }
  }
}
