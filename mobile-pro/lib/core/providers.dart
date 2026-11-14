import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import 'api/api_client.dart';
import 'api/api_error.dart';
import 'config/config_repository.dart';
import 'device/device_id.dart';
import 'device/native_device.dart';
import 'env.dart';
import 'storage/secure_store.dart';
import 'theme/theme_mode_store.dart';

/// Provider inti. Nilai platform (versi app, penyimpanan, tema awal) di-override di `main()`
/// dan di test, sehingga tidak ada kanal platform yang tersentuh saat test.

final secureStoreProvider = Provider<SecureStore>((ref) => throw UnimplementedError('override di main()'));

/// Versi Pakis Pro (`version` di pubspec tanpa build number), untuk `X-App-Version`.
final appVersionProvider = Provider<String>((ref) => throw UnimplementedError('override di main()'));

final initialThemeModeProvider = Provider<ThemeMode>((ref) => ThemeMode.system);

final apiBaseUrlProvider = Provider<String>((ref) => AppEnv.apiBaseUrl);

final nativeDeviceProvider = Provider<NativeDevice>((ref) => const MethodChannelNativeDevice());

final deviceIdProvider = Provider<DeviceId>(
  (ref) => DeviceId(store: ref.watch(secureStoreProvider), native: ref.watch(nativeDeviceProvider)),
);

/// Adapter HTTP pengganti untuk test (null = adapter bawaan Dio).
final httpAdapterProvider = Provider<HttpClientAdapter?>((ref) => null);

final apiClientProvider = Provider<ApiClient>((ref) {
  final store = ref.watch(secureStoreProvider);
  final deviceId = ref.watch(deviceIdProvider);
  return ApiClient(
    baseUrl: ref.watch(apiBaseUrlProvider),
    appVersion: ref.watch(appVersionProvider),
    deviceId: deviceId.uid,
    token: () => store.read(StoreKeys.authToken),
    adapter: ref.watch(httpAdapterProvider),
    onUpdateRequired: (error) => ref.read(updateGateProvider.notifier).require(error),
    onServerClock: (seconds) => ref.read(clockOffsetProvider.notifier).set(seconds),
  );
});

final configRepositoryProvider = Provider<ConfigRepository>(
  (ref) => ConfigRepository(api: ref.watch(apiClientProvider), store: ref.watch(secureStoreProvider)),
);

/// Diisi saat endpoint mana pun menjawab `426 APP_UPDATE_REQUIRED` (BR-027). Sekali terisi,
/// tidak bisa dikosongkan dari UI: satu-satunya jalan keluar adalah memasang versi baru.
class UpdateGate extends Notifier<ApiError?> {
  @override
  ApiError? build() => null;

  void require(ApiError error) => state = error;
}

final updateGateProvider = NotifierProvider<UpdateGate, ApiError?>(UpdateGate.new);

class ThemeModeController extends Notifier<ThemeMode> {
  @override
  ThemeMode build() => ref.watch(initialThemeModeProvider);

  Future<void> set(ThemeMode mode) async {
    state = mode;
    await ThemeModeStore(ref.read(secureStoreProvider)).write(mode);
  }
}

final themeModeProvider = NotifierProvider<ThemeModeController, ThemeMode>(ThemeModeController.new);

/// Selisih detik jam server − jam HP terakhir yang diketahui (header `Date`), untuk
/// `device_clock_offset_s` (05 §5.4, BR-067). Disimpan agar tetap ada saat HP dibuka offline.
class ClockOffset extends Notifier<int?> {
  @override
  int? build() {
    final store = ref.watch(secureStoreProvider);
    store.read(StoreKeys.clockOffset).then((value) {
      if (state == null && value != null) {
        state = int.tryParse(value);
      }
    }, onError: (Object _) {});
    return null;
  }

  void set(int seconds) {
    if (state == seconds) {
      return;
    }
    state = seconds;
    unawaited(ref.read(secureStoreProvider).write(StoreKeys.clockOffset, '$seconds'));
  }
}

final clockOffsetProvider = NotifierProvider<ClockOffset, int?>(ClockOffset.new);
