import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:pakis_pro/core/api/api_client.dart';
import 'package:pakis_pro/core/api/api_error.dart';
import 'package:pakis_pro/core/config/app_config.dart';
import 'package:pakis_pro/core/config/config_repository.dart';
import 'package:pakis_pro/core/config/semver.dart';
import 'package:pakis_pro/core/storage/secure_store.dart';

import '../../helpers/fakes.dart';

void main() {
  group('Semver (cermin backend)', () {
    test('membandingkan per bagian secara numerik', () {
      expect(Semver.lessThan('1.0.9', '1.0.10'), isTrue);
      expect(Semver.lessThan('1.2.0', '1.10.0'), isTrue);
      expect(Semver.compare('2.0.0', '1.99.99'), 1);
      expect(Semver.compare('1.0.3', '1.0.3'), 0);
    });

    test('nilai tidak sah = 0.0.0 (sama dengan Semver.php)', () {
      expect(Semver.isValid('1.0'), isFalse);
      expect(Semver.isValid('01.0.0'), isFalse);
      expect(Semver.isValid('1.0.0-beta'), isFalse);
      expect(Semver.compare('rusak', '0.0.0'), 0);
      expect(Semver.lessThan('rusak', '1.0.0'), isTrue);
    });
  });

  group('AppConfig', () {
    test('membaca semua kunci ConfigController::MAP', () {
      final config = AppConfig.fromJson((configJson(minVersion: '1.2.0')['data']! as Map).cast());

      expect(config.appName, 'PAKIS ONE');
      expect(config.companyName, 'CV Pakis Jaya Abadi');
      expect(config.primaryColor, '#2F6B3B');
      expect(config.mobile.minVersion, '1.2.0');
      expect(config.mobile.downloadUrl, 'https://pakis.example/unduh/pakis-pro.apk');
      expect(config.attendance.correctionMaxDays, 7);
      expect(config.auth.passwordMinLength, 10);
      expect(config.auth.forgotChannels, ['email']);
    });

    test('versi < min_version → wajib update; = atau > min → tidak', () {
      final config = AppConfig.fromJson((configJson(minVersion: '1.2.0')['data']! as Map).cast());

      expect(config.requiresUpdate('1.1.9'), isTrue);
      expect(config.requiresUpdate('1.2.0'), isFalse);
      expect(config.requiresUpdate('1.10.0'), isFalse);
    });
  });

  group('ConfigRepository', () {
    late FakeAdapter adapter;
    late MemorySecureStore store;

    ConfigRepository repo() => ConfigRepository(
      api: ApiClient(
        baseUrl: 'https://pakis.test',
        appVersion: '1.0.0',
        deviceId: () async => 'a' * 64,
        adapter: adapter,
      ),
      store: store,
    );

    setUp(() {
      store = MemorySecureStore();
      adapter = FakeAdapter((_) => FakeReply(200, configJson(minVersion: '1.1.0')));
    });

    test('online → config baru disimpan sebagai config terakhir', () async {
      final loaded = await repo().load();

      expect(loaded.fromCache, isFalse);
      expect(adapter.requests.single.path, '/api/v1/config');
      expect(jsonDecode(store.values[StoreKeys.lastConfig]!), containsPair('app_name', 'PAKIS ONE'));
    });

    test('offline → pakai config terakhir (07 §5)', () async {
      await repo().load();
      adapter.handler = (_) => const FakeReply.offline();

      final loaded = await repo().load();
      expect(loaded.fromCache, isTrue);
      expect(loaded.config.mobile.minVersion, '1.1.0', reason: 'min_version tersimpan tetap berlaku offline');
    });

    test('server perawatan (503) → pakai config terakhir', () async {
      await repo().load();
      adapter.handler = (_) => FakeReply(503, errorJson('SERVICE_UNAVAILABLE'));

      expect((await repo().load()).fromCache, isTrue);
    });

    test('offline tanpa config tersimpan → galat diteruskan', () async {
      adapter.handler = (_) => const FakeReply.offline();

      await expectLater(
        repo().load(),
        throwsA(isA<ApiError>().having((e) => e.code, 'code', ApiErrorCode.networkOffline)),
      );
    });

    test('config tersimpan rusak diabaikan, bukan crash', () async {
      store.values[StoreKeys.lastConfig] = '{rusak';
      adapter.handler = (_) => const FakeReply.offline();

      await expectLater(repo().load(), throwsA(isA<ApiError>()));
    });
  });
}
