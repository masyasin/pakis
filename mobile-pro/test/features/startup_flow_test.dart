import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:material_ui/material_ui.dart';
import 'package:pakis_pro/app/router.dart';
import 'package:pakis_pro/core/api/api_error.dart';
import 'package:pakis_pro/core/config/app_config.dart';
import 'package:pakis_pro/core/providers.dart';
import 'package:pakis_pro/core/storage/secure_store.dart';
import 'package:pakis_pro/features/auth/login_screen.dart';
import 'package:pakis_pro/features/startup/startup_controller.dart';
import 'package:pakis_pro/features/startup/update_required_screen.dart';

import '../helpers/app_harness.dart';
import '../helpers/fakes.dart';

void main() {
  group('MOB-08 / BR-027: versi < min_version → layar wajib update', () {
    testWidgets('tampil layar update, tombol unduh membuka download_url', (tester) async {
      final native = FakeNativeDevice();
      final adapter = FakeAdapter((_) => FakeReply(200, configJson(minVersion: '1.2.0')));

      await pumpPakisPro(tester, adapter: adapter, native: native, version: '1.1.9');

      expect(find.byType(UpdateRequiredScreen), findsOneWidget);
      expect(find.text('Perbarui aplikasi'), findsOneWidget);
      expect(find.text('Versi baru tersedia.'), findsOneWidget);
      expect(find.text('Versi terpasang 1.1.9 · minimal 1.2.0'), findsOneWidget);

      await tester.tap(find.text('Unduh Versi Terbaru'));
      await tester.pump();
      expect(native.openedUrls, ['https://pakis.example/unduh/pakis-pro.apk']);
    });

    testWidgets('tidak bisa lanjut: Back diblokir dan navigasi paksa dikembalikan', (tester) async {
      final adapter = FakeAdapter((_) => FakeReply(200, configJson(minVersion: '2.0.0')));
      final container = await pumpPakisPro(tester, adapter: adapter, version: '1.0.0');

      // Tombol Back sistem.
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.byType(UpdateRequiredScreen), findsOneWidget);

      // Navigasi langsung ke rute lain (mis. deep link) dikembalikan oleh redirect.
      container.read(routerProvider).go(Routes.login);
      await tester.pumpAndSettle();
      expect(find.byType(UpdateRequiredScreen), findsOneWidget);
      expect(find.byType(LoginScreen), findsNothing);
    });

    testWidgets('offline dengan config tersimpan ber-min tinggi tetap terkunci', (tester) async {
      final store = MemorySecureStore({StoreKeys.lastConfig: jsonEncode(configJson(minVersion: '1.5.0')['data'])});
      final adapter = FakeAdapter((_) => const FakeReply.offline());

      await pumpPakisPro(tester, adapter: adapter, store: store, version: '1.0.0');

      expect(find.byType(UpdateRequiredScreen), findsOneWidget);
    });

    testWidgets('426 dari endpoint lain setelah lolos splash → pindah ke layar update', (tester) async {
      final adapter = FakeAdapter((_) => FakeReply(200, configJson(minVersion: '1.0.0')));
      final container = await pumpPakisPro(tester, adapter: adapter, version: '1.0.0');
      expect(find.byType(LoginScreen), findsOneWidget);

      adapter.handler = (_) =>
          FakeReply(426, errorJson('APP_UPDATE_REQUIRED', details: {'download_url': 'https://pakis.example/baru.apk'}));
      // Request dijalankan di zona uji (tidak di-await langsung) lalu frame dipompa sampai selesai.
      ApiError? thrown;
      unawaited(
        container
            .read(apiClientProvider)
            .get('/api/v1/me')
            .then<void>((_) {}, onError: (Object e) => thrown = e as ApiError),
      );
      await tester.pumpAndSettle();

      expect(thrown?.code, ApiErrorCode.appUpdateRequired);

      expect(find.byType(UpdateRequiredScreen), findsOneWidget);
    });

    testWidgets('download_url belum diatur → tombol nonaktif + arahan hubungi admin', (tester) async {
      final json = configJson(minVersion: '9.0.0');
      final data = json['data']! as Map<String, Object?>;
      data['mobile'] = <String, Object?>{...(data['mobile']! as Map<String, Object?>), 'download_url': null};
      await pumpPakisPro(tester, adapter: FakeAdapter((_) => FakeReply(200, json)), version: '1.0.0');

      final button = tester.widget<ButtonStyleButton>(
        find.ancestor(
          of: find.text('Unduh Versi Terbaru'),
          matching: find.byWidgetPredicate((w) => w is ButtonStyleButton),
        ),
      );
      expect(button.onPressed, isNull);
      expect(find.textContaining('Hubungi admin'), findsOneWidget);
    });
  });

  testWidgets('versi memenuhi → lanjut ke rute masuk (layar login = T-032)', (tester) async {
    final adapter = FakeAdapter((_) => FakeReply(200, configJson(minVersion: '1.0.0')));

    await pumpPakisPro(tester, adapter: adapter, version: '1.0.0');

    expect(find.byType(LoginScreen), findsOneWidget);
    expect(find.textContaining('Memakai pengaturan terakhir'), findsNothing);
  });

  testWidgets('offline dengan config tersimpan → lanjut + banner offline', (tester) async {
    final store = MemorySecureStore({StoreKeys.lastConfig: jsonEncode(configJson()['data'])});
    await pumpPakisPro(tester, adapter: FakeAdapter((_) => const FakeReply.offline()), store: store);

    expect(find.byType(LoginScreen), findsOneWidget);
    expect(find.textContaining('Memakai pengaturan terakhir'), findsOneWidget);
  });

  testWidgets('offline tanpa config tersimpan → pesan koneksi + Coba Lagi berhasil', (tester) async {
    final adapter = FakeAdapter((_) => const FakeReply.offline());
    await pumpPakisPro(tester, adapter: adapter);

    expect(find.text('Tidak ada koneksi ke server. Periksa data seluler atau Wi-Fi.'), findsOneWidget);

    adapter.handler = (_) => FakeReply(200, configJson());
    await tester.tap(find.text('Coba Lagi'));
    await tester.pumpAndSettle();
    expect(find.byType(LoginScreen), findsOneWidget);
  });

  testWidgets('request /config membawa X-Device-Id hasil DeviceId & X-App-Version', (tester) async {
    final adapter = FakeAdapter((_) => FakeReply(200, configJson()));
    final native = FakeNativeDevice(androidIdValue: 'abc');
    final store = MemorySecureStore({StoreKeys.installId: 'pasang-1'});

    await pumpPakisPro(tester, adapter: adapter, native: native, store: store, version: '1.0.0');

    final headers = adapter.requests.first.headers;
    expect(headers['X-Device-Id'], FakeNativeDevice.fakeHash('abcpasang-1'));
    expect(headers['X-App-Version'], '1.0.0');
  });

  testWidgets('warna utama mengikuti branding.primary_color dari config', (tester) async {
    final adapter = FakeAdapter((_) => FakeReply(200, configJson(primaryColor: '#1F4E79')));
    await pumpPakisPro(tester, adapter: adapter);

    final context = tester.element(find.byType(LoginScreen));
    expect(Theme.of(context).colorScheme.primary, const Color(0xFF1F4E79));
  });

  group('requiredLocation (aturan gerbang murni)', () {
    test('tanpa state / gagal → splash', () {
      expect(requiredLocation(startup: null, updateForced: false, location: Routes.login), Routes.splash);
      expect(requiredLocation(startup: null, updateForced: false, location: Routes.splash), isNull);
    });

    test('426 memaksa layar update walau startup siap', () {
      final ready = StartupReady(_config(), fromCache: false);
      expect(requiredLocation(startup: ready, updateForced: true, location: Routes.login), Routes.update);
      expect(requiredLocation(startup: ready, updateForced: true, location: Routes.update), isNull);
    });

    test('siap → splash/update diarahkan ke masuk, rute lain bebas', () {
      final ready = StartupReady(_config(), fromCache: false);
      expect(requiredLocation(startup: ready, updateForced: false, location: Routes.splash), Routes.login);
      expect(requiredLocation(startup: ready, updateForced: false, location: '/riwayat'), isNull);
    });
  });
}

AppConfig _config() => AppConfig.fromJson((configJson()['data']! as Map).cast());
