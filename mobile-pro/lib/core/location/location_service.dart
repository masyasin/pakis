import 'dart:async';

import 'package:geolocator/geolocator.dart';
import 'package:intl/intl.dart';

/// Posisi untuk satu absen (05 §5.4: `latitude`, `longitude`, `accuracy_m`, `is_mock`).
class LocationFix {
  const LocationFix({required this.latitude, required this.longitude, required this.accuracyM, required this.isMock});

  final double latitude;
  final double longitude;
  final double accuracyM;

  /// `Position.isMocked` (aplikasi fake GPS). **Tidak** ditolak di HP: tetap dikirim agar
  /// server menolak, mencatat `attendance_mock_detected`, dan memberi tahu HRD (BR-065).
  final bool isMock;
}

enum LocationProblemKind { serviceDisabled, denied, deniedForever, timeout, lowAccuracy }

/// Kegagalan mengambil lokasi, dengan arahan Bahasa Indonesia untuk pengguna.
class LocationProblem implements Exception {
  const LocationProblem(this.kind, {this.accuracyM, this.maxAccuracyM});

  final LocationProblemKind kind;
  final double? accuracyM;
  final int? maxAccuracyM;

  /// Tombol yang membantu: buka pengaturan lokasi / izin aplikasi.
  bool get opensSettings => kind == LocationProblemKind.serviceDisabled || kind == LocationProblemKind.deniedForever;

  String get message => switch (kind) {
    LocationProblemKind.serviceDisabled => 'GPS (Lokasi) di HP mati. Aktifkan lalu coba lagi.',
    LocationProblemKind.denied => 'Pakis Pro perlu izin lokasi untuk absen. Pilih "Izinkan" saat diminta.',
    LocationProblemKind.deniedForever =>
      'Izin lokasi ditolak permanen. Buka Pengaturan → Aplikasi → Pakis Pro → Izin → Lokasi, lalu izinkan.',
    LocationProblemKind.timeout => 'GPS belum mendapat sinyal. Pindah ke tempat terbuka lalu coba lagi.',
    LocationProblemKind.lowAccuracy =>
      'Akurasi GPS ${_m(accuracyM)} m, batasnya ${_m(maxAccuracyM)} m. Pindah ke tempat terbuka lalu coba lagi.',
  };

  static String _m(num? v) => v == null ? '-' : NumberFormat.decimalPattern('id_ID').format(v.round());

  @override
  String toString() => 'LocationProblem(${kind.name})';
}

/// Jembatan ke `geolocator`, agar [LocationService] bisa diuji tanpa kanal platform.
abstract interface class GeoPlatform {
  Future<bool> serviceEnabled();
  Future<LocationPermission> checkPermission();
  Future<LocationPermission> requestPermission();
  Future<Position> currentPosition(Duration timeLimit);
  Future<bool> openAppSettings();
  Future<bool> openLocationSettings();
}

class GeolocatorPlatformAdapter implements GeoPlatform {
  const GeolocatorPlatformAdapter();

  @override
  Future<bool> serviceEnabled() => Geolocator.isLocationServiceEnabled();

  @override
  Future<LocationPermission> checkPermission() => Geolocator.checkPermission();

  @override
  Future<LocationPermission> requestPermission() => Geolocator.requestPermission();

  /// Posisi segar (bukan cache lama) dengan akurasi tertinggi; lokasi hanya diambil saat
  /// tombol absen ditekan, tidak pernah di latar belakang (09 §8 PDP).
  @override
  Future<Position> currentPosition(Duration timeLimit) => Geolocator.getCurrentPosition(
    locationSettings: AndroidSettings(
      accuracy: LocationAccuracy.best,
      timeLimit: timeLimit,
      maxUpdateAge: Duration.zero,
    ),
  );

  @override
  Future<bool> openAppSettings() => Geolocator.openAppSettings();

  @override
  Future<bool> openLocationSettings() => Geolocator.openLocationSettings();
}

/// Mengambil lokasi untuk absen (07 §5 "cek GPS (spinner ≤ 15 s, kalau akurasi buruk: 'Pindah
/// ke tempat terbuka')", SEC-023).
class LocationService {
  const LocationService(this._platform, {this.timeLimit = const Duration(seconds: 15)});

  final GeoPlatform _platform;
  final Duration timeLimit;

  /// [maxAccuracyM] dari config (`attendance.max_accuracy_m`), bukan konstanta: server
  /// menolak akurasi di atas batas yang sama (BR-064), jadi HP memberi arahan lebih awal.
  Future<LocationFix> acquire({required int maxAccuracyM}) async {
    if (!await _platform.serviceEnabled()) {
      throw const LocationProblem(LocationProblemKind.serviceDisabled);
    }

    var permission = await _platform.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await _platform.requestPermission();
    }
    switch (permission) {
      case LocationPermission.denied:
        throw const LocationProblem(LocationProblemKind.denied);
      case LocationPermission.deniedForever:
        throw const LocationProblem(LocationProblemKind.deniedForever);
      case LocationPermission.whileInUse:
      case LocationPermission.always:
      case LocationPermission.unableToDetermine:
        break;
    }

    final Position position;
    try {
      position = await _platform.currentPosition(timeLimit);
    } on TimeoutException {
      throw const LocationProblem(LocationProblemKind.timeout);
    } on LocationServiceDisabledException {
      throw const LocationProblem(LocationProblemKind.serviceDisabled);
    } on PermissionDeniedException {
      throw const LocationProblem(LocationProblemKind.denied);
    }

    if (position.accuracy > maxAccuracyM) {
      throw LocationProblem(LocationProblemKind.lowAccuracy, accuracyM: position.accuracy, maxAccuracyM: maxAccuracyM);
    }

    return LocationFix(
      latitude: position.latitude,
      longitude: position.longitude,
      accuracyM: position.accuracy,
      isMock: position.isMocked,
    );
  }

  Future<bool> openSettings(LocationProblem problem) => problem.kind == LocationProblemKind.serviceDisabled
      ? _platform.openLocationSettings()
      : _platform.openAppSettings();
}
