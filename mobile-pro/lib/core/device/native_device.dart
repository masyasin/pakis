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
  Future<bool> openUrl(String url) async {
    try {
      return await _channel.invokeMethod<bool>('openUrl', {'url': url}) ?? false;
    } on PlatformException {
      return false;
    }
  }
}
