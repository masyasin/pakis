import 'package:flutter_test/flutter_test.dart';
import 'package:pakis_pro/app/router.dart';
import 'package:pakis_pro/core/config/app_config.dart';
import 'package:pakis_pro/features/startup/startup_controller.dart';

import '../helpers/fakes.dart';

void main() {
  final ready = StartupReady(AppConfig.fromJson((configJson()['data']! as Map).cast()), fromCache: false);

  String? go(String location, AuthGate auth, {bool updateForced = false}) =>
      requiredLocation(startup: ready, updateForced: updateForced, location: location, auth: auth);

  test('426 / versi lama menang atas sesi apa pun', () {
    for (final auth in AuthGate.values) {
      expect(go(Routes.home, auth, updateForced: true), Routes.update);
    }
  });

  test('sesi belum terbaca → tetap di splash', () {
    expect(go(Routes.login, AuthGate.unknown), Routes.splash);
    expect(go(Routes.splash, AuthGate.unknown), isNull);
  });

  test('belum masuk → hanya rute publik; lainnya ke masuk', () {
    for (final route in Routes.public) {
      expect(go(route, AuthGate.signedOut), isNull, reason: route);
    }
    expect(go(Routes.home, AuthGate.signedOut), Routes.login);
    expect(go(Routes.changePassword, AuthGate.signedOut), Routes.login);
    expect(go(Routes.splash, AuthGate.signedOut), Routes.login);
  });

  test('SEC-006 wajib ganti password → semua rute ke layar ganti password', () {
    expect(go(Routes.home, AuthGate.mustChangePassword), Routes.changePassword);
    expect(go(Routes.login, AuthGate.mustChangePassword), Routes.changePassword);
    expect(go('/riwayat', AuthGate.mustChangePassword), Routes.changePassword);
    expect(go(Routes.changePassword, AuthGate.mustChangePassword), isNull);
  });

  test('sudah masuk → layar sebelum masuk diarahkan ke beranda, rute lain bebas', () {
    for (final route in [...Routes.public, Routes.splash, Routes.update, Routes.changePassword]) {
      expect(go(route, AuthGate.signedIn), Routes.home, reason: route);
    }
    expect(go(Routes.home, AuthGate.signedIn), isNull);
    expect(go('/riwayat', AuthGate.signedIn), isNull);
  });
}
