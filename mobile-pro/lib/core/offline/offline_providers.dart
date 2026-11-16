import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../location/location_service.dart';
import '../providers.dart';
import 'queue_db.dart';
import 'sync_service.dart';

/// Provider antrean & sinkron (T-042). Sinkron butuh token, jadi `SyncService.start()`
/// dipanggil `SyncSessionHooks` setelah masuk ke akun dan `stop()` saat keluar (T-032).

final queueDbProvider = FutureProvider<QueueDb>((ref) async {
  final dir = await ref.watch(nativeDeviceProvider).privateDir();
  final db = await QueueDb.open('$dir/attendance_queue.db', store: ref.watch(secureStoreProvider));
  ref.onDispose(db.close);
  return db;
});

final syncServiceProvider = FutureProvider<SyncService>((ref) async {
  final service = SyncService(
    queue: await ref.watch(queueDbProvider.future),
    uploader: AttendanceUploader(ref.watch(apiClientProvider)),
  );
  ref.onDispose(service.dispose);
  return service;
});

/// `true` setiap kali HP punya koneksi (Wi-Fi/data). Hanya pemicu: kiriman tetap bisa gagal
/// bila koneksi ada tetapi server tidak terjangkau, dan itu ditangani backoff.
final onlineStreamProvider = Provider<Stream<bool>>(
  (ref) => Connectivity().onConnectivityChanged.map((results) => results.any((r) => r != ConnectivityResult.none)),
);

final locationServiceProvider = Provider<LocationService>((ref) => const LocationService(GeolocatorPlatformAdapter()));
