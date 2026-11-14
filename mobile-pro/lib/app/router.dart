import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';

import '../core/providers.dart';
import '../features/auth/login_screen.dart';
import '../features/startup/splash_screen.dart';
import '../features/startup/startup_controller.dart';
import '../features/startup/update_required_screen.dart';

abstract final class Routes {
  static const splash = '/mulai';
  static const update = '/perbarui';
  static const login = '/masuk';
}

/// Tujuan wajib menurut state startup + gerbang 426, atau null bila bebas bernavigasi.
///
/// Fungsi murni agar aturan gerbang versi bisa diuji tanpa widget: versi < min (dari config
/// awal **atau** 426 dari endpoint mana pun) selalu berakhir di [Routes.update].
String? requiredLocation({required StartupState? startup, required bool updateForced, required String location}) {
  final target = switch (startup) {
    _ when updateForced => Routes.update,
    StartupUpdateRequired() => Routes.update,
    null || StartupFailed() => Routes.splash,
    StartupReady() => null,
  };

  if (target != null) {
    return location == target ? null : target;
  }
  // Sudah siap: splash / layar update tidak relevan lagi.
  return location == Routes.splash || location == Routes.update ? Routes.login : null;
}

final routerProvider = Provider<GoRouter>((ref) {
  // Jembatan Riverpod → go_router: redirect dievaluasi ulang setiap state berubah.
  final refresh = ValueNotifier<int>(0);
  ref
    ..listen(startupProvider, (_, _) => refresh.value++)
    ..listen(updateGateProvider, (_, _) => refresh.value++)
    ..onDispose(refresh.dispose);

  final router = GoRouter(
    initialLocation: Routes.splash,
    refreshListenable: refresh,
    redirect: (context, state) => requiredLocation(
      startup: ref.read(startupProvider).value,
      updateForced: ref.read(updateGateProvider) != null,
      location: state.matchedLocation,
    ),
    routes: [
      GoRoute(path: Routes.splash, builder: (_, _) => const SplashScreen()),
      GoRoute(path: Routes.update, builder: (_, _) => const UpdateRequiredScreen()),
      GoRoute(path: Routes.login, builder: (_, _) => const LoginScreen()),
    ],
  );
  ref.onDispose(router.dispose);
  return router;
});
