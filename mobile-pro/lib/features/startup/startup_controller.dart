import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_error.dart';
import '../../core/config/app_config.dart';
import '../../core/providers.dart';

/// Hasil splash (07 §5 "Splash / cek versi").
sealed class StartupState {
  const StartupState();
}

/// Config ada dan versi memenuhi `min_version`.
class StartupReady extends StartupState {
  const StartupReady(this.config, {required this.fromCache});

  final AppConfig config;
  final bool fromCache;
}

/// Versi < `min_version`: berhenti di layar "Perbarui aplikasi", tanpa jalan pintas.
class StartupUpdateRequired extends StartupState {
  const StartupUpdateRequired(this.config);

  final AppConfig config;
}

/// Tidak ada config sama sekali (belum pernah online & server tidak terjangkau).
class StartupFailed extends StartupState {
  const StartupFailed(this.error);

  final ApiError error;
}

/// Kegagalan dikembalikan sebagai [StartupFailed] (bukan dilempar) agar Riverpod tidak
/// mencoba ulang otomatis: pengguna memilih sendiri lewat tombol "Coba Lagi" ([retry]).
class StartupController extends AsyncNotifier<StartupState> {
  @override
  Future<StartupState> build() async {
    try {
      final loaded = await ref.read(configRepositoryProvider).load();
      final version = ref.read(appVersionProvider);
      return loaded.config.requiresUpdate(version)
          ? StartupUpdateRequired(loaded.config)
          : StartupReady(loaded.config, fromCache: loaded.fromCache);
    } on ApiError catch (e) {
      return StartupFailed(e);
    }
  }

  void retry() => ref.invalidateSelf();
}

final startupProvider = AsyncNotifierProvider<StartupController, StartupState>(StartupController.new);

/// Config yang sedang dipakai (untuk tema), null selama splash.
final currentConfigProvider = Provider<AppConfig?>((ref) {
  return switch (ref.watch(startupProvider).value) {
    StartupReady(:final config) || StartupUpdateRequired(:final config) => config,
    _ => null,
  };
});
