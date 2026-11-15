import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';

import '../core/providers.dart';
import '../features/auth/change_password_screen.dart';
import '../features/auth/device_pending_screen.dart';
import '../features/auth/forgot_password_screen.dart';
import '../features/auth/login_screen.dart';
import '../features/auth/reset_otp_screen.dart';
import '../features/auth/session.dart';
import '../features/home/home_screen.dart';
import '../features/startup/splash_screen.dart';
import '../features/startup/startup_controller.dart';
import '../features/startup/update_required_screen.dart';

abstract final class Routes {
  static const splash = '/mulai';
  static const update = '/perbarui';
  static const login = '/masuk';
  static const forgot = '/lupa-password';
  static const forgotCode = '/lupa-password/kode';
  static const devicePending = '/perangkat-menunggu';
  static const changePassword = '/ganti-password';
  static const home = '/beranda';

  /// Rute yang boleh dibuka sebelum masuk.
  static const public = {login, forgot, forgotCode, devicePending};
}

/// Status sesi yang dilihat router (diturunkan dari [SessionState]).
enum AuthGate {
  /// Sesi tersimpan masih dibaca dari penyimpanan.
  unknown,
  signedOut,

  /// Masuk tetapi wajib ganti password (SEC-006): hanya layar ganti password.
  mustChangePassword,
  signedIn,
}

AuthGate authGateOf(AsyncValue<SessionState> session) => switch (session.value) {
  null => AuthGate.unknown,
  SignedOut() => AuthGate.signedOut,
  SignedIn(:final user) when user.mustChangePassword => AuthGate.mustChangePassword,
  SignedIn() => AuthGate.signedIn,
};

/// Tujuan wajib menurut state startup + gerbang 426 + sesi, atau null bila bebas bernavigasi.
///
/// Fungsi murni agar aturan gerbang bisa diuji tanpa widget. Urutan prioritas:
/// 1. versi < min (config awal **atau** 426 dari endpoint mana pun) → [Routes.update];
/// 2. config belum siap → [Routes.splash];
/// 3. sesi belum terbaca → splash; belum masuk → hanya rute [Routes.public];
/// 4. wajib ganti password → hanya [Routes.changePassword] (tanpa jalan pintas);
/// 5. sudah masuk → layar sebelum masuk diarahkan ke [Routes.home].
String? requiredLocation({
  required StartupState? startup,
  required bool updateForced,
  required String location,
  AuthGate auth = AuthGate.signedOut,
}) {
  final startupTarget = switch (startup) {
    _ when updateForced => Routes.update,
    StartupUpdateRequired() => Routes.update,
    null || StartupFailed() => Routes.splash,
    StartupReady() => null,
  };
  if (startupTarget != null) {
    return location == startupTarget ? null : startupTarget;
  }

  String? only(String target) => location == target ? null : target;

  return switch (auth) {
    AuthGate.unknown => only(Routes.splash),
    AuthGate.signedOut => Routes.public.contains(location) ? null : Routes.login,
    AuthGate.mustChangePassword => only(Routes.changePassword),
    AuthGate.signedIn =>
      Routes.public.contains(location) ||
              location == Routes.splash ||
              location == Routes.update ||
              location == Routes.changePassword
          ? Routes.home
          : null,
  };
}

final routerProvider = Provider<GoRouter>((ref) {
  // Jembatan Riverpod → go_router: redirect dievaluasi ulang setiap state berubah.
  final refresh = ValueNotifier<int>(0);
  ref
    ..listen(startupProvider, (_, _) => refresh.value++)
    ..listen(updateGateProvider, (_, _) => refresh.value++)
    ..listen(sessionProvider, (_, _) => refresh.value++)
    ..onDispose(refresh.dispose);

  final router = GoRouter(
    initialLocation: Routes.splash,
    refreshListenable: refresh,
    redirect: (context, state) => requiredLocation(
      startup: ref.read(startupProvider).value,
      updateForced: ref.read(updateGateProvider) != null,
      location: state.matchedLocation,
      auth: authGateOf(ref.read(sessionProvider)),
    ),
    routes: [
      GoRoute(path: Routes.splash, builder: (_, _) => const SplashScreen()),
      GoRoute(path: Routes.update, builder: (_, _) => const UpdateRequiredScreen()),
      GoRoute(path: Routes.login, builder: (_, _) => const LoginScreen()),
      GoRoute(path: Routes.forgot, builder: (_, _) => const ForgotPasswordScreen()),
      GoRoute(path: Routes.forgotCode, builder: (_, _) => const ResetOtpScreen()),
      GoRoute(path: Routes.devicePending, builder: (_, _) => const DevicePendingScreen()),
      GoRoute(path: Routes.changePassword, builder: (_, _) => const ChangePasswordScreen()),
      GoRoute(path: Routes.home, builder: (_, _) => const HomeScreen()),
    ],
  );
  ref.onDispose(router.dispose);
  return router;
});
