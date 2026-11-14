import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';
import 'package:pakis_pro/core/location/location_service.dart';

class FakeGeo implements GeoPlatform {
  bool enabled = true;
  LocationPermission permission = LocationPermission.whileInUse;
  LocationPermission afterRequest = LocationPermission.whileInUse;
  Object? error;
  double accuracy = 12;
  bool mocked = false;
  Duration? askedTimeLimit;
  var requested = 0;

  @override
  Future<bool> serviceEnabled() async => enabled;

  @override
  Future<LocationPermission> checkPermission() async => permission;

  @override
  Future<LocationPermission> requestPermission() async {
    requested++;
    return afterRequest;
  }

  @override
  Future<Position> currentPosition(Duration timeLimit) async {
    askedTimeLimit = timeLimit;
    if (error != null) {
      throw error!;
    }
    return Position(
      latitude: -6.7891,
      longitude: 110.8412,
      timestamp: DateTime(2026, 11, 3, 7, 40),
      accuracy: accuracy,
      altitude: 0,
      altitudeAccuracy: 0,
      heading: 0,
      headingAccuracy: 0,
      speed: 0,
      speedAccuracy: 0,
      isMocked: mocked,
    );
  }

  @override
  Future<bool> openAppSettings() async => true;

  @override
  Future<bool> openLocationSettings() async => true;
}

void main() {
  late FakeGeo geo;
  late LocationService service;

  setUp(() {
    geo = FakeGeo();
    service = LocationService(geo);
  });

  Future<LocationProblem> problem() async {
    try {
      await service.acquire(maxAccuracyM: 100);
    } on LocationProblem catch (e) {
      return e;
    }
    fail('seharusnya gagal');
  }

  test('berhasil: koordinat & akurasi, batas tunggu 15 detik (07 §5)', () async {
    final fix = await service.acquire(maxAccuracyM: 100);

    expect(fix.latitude, -6.7891);
    expect(fix.accuracyM, 12);
    expect(fix.isMock, isFalse);
    expect(geo.askedTimeLimit, const Duration(seconds: 15));
  });

  test('lokasi palsu tidak ditolak di HP: is_mock diteruskan ke server (BR-065)', () async {
    geo.mocked = true;
    expect((await service.acquire(maxAccuracyM: 100)).isMock, isTrue);
  });

  test('akurasi buruk → arahan pindah ke tempat terbuka, batas dari config', () async {
    geo.accuracy = 1250;
    final p = await problem();

    expect(p.kind, LocationProblemKind.lowAccuracy);
    expect(p.message, 'Akurasi GPS 1.250 m, batasnya 100 m. Pindah ke tempat terbuka lalu coba lagi.');
    expect((await service.acquire(maxAccuracyM: 2000)).accuracyM, 1250);
  });

  test('GPS tidak dapat sinyal dalam batas waktu', () async {
    geo.error = TimeoutException('gps');
    expect((await problem()).kind, LocationProblemKind.timeout);
  });

  test('GPS mati → arahan + tombol pengaturan', () async {
    geo.enabled = false;
    final p = await problem();
    expect(p.kind, LocationProblemKind.serviceDisabled);
    expect(p.opensSettings, isTrue);
  });

  test('izin: diminta sekali bila belum; ditolak / ditolak permanen', () async {
    geo
      ..permission = LocationPermission.denied
      ..afterRequest = LocationPermission.whileInUse;
    await service.acquire(maxAccuracyM: 100);
    expect(geo.requested, 1);

    geo.afterRequest = LocationPermission.denied;
    expect((await problem()).kind, LocationProblemKind.denied);

    geo.permission = LocationPermission.deniedForever;
    final p = await problem();
    expect(p.kind, LocationProblemKind.deniedForever);
    expect(p.opensSettings, isTrue);
  });
}
