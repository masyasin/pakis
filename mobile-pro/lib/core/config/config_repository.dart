import 'dart:convert';

import '../api/api_client.dart';
import '../api/api_error.dart';
import '../storage/secure_store.dart';
import 'app_config.dart';

class LoadedConfig {
  const LoadedConfig(this.config, {required this.fromCache});

  final AppConfig config;

  /// `true` = server tidak terjangkau, memakai config terakhir yang tersimpan.
  final bool fromCache;
}

/// Mengambil `/config` dan menyimpan salinan terakhir (07 §5: "Bila offline → pakai config
/// terakhir").
///
/// Gerbang versi tetap berlaku pada config tersimpan: mode pesawat tidak bisa dipakai untuk
/// melewati `min_version` yang sudah pernah diterima HP ini.
class ConfigRepository {
  ConfigRepository({required this._api, required this._store});

  static const path = '/api/v1/config';

  final ApiClient _api;
  final SecureStore _store;

  Future<LoadedConfig> load() async {
    try {
      final response = await _api.get(path);
      final json = response.object;
      final config = AppConfig.fromJson(json);
      await _store.write(StoreKeys.lastConfig, jsonEncode(json));
      return LoadedConfig(config, fromCache: false);
    } on ApiError catch (e) {
      // Server tidak terjangkau / sedang perawatan → config terakhir. Galat lain (respons
      // rusak, 4xx tak terduga) tetap ditampilkan agar tidak tersembunyi.
      final usable =
          e.isConnectivity || e.code == ApiErrorCode.serviceUnavailable || e.code == ApiErrorCode.serverError;
      final cached = usable ? await _cached() : null;
      if (cached == null) {
        rethrow;
      }
      return LoadedConfig(cached, fromCache: true);
    }
  }

  Future<AppConfig?> _cached() async {
    final text = await _store.read(StoreKeys.lastConfig);
    if (text == null) {
      return null;
    }
    try {
      final json = jsonDecode(text);
      return json is Map ? AppConfig.fromJson(json.cast<String, Object?>()) : null;
    } on FormatException {
      return null;
    }
  }
}
