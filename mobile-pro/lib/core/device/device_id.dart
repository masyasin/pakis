import 'package:uuid/uuid.dart';

import '../storage/secure_store.dart';
import 'native_device.dart';

/// `device_uid` untuk header `X-Device-Id` dan pendaftaran perangkat (04 §2.12, BR-040):
/// **SHA-256(ANDROID_ID + install id)**.
///
/// - ANDROID_ID membuat nilai terikat ke HP + pengguna Android.
/// - install id (UUID v4 acak, disimpan di [SecureStore]) membuatnya terikat ke pemasangan ini:
///   tetap sama antar restart, berubah bila aplikasi dihapus lalu dipasang ulang, dan tidak bisa
///   disalin lewat cadangan karena `allowBackup=false`.
/// - Hanya hash yang keluar dari HP, ANDROID_ID mentah tidak pernah dikirim.
class DeviceId {
  DeviceId({required this._store, required this._native, String Function()? newInstallId})
    : _newInstallId = newInstallId ?? (() => const Uuid().v4());

  final SecureStore _store;
  final NativeDevice _native;
  final String Function() _newInstallId;
  Future<String>? _uid;

  static final _hex64 = RegExp(r'^[0-9a-f]{64}$');

  /// Dihitung sekali per proses lalu di-cache (dipanggil di setiap request API).
  Future<String> uid() async {
    final pending = _uid ??= _compute();
    try {
      return await pending;
    } catch (_) {
      _uid = null; // jangan simpan kegagalan: request berikutnya mencoba lagi
      rethrow;
    }
  }

  Future<String> _compute() async {
    var installId = await _store.read(StoreKeys.installId);
    if (installId == null || installId.isEmpty) {
      installId = _newInstallId();
      await _store.write(StoreKeys.installId, installId);
    }

    // ANDROID_ID kosong (jarang, HP tertentu) tetap menghasilkan uid unik per pemasangan.
    final androidId = await _native.androidId() ?? '';
    final uid = await _native.sha256Hex('$androidId$installId');
    if (!_hex64.hasMatch(uid)) {
      throw StateError('device_uid tidak sah: harus 64 karakter heksadesimal');
    }
    return uid;
  }
}
