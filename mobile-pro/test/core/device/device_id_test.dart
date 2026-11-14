import 'package:flutter_test/flutter_test.dart';
import 'package:pakis_pro/core/device/device_id.dart';
import 'package:pakis_pro/core/storage/secure_store.dart';

import '../../helpers/fakes.dart';

/// Kriteria T-030: `device_uid` = SHA-256(ANDROID_ID + install id), stabil antar restart.
void main() {
  late MemorySecureStore store;
  late FakeNativeDevice native;
  var generated = 0;

  DeviceId boot() => DeviceId(store: store, native: native, newInstallId: () => 'install-${++generated}');

  setUp(() {
    store = MemorySecureStore();
    native = FakeNativeDevice(androidIdValue: 'a1b2c3d4e5f60718');
    generated = 0;
  });

  test('rumus: hash dari ANDROID_ID lalu install id', () async {
    final uid = await boot().uid();

    expect(native.hashInputs, ['a1b2c3d4e5f60718install-1']);
    expect(uid, FakeNativeDevice.fakeHash('a1b2c3d4e5f60718install-1'));
    expect(uid, matches(RegExp(r'^[0-9a-f]{64}$')));
  });

  test('stabil antar restart: proses baru dengan penyimpanan yang sama → uid sama', () async {
    final first = await boot().uid();
    final afterRestart = await boot().uid(); // instance baru = aplikasi dibuka ulang

    expect(afterRestart, first);
    expect(generated, 1, reason: 'install id dibuat sekali saja');
    expect(store.values[StoreKeys.installId], 'install-1');
  });

  test('pasang ulang (penyimpanan kosong) → uid berbeda walau HP sama', () async {
    final before = await boot().uid();
    store.values.clear();
    final after = await boot().uid();

    expect(after, isNot(before));
  });

  test('HP lain dengan install id sama (mis. data disalin) → uid berbeda', () async {
    final phoneA = await boot().uid();
    native.androidIdValue = 'ffffeeee00001111';
    final phoneB = await boot().uid();

    expect(phoneB, isNot(phoneA));
  });

  test('di-cache per proses: kanal native dipanggil sekali walau dipakai di banyak request', () async {
    final id = boot();
    await Future.wait([id.uid(), id.uid(), id.uid()]);

    expect(native.hashInputs, hasLength(1));
  });

  test('ANDROID_ID tidak tersedia → tetap unik per pemasangan', () async {
    native.androidIdValue = null;

    await boot().uid();
    expect(native.hashInputs.single, 'install-1');
  });

  test('hasil native tidak sah ditolak, lalu dicoba lagi di panggilan berikutnya', () async {
    final broken = _BrokenNative();
    final id = DeviceId(store: store, native: broken, newInstallId: () => 'x');

    await expectLater(id.uid(), throwsStateError);
    broken.valid = true;
    expect(await id.uid(), 'a' * 64);
  });
}

class _BrokenNative extends FakeNativeDevice {
  bool valid = false;

  @override
  Future<String> sha256Hex(String input) async => valid ? 'a' * 64 : 'BUKAN-HEX';
}
