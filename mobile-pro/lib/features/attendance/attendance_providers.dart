import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/api/api_error.dart';
import '../../core/offline/offline_providers.dart';
import '../../core/offline/sync_service.dart';
import '../../core/providers.dart';
import '../../core/storage/secure_store.dart';
import '../auth/session.dart';
import 'attendance_models.dart';
import 'attendance_recorder.dart';
import 'capture/selfie_capture_screen.dart';

/// Membuka kamera selfie dan mengembalikan JPEG ≤ 100 KB (null = batal). Di test diganti
/// agar alur presensi bisa diuji tanpa kamera.
typedef SelfieCapture = Future<Uint8List?> Function(BuildContext context);

final selfieCaptureProvider = Provider<SelfieCapture>((ref) => SelfieCaptureScreen.open);

/// Sinkron antrean, atau null bila antrean tidak bisa dibuka (presensi tetap bisa online).
final optionalSyncServiceProvider = FutureProvider<SyncService?>((ref) async {
  try {
    return await ref.watch(syncServiceProvider.future);
  } on Object {
    return null;
  }
});

final attendanceRecorderProvider = FutureProvider<AttendanceRecorder>(
  (ref) async => AttendanceRecorder(
    uploader: AttendanceUploader(ref.watch(apiClientProvider)),
    sync: await ref.watch(optionalSyncServiceProvider.future),
    clockOffset: () => ref.read(clockOffsetProvider),
  ),
);

/// Isi beranda: hari kerja dari server, atau salinan terakhir bila server tidak terjangkau.
class AttendanceHomeData {
  const AttendanceHomeData({this.today, this.fetchedAt, this.error, this.lastOutcome});

  final AttendanceToday? today;

  /// Kapan [today] diambil dari server. Null bila belum pernah.
  final DateTime? fetchedAt;

  /// Galat ambil terakhir (biasanya offline). [today] bisa tetap berisi salinan lama.
  final ApiError? error;

  /// Presensi terakhir dari HP ini di sesi ini, untuk indikator GPS (akurasi, jarak).
  final RecordOutcome? lastOutcome;

  bool get isStale => error != null;
}

/// Salinan `attendance/today` lebih tua dari ini tidak dipakai: hari kerja sudah berganti.
const _cacheMaxAge = Duration(hours: 12);

class AttendanceHomeController extends AsyncNotifier<AttendanceHomeData> {
  static const path = '/api/v1/attendance/today';

  @override
  Future<AttendanceHomeData> build() => _load();

  Future<void> refresh() async {
    final previous = state.value;
    final next = await _load();
    state = AsyncData(
      AttendanceHomeData(
        today: next.today,
        fetchedAt: next.fetchedAt,
        error: next.error,
        lastOutcome: previous?.lastOutcome,
      ),
    );
  }

  Future<AttendanceHomeData> _load() async {
    final store = ref.read(secureStoreProvider);
    final employeeId = _employeeId();
    try {
      final response = await ref.read(apiClientProvider).get(path);
      final today = AttendanceToday.fromJson(response.data);
      final now = DateTime.now();
      if (today != null) {
        unawaited(
          store
              .write(
                StoreKeys.attendanceToday,
                jsonEncode({'employee_id': employeeId, 'fetched_at': now.toIso8601String(), 'data': today.toJson()}),
              )
              .catchError((Object _) {}),
        );
      }
      return AttendanceHomeData(today: today, fetchedAt: now);
    } on ApiError catch (e) {
      final cached = await _cached(store, employeeId);
      return AttendanceHomeData(today: cached?.$1, fetchedAt: cached?.$2, error: e);
    }
  }

  Future<(AttendanceToday, DateTime)?> _cached(SecureStore store, int? employeeId) async {
    try {
      final raw = await store.read(StoreKeys.attendanceToday);
      if (raw == null) {
        return null;
      }
      final json = jsonDecode(raw);
      if (json is! Map || json['employee_id'] != employeeId) {
        return null;
      }
      final fetchedAt = DateTime.tryParse(json['fetched_at'] as String? ?? '');
      final today = AttendanceToday.fromJson(json['data']);
      if (fetchedAt == null || today == null || DateTime.now().difference(fetchedAt) > _cacheMaxAge) {
        return null;
      }
      return (today, fetchedAt);
    } on Object {
      return null;
    }
  }

  int? _employeeId() {
    final session = ref.read(sessionProvider).value;
    return session is SignedIn ? session.user.employee?.id : null;
  }

  /// Terapkan hasil presensi ke beranda tanpa menunggu server (penting saat offline):
  /// tombol berganti Masuk → Pulang → selesai.
  void apply(RecordOutcome outcome) {
    final data = state.value ?? const AttendanceHomeData();
    var today = data.today;
    if (outcome is RecordSent || outcome is RecordQueued) {
      final record = outcome is RecordSent ? outcome.record : null;
      final base =
          today ?? AttendanceToday(workDate: '', scheduleKind: AttendanceToday.unknownKind, nextAction: outcome.type);
      final previous = base.attendance;
      final attendance =
          record ??
          AttendanceRecord(
            workDate: previous?.workDate ?? base.workDate,
            status: previous?.status ?? '',
            lateMinutes: previous?.lateMinutes ?? 0,
            shift: previous?.shift ?? base.shift,
            checkInAt: outcome.type == 'in' ? outcome.clientTime : previous?.checkInAt,
            checkOutAt: outcome.type == 'out' ? outcome.clientTime : previous?.checkOutAt,
            locationName: previous?.locationName,
            distanceM: previous?.distanceM,
            isOffline: outcome is RecordQueued,
          );
      today = outcome.type == 'in'
          ? base.copyWith(attendance: attendance, nextAction: 'out')
          : base.copyWith(attendance: attendance, clearNextAction: true);
    }
    state = AsyncData(
      AttendanceHomeData(today: today, fetchedAt: data.fetchedAt, error: data.error, lastOutcome: outcome),
    );
  }
}

final attendanceHomeProvider = AsyncNotifierProvider<AttendanceHomeController, AttendanceHomeData>(
  AttendanceHomeController.new,
);
