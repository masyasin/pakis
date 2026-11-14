import 'dart:async';
import 'dart:math';

import 'package:dio/dio.dart';
import 'package:material_ui/material_ui.dart';

import '../api/api_client.dart';
import '../api/api_error.dart';
import 'queue_db.dart';

/// Mengirim satu absen ke `POST /api/v1/attendance` (multipart, 05 §5.4).
///
/// Kontrak sisi server dibuat di T-036; bentuk field mengikuti 05 §5.4 apa adanya.
class AttendanceUploader {
  const AttendanceUploader(this._api);

  static const path = '/api/v1/attendance';

  final ApiClient _api;

  /// 201 = baru, 200 + `meta.replayed` = sudah pernah diterima. Keduanya berarti selesai.
  Future<ApiResponse> send(PendingAttendance item, {required bool offline}) => _api.post(
    path,
    data: FormData.fromMap({
      'client_uuid': item.clientUuid,
      'type': item.type,
      'client_time': item.clientTime,
      'is_offline': offline ? '1' : '0',
      'latitude': '${item.latitude}',
      'longitude': '${item.longitude}',
      'accuracy_m': '${item.accuracyM}',
      'is_mock': item.isMock ? '1' : '0',
      if (item.deviceClockOffsetS != null) 'device_clock_offset_s': '${item.deviceClockOffsetS}',
      if (item.selfie != null)
        'selfie': MultipartFile.fromBytes(
          item.selfie!,
          filename: 'selfie.jpg',
          contentType: DioMediaType('image', 'jpeg'),
        ),
    }),
  );
}

/// Ringkasan satu putaran sinkron.
class SyncResult {
  const SyncResult({this.sent = 0, this.rejected = 0, this.remaining = 0, this.stoppedBy});

  final int sent;
  final int rejected;
  final int remaining;

  /// Galat yang butuh tindakan pengguna (masuk ulang, perbarui aplikasi): sinkron berhenti,
  /// antrean tetap utuh.
  final ApiError? stoppedBy;
}

/// Sinkron antrean offline (03 §4.5, NFR-007, SEC-024).
///
/// - Berurutan (FIFO), satu putaran pada satu waktu.
/// - Idempoten: setiap baris membawa `client_uuid`; kiriman ulang dijawab server 200
///   `replayed` dan dianggap sukses, jadi jaringan putus setelah server menerima tidak
///   menggandakan absen (MOB-07).
/// - Galat sementara (jaringan, 5xx, 429) → backoff eksponensial per baris.
/// - Galat permanen (validasi/aturan absen) → baris ditandai `rejected`, tidak dicoba lagi.
/// - Pemicu: aplikasi dibuka, koneksi kembali, tombol "Sinkronkan" (`force`: abaikan backoff),
///   dan timer berkala ≤ 1 menit selama aplikasi terbuka (NFR-007).
class SyncService {
  SyncService({
    required this._queue,
    required this._uploader,
    this._now = DateTime.now,
    this.baseBackoff = const Duration(seconds: 30),
    this.maxBackoff = const Duration(minutes: 15),
  }) {
    _refreshCount();
  }

  final QueueDb _queue;
  final AttendanceUploader _uploader;
  final DateTime Function() _now;
  final Duration baseBackoff;
  final Duration maxBackoff;

  /// Jumlah absen menunggu kiriman, untuk indikator "2 absen menunggu sinyal" (07 §5).
  final pending = ValueNotifier<int>(0);

  /// Jumlah absen yang ditolak server dan perlu diajukan koreksi.
  final rejected = ValueNotifier<int>(0);

  Future<SyncResult>? _running;
  StreamSubscription<bool>? _connectivity;
  Timer? _timer;

  /// Galat yang tidak akan berubah bila dikirim ulang.
  static const _transient = {
    ApiErrorCode.networkOffline,
    ApiErrorCode.timeout,
    ApiErrorCode.serverError,
    ApiErrorCode.serviceUnavailable,
    ApiErrorCode.rateLimited,
    ApiErrorCode.unknown,
  };

  /// Butuh tindakan pengguna di luar antrean: hentikan putaran, jangan tandai baris.
  static const _blocking = {
    ApiErrorCode.unauthenticated,
    ApiErrorCode.deviceMismatch,
    ApiErrorCode.devicePending,
    ApiErrorCode.appUpdateRequired,
    ApiErrorCode.passwordChangeRequired,
  };

  /// Simpan absen yang gagal dikirim langsung (07 §5: "Tersimpan di HP, akan dikirim otomatis").
  void enqueue(PendingAttendance item) {
    _queue.enqueue(item);
    _refreshCount();
  }

  /// Satu putaran sinkron. Panggilan saat putaran lain berjalan mendapat hasil putaran itu.
  Future<SyncResult> sync({bool force = false}) => _running ??= _run(force).whenComplete(() => _running = null);

  Future<SyncResult> _run(bool force) async {
    var sent = 0;
    var rejectedNow = 0;
    ApiError? stoppedBy;

    // Semua baris pending, FIFO. Berhenti di baris pertama yang masih backoff: absen pulang
    // tidak boleh mendahului absen masuk yang belum terkirim. `force` (koneksi baru kembali /
    // tombol ditekan) mengabaikan jadwal backoff.
    final now = _now();
    for (final item in _queue.due(DateTime(9999))) {
      if (!force && item.nextAttemptAt != null && item.nextAttemptAt!.isAfter(now)) {
        break;
      }
      try {
        await _uploader.send(item, offline: true);
        _queue.remove(item.clientUuid);
        sent++;
      } on ApiError catch (e) {
        if (_blocking.contains(e.code)) {
          stoppedBy = e;
          break;
        }
        if (_transient.contains(e.code)) {
          final attempts = item.attempts + 1;
          _queue.scheduleRetry(
            item.clientUuid,
            attempts: attempts,
            nextAttemptAt: _now().add(backoffFor(attempts)),
            errorCode: e.code.wire,
          );
          // Server/jaringan sedang bermasalah: baris berikutnya pasti gagal juga, dan urutan
          // masuk → pulang harus dijaga. Coba lagi putaran berikutnya.
          break;
        }
        _queue.reject(item.clientUuid, e.code.wire);
        rejectedNow++;
      }
    }

    _refreshCount();
    return SyncResult(sent: sent, rejected: rejectedNow, remaining: pending.value, stoppedBy: stoppedBy);
  }

  /// 30 s, 60 s, 120 s, … maksimal [maxBackoff]. Jitter ±10% agar banyak HP tidak serentak
  /// menyerbu server setelah gangguan.
  Duration backoffFor(int attempts, [Random? random]) {
    final exp = baseBackoff * pow(2, min(attempts - 1, 20)).toInt();
    final capped = exp > maxBackoff ? maxBackoff : exp;
    final jitter = 0.9 + (random ?? Random()).nextDouble() * 0.2;
    return capped * jitter;
  }

  /// Mulai pemicu otomatis. [online] = true setiap kali koneksi tersedia lagi.
  void start({required Stream<bool> online, Duration interval = const Duration(minutes: 1)}) {
    stop();
    unawaited(sync(force: true)); // aplikasi dibuka
    _connectivity = online.where((isOnline) => isOnline).listen((_) => unawaited(sync(force: true)));
    _timer = Timer.periodic(interval, (_) {
      if (pending.value > 0) {
        unawaited(sync());
      }
    });
  }

  void stop() {
    unawaited(_connectivity?.cancel());
    _connectivity = null;
    _timer?.cancel();
    _timer = null;
  }

  void dispose() {
    stop();
    pending.dispose();
    rejected.dispose();
  }

  void _refreshCount() {
    pending.value = _queue.count();
    rejected.value = _queue.count(status: QueueStatus.rejected);
  }
}
