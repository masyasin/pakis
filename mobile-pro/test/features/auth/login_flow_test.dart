import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:material_ui/material_ui.dart';
import 'package:pakis_pro/core/storage/secure_store.dart';
import 'package:pakis_pro/features/auth/change_password_screen.dart';
import 'package:pakis_pro/features/auth/device_pending_screen.dart';
import 'package:pakis_pro/features/auth/login_screen.dart';
import 'package:pakis_pro/features/auth/reset_otp_screen.dart';
import 'package:pakis_pro/features/home/home_screen.dart';
import 'package:pakis_pro/features/startup/update_required_screen.dart';

import '../../helpers/app_harness.dart';
import '../../helpers/fakes.dart';

/// Server palsu per path; `/config` selalu tersedia (bisa diganti lewat [config]).
FakeAdapter server(Map<String, FakeReply Function(RequestOptions o)> routes, {Map<String, Object?>? config}) =>
    FakeAdapter((o) {
      if (o.path.endsWith('/api/v1/config')) {
        return FakeReply(200, config ?? configJson());
      }
      for (final entry in routes.entries) {
        if (o.path.endsWith(entry.key)) {
          return entry.value(o);
        }
      }
      return FakeReply(404, errorJson('NOT_FOUND'));
    });

Map<String, Object?> bodyOf(RequestOptions o) => (o.data as Map).cast<String, Object?>();

Future<void> fillLogin(WidgetTester tester, {String login = 'agus.s', String password = 'kopi pagi di gudang'}) async {
  await tester.enterText(find.widgetWithText(TextField, 'Username atau email'), login);
  await tester.enterText(find.widgetWithText(TextField, 'Password'), password);
  await tester.tap(find.widgetWithText(FilledButton, 'Masuk'));
  await tester.pumpAndSettle();
}

void main() {
  group('FR-002 / BR-040 login', () {
    testWidgets('sukses → beranda; body membawa device uid/model/OS; token hanya di SecureStore', (tester) async {
      final store = MemorySecureStore();
      final hooks = FakeSessionHooks();
      final adapter = server({'/auth/login': (_) => FakeReply(200, loginJson())});

      await pumpPakisPro(tester, adapter: adapter, store: store, hooks: hooks);
      expect(find.byType(LoginScreen), findsOneWidget);
      await fillLogin(tester, login: '  agus.s ');

      expect(find.byType(HomeScreen), findsOneWidget);
      expect(find.text('Agus Santoso'), findsOneWidget);
      expect(find.text('Sales · Kantor Pusat Kalirejo'), findsOneWidget);

      final request = adapter.requests.firstWhere((o) => o.path.endsWith('/auth/login'));
      final body = bodyOf(request);
      expect(body['login'], 'agus.s');
      expect(body.containsKey('otp'), isFalse);
      final device = (body['device']! as Map).cast<String, Object?>();
      expect(device['uid'], matches(RegExp(r'^[0-9a-f]{64}$')));
      expect(device['uid'], request.headers['X-Device-Id']);
      expect(device['model'], 'Samsung SM-A045F');
      expect(device['os_version'], 'Android 13');

      // SEC-020: token di penyimpanan aman, pengguna tersimpan tanpa token/password.
      expect(store.values[StoreKeys.authToken], '12|tokenuji');
      expect(store.values[StoreKeys.authUser], isNot(contains('tokenuji')));
      expect(hooks.calls, ['signedIn']);
    });

    testWidgets('SEC-020 aplikasi tidak memakai SharedPreferences sama sekali', (tester) async {
      final lock = File('pubspec.lock').readAsStringSync();
      expect(lock, isNot(contains('shared_preferences')));
    });

    testWidgets('isian kosong ditolak di HP tanpa request', (tester) async {
      final adapter = server({});
      await pumpPakisPro(tester, adapter: adapter);

      await tester.tap(find.widgetWithText(FilledButton, 'Masuk'));
      await tester.pumpAndSettle();

      expect(find.text('Isi username/email dan password.'), findsOneWidget);
      expect(adapter.requests.where((o) => o.path.endsWith('/auth/login')), isEmpty);
    });

    testWidgets('INVALID_CREDENTIALS → pesan seragam, tetap di layar masuk', (tester) async {
      await pumpPakisPro(
        tester,
        adapter: server({'/auth/login': (_) => FakeReply(401, errorJson('INVALID_CREDENTIALS'))}),
      );
      await fillLogin(tester);

      expect(find.byType(LoginScreen), findsOneWidget);
      expect(find.text('Username/email atau password salah.'), findsOneWidget);
    });

    testWidgets('MFA_REQUIRED → field kode muncul → kirim ulang dengan otp → beranda', (tester) async {
      final adapter = server({
        '/auth/login': (o) => bodyOf(o).containsKey('otp')
            ? FakeReply(200, loginJson())
            : FakeReply(401, errorJson('MFA_REQUIRED', details: {'mfa': true})),
      });
      await pumpPakisPro(tester, adapter: adapter);
      await fillLogin(tester);

      expect(find.widgetWithText(TextField, 'Kode verifikasi'), findsOneWidget);
      expect(find.text('Verifikasi dua langkah'), findsOneWidget);

      await tester.enterText(find.widgetWithText(TextField, 'Kode verifikasi'), ' 123456 ');
      await tester.tap(find.widgetWithText(FilledButton, 'Verifikasi & Masuk'));
      await tester.pumpAndSettle();

      expect(find.byType(HomeScreen), findsOneWidget);
      final logins = adapter.requests.where((o) => o.path.endsWith('/auth/login')).toList();
      expect(bodyOf(logins.last)['otp'], '123456');
      expect(bodyOf(logins.last)['password'], 'kopi pagi di gudang');
    });

    testWidgets('MFA_INVALID → pesan kode salah, field kode tetap', (tester) async {
      await pumpPakisPro(
        tester,
        adapter: server({
          '/auth/login': (o) => bodyOf(o).containsKey('otp')
              ? FakeReply(401, errorJson('MFA_INVALID'))
              : FakeReply(401, errorJson('MFA_REQUIRED')),
        }),
      );
      await fillLogin(tester);
      await tester.enterText(find.widgetWithText(TextField, 'Kode verifikasi'), '000000');
      await tester.tap(find.widgetWithText(FilledButton, 'Verifikasi & Masuk'));
      await tester.pumpAndSettle();

      expect(find.text('Kode authenticator atau kode pemulihan salah.'), findsOneWidget);
      expect(find.widgetWithText(TextField, 'Kode verifikasi'), findsOneWidget);
    });

    testWidgets('MFA_SETUP_REQUIRED → arahan aktivasi di web', (tester) async {
      await pumpPakisPro(
        tester,
        adapter: server({'/auth/login': (_) => FakeReply(403, errorJson('MFA_SETUP_REQUIRED'))}),
      );
      await fillLogin(tester);

      expect(find.textContaining('Aktifkan dulu di web'), findsOneWidget);
      expect(find.byType(LoginScreen), findsOneWidget);
    });

    testWidgets('ACCOUNT_LOCKED → pesan dengan sisa waktu', (tester) async {
      await pumpPakisPro(
        tester,
        adapter: server({
          '/auth/login': (_) => FakeReply(423, errorJson('ACCOUNT_LOCKED', details: {'retry_after_s': 540})),
        }),
      );
      await fillLogin(tester);

      expect(find.textContaining('Coba lagi dalam 9 menit.'), findsOneWidget);
    });

    testWidgets('APP_UPDATE_REQUIRED saat login → layar wajib update', (tester) async {
      await pumpPakisPro(
        tester,
        adapter: server({'/auth/login': (_) => FakeReply(426, errorJson('APP_UPDATE_REQUIRED'))}),
      );
      await fillLogin(tester);

      expect(find.byType(UpdateRequiredScreen), findsOneWidget);
    });

    testWidgets('DEVICE_PENDING → layar menunggu; Coba Lagi masih menunggu; setelah disetujui → beranda', (
      tester,
    ) async {
      var approved = false;
      final adapter = server({
        '/auth/login': (_) => approved ? FakeReply(200, loginJson()) : FakeReply(403, errorJson('DEVICE_PENDING')),
      });
      await pumpPakisPro(tester, adapter: adapter);
      await fillLogin(tester);

      expect(find.byType(DevicePendingScreen), findsOneWidget);
      expect(find.text('Menunggu persetujuan perangkat'), findsOneWidget);

      await tester.tap(find.text('Coba Lagi'));
      await tester.pumpAndSettle();
      expect(find.textContaining('masih menunggu persetujuan'), findsOneWidget);

      approved = true;
      // Pesan "masih menunggu" mendorong tombol ke bawah: gulir dulu seperti pengguna.
      await tester.ensureVisible(find.text('Coba Lagi'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Coba Lagi'));
      await tester.pumpAndSettle();
      expect(find.byType(HomeScreen), findsOneWidget);
      // Coba lagi memakai kredensial yang sama (dari memori, bukan penyimpanan).
      final logins = adapter.requests.where((o) => o.path.endsWith('/auth/login'));
      expect(logins.map((o) => bodyOf(o)['password']).toSet(), {'kopi pagi di gudang'});
    });
  });

  group('sesi tersimpan', () {
    MemorySecureStore signedInStore({bool mustChange = false}) => MemorySecureStore({
      StoreKeys.authToken: '12|tokenuji',
      StoreKeys.authUser: jsonEncode(userJson(mustChangePassword: mustChange)),
    });

    testWidgets('dibuka ulang dengan token → langsung beranda, data disegarkan dari /me', (tester) async {
      final adapter = server({
        '/me': (_) => FakeReply(200, {'data': userJson(name: 'Agus Santoso Baru'), 'meta': <String, Object?>{}}),
      });
      final hooks = FakeSessionHooks();
      await pumpPakisPro(tester, adapter: adapter, store: signedInStore(), hooks: hooks);

      expect(find.byType(HomeScreen), findsOneWidget);
      expect(find.text('Agus Santoso Baru'), findsOneWidget);
      final me = adapter.requests.firstWhere((o) => o.path.endsWith('/me'));
      expect(me.headers['Authorization'], 'Bearer 12|tokenuji');
      expect(hooks.calls, ['signedIn']);
    });

    testWidgets('server tidak terjangkau → tetap beranda (absen offline butuh sesi)', (tester) async {
      await pumpPakisPro(
        tester,
        adapter: FakeAdapter(
          (o) => o.path.endsWith('/config') ? FakeReply(200, configJson()) : const FakeReply.offline(),
        ),
        store: signedInStore(),
      );

      expect(find.byType(HomeScreen), findsOneWidget);
    });

    testWidgets('token ditolak (401) → keluar, token dihapus, pesan sesi berakhir', (tester) async {
      final store = signedInStore();
      final hooks = FakeSessionHooks();
      await pumpPakisPro(
        tester,
        adapter: server({'/me': (_) => FakeReply(401, errorJson('UNAUTHENTICATED'))}),
        store: store,
        hooks: hooks,
      );

      expect(find.byType(LoginScreen), findsOneWidget);
      expect(find.text('Sesi Anda sudah berakhir. Silakan masuk lagi.'), findsOneWidget);
      expect(store.values.containsKey(StoreKeys.authToken), isFalse);
      expect(store.values.containsKey(StoreKeys.authUser), isFalse);
      expect(hooks.calls, ['signedIn', 'signedOut']);
    });

    testWidgets('DEVICE_MISMATCH → keluar dengan pesan HP', (tester) async {
      await pumpPakisPro(
        tester,
        adapter: server({'/me': (_) => FakeReply(401, errorJson('DEVICE_MISMATCH'))}),
        store: signedInStore(),
      );

      expect(find.byType(LoginScreen), findsOneWidget);
      expect(find.textContaining('tidak berlaku di HP ini'), findsOneWidget);
    });

    testWidgets('Keluar → konfirmasi → /auth/logout, token dihapus, kembali ke masuk', (tester) async {
      final store = signedInStore();
      final hooks = FakeSessionHooks();
      final adapter = server({
        '/me': (_) => FakeReply(200, {'data': userJson(), 'meta': <String, Object?>{}}),
        '/auth/logout': (_) => const FakeReply(200, {'data': <String, Object?>{}, 'meta': <String, Object?>{}}),
      });
      await pumpPakisPro(tester, adapter: adapter, store: store, hooks: hooks);

      await tester.tap(find.byTooltip('Keluar'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Keluar'));
      await tester.pumpAndSettle();

      expect(find.byType(LoginScreen), findsOneWidget);
      expect(adapter.requests.where((o) => o.path.endsWith('/auth/logout')), hasLength(1));
      expect(store.values.containsKey(StoreKeys.authToken), isFalse);
      expect(hooks.calls.last, 'signedOut');
    });
  });

  group('SEC-006 wajib ganti password', () {
    testWidgets('login must_change_password → layar ganti password, tidak bisa keluar, sukses → beranda', (
      tester,
    ) async {
      final adapter = server({
        '/auth/login': (_) => FakeReply(200, loginJson(mustChangePassword: true)),
        '/me/password': (_) => FakeReply(200, {'data': userJson(), 'meta': <String, Object?>{}}),
      });
      await pumpPakisPro(tester, adapter: adapter);
      await fillLogin(tester);

      expect(find.byType(ChangePasswordScreen), findsOneWidget);

      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.byType(ChangePasswordScreen), findsOneWidget);

      // Isian tidak sama ditolak di HP.
      await tester.enterText(find.widgetWithText(TextField, 'Password saat ini'), 'kopi pagi di gudang');
      await tester.enterText(find.widgetWithText(TextField, 'Password baru'), 'teh sore di kantor');
      await tester.enterText(find.widgetWithText(TextField, 'Ulangi password baru'), 'teh sore di kantorX');
      await tester.tap(find.widgetWithText(FilledButton, 'Simpan Password'));
      await tester.pumpAndSettle();
      expect(find.text('Ulangi password baru belum sama.'), findsOneWidget);

      await tester.enterText(find.widgetWithText(TextField, 'Ulangi password baru'), 'teh sore di kantor');
      await tester.tap(find.widgetWithText(FilledButton, 'Simpan Password'));
      await tester.pumpAndSettle();

      expect(find.byType(HomeScreen), findsOneWidget);
      final put = adapter.requests.firstWhere((o) => o.path.endsWith('/me/password'));
      expect(bodyOf(put), {
        'current_password': 'kopi pagi di gudang',
        'password': 'teh sore di kantor',
        'password_confirmation': 'teh sore di kantor',
      });
    });

    testWidgets('galat field dari server tampil di bawah isian', (tester) async {
      await pumpPakisPro(
        tester,
        adapter: server({
          '/auth/login': (_) => FakeReply(200, loginJson(mustChangePassword: true)),
          '/me/password': (_) => FakeReply(
            422,
            errorJson(
              'VALIDATION_FAILED',
              details: {
                'current_password': ['Password saat ini salah.'],
              },
            ),
          ),
        }),
      );
      await fillLogin(tester);
      await tester.enterText(find.widgetWithText(TextField, 'Password saat ini'), 'salah sekali');
      await tester.enterText(find.widgetWithText(TextField, 'Password baru'), 'teh sore di kantor');
      await tester.enterText(find.widgetWithText(TextField, 'Ulangi password baru'), 'teh sore di kantor');
      await tester.tap(find.widgetWithText(FilledButton, 'Simpan Password'));
      await tester.pumpAndSettle();

      expect(find.text('Password saat ini salah.'), findsOneWidget);
      expect(find.byType(ChangePasswordScreen), findsOneWidget);
    });
  });

  group('FR-005 / FR-006 lupa password', () {
    testWidgets('kanal email → kartu cek email', (tester) async {
      final adapter = server({
        '/auth/password/forgot': (_) =>
            const FakeReply(200, {'data': <String, Object?>{}, 'meta': <String, Object?>{}}),
      });
      await pumpPakisPro(tester, adapter: adapter);

      await tester.tap(find.text('Lupa password?'));
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextField, 'Username atau email'), 'agus.s');
      await tester.tap(find.widgetWithText(FilledButton, 'Kirim Tautan'));
      await tester.pumpAndSettle();

      expect(find.text('Cek email Anda'), findsOneWidget);
      final request = adapter.requests.firstWhere((o) => o.path.endsWith('/auth/password/forgot'));
      expect(bodyOf(request), {'login': 'agus.s', 'channel': 'email'});
    });

    testWidgets('kanal WhatsApp → kode OTP + password baru → kembali ke masuk dengan pesan', (tester) async {
      final adapter = server({
        '/auth/password/forgot': (_) =>
            const FakeReply(200, {'data': <String, Object?>{}, 'meta': <String, Object?>{}}),
        '/auth/password/reset-otp': (_) =>
            const FakeReply(200, {'data': <String, Object?>{}, 'meta': <String, Object?>{}}),
      }, config: configJson(forgotChannels: ['email', 'whatsapp']));
      await pumpPakisPro(tester, adapter: adapter);

      await tester.tap(find.text('Lupa password?'));
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextField, 'Username atau email'), 'agus.s');
      await tester.tap(find.text('WhatsApp'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Kirim Kode'));
      await tester.pumpAndSettle();

      expect(find.byType(ResetOtpScreen), findsOneWidget);
      await tester.enterText(find.widgetWithText(TextField, 'Kode'), '482913');
      await tester.enterText(find.widgetWithText(TextField, 'Password baru'), 'teh sore di kantor');
      await tester.enterText(find.widgetWithText(TextField, 'Ulangi password baru'), 'teh sore di kantor');
      await tester.tap(find.widgetWithText(FilledButton, 'Simpan Password Baru'));
      await tester.pumpAndSettle();

      expect(find.byType(LoginScreen), findsOneWidget);
      expect(find.text('Password baru tersimpan. Silakan masuk.'), findsOneWidget);
      final reset = adapter.requests.firstWhere((o) => o.path.endsWith('/reset-otp'));
      expect(bodyOf(reset), {
        'login': 'agus.s',
        'code': '482913',
        'password': 'teh sore di kantor',
        'password_confirmation': 'teh sore di kantor',
      });
    });
  });
}
