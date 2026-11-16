import 'dart:typed_data';

import 'package:uuid/uuid.dart';

import '../../core/api/api_error.dart';
import '../../core/format.dart';
import '../../core/location/location_service.dart';
import '../../core/offline/queue_db.dart';
import '../../core/offline/sync_service.dart';
import 'attendance_models.dart';

/// Hasil satu presensi dari HP (07 §5 "hasil (berhasil/telat N menit/galat dengan arahan)").
sealed class RecordOutcome {
  const RecordOutcome(this.type, this.clientTime, {this.accuracyM});

  /// `in` / `out`.
  final String type;

  /// Waktu HP saat tombol ditekan (ISO-8601 + offset).
  final String clientTime;

  /// Akurasi GPS yang dikirim, untuk indikator lokasi di beranda.
  final double? accuracyM;
}

/// Diterima server. [record] null bila terkirim lewat antrean (respons tidak disimpan).
class RecordSent extends RecordOutcome {
  const RecordSent(super.type, super.clientTime, {super.accuracyM, this.record, this.replayed = false});

  final AttendanceRecord? record;

  /// `client_uuid` sudah pernah diterima (05 §5.4 `meta.replayed`).
  final bool replayed;
}

/// Server tidak terjangkau: tersimpan di antrean terenkripsi, dikirim otomatis nanti.
class RecordQueued extends RecordOutcome {
  const RecordQueued(super.type, super.clientTime, {super.accuracyM, this.cause});

  final ApiError? cause;
}

/// Ditolak server (aturan presensi) atau tidak bisa disimpan sama sekali.
class RecordRejected extends RecordOutcome {
  const RecordRejected(super.type, super.clientTime, this.error, {super.accuracyM});

  final ApiError error;
}

/// Mengirim presensi: online dulu, bila server tidak terjangkau → antrean (03 §4.5).
///
/// Bila antrean masih berisi presensi lama, presensi baru **ikut antre** (FIFO) dan antrean
/// dikirim saat itu juga: presensi pulang tidak boleh sampai di server sebelum presensi masuk
/// yang masih tertahan di HP.
class AttendanceRecorder {
  AttendanceRecorder({
    required this._uploader,
    required this._sync,
    required this._clockOffset,
    this._now = DateTime.now,
    Uuid? uuid,
  }) : _uuid = uuid ?? const Uuid();

  final AttendanceUploader _uploader;

  /// Null bila antrean tidak bisa dibuka (mis. SQLCipher gagal): presensi hanya bisa online.
  final SyncService? _sync;
  final int? Function() _clockOffset;
  final DateTime Function() _now;
  final Uuid _uuid;

  Future<RecordOutcome> record({required String type, required LocationFix fix, Uint8List? selfie}) async {
    final now = _now();
    final item = PendingAttendance(
      clientUuid: _uuid.v4(),
      type: type,
      clientTime: Fmt.isoWithOffset(now),
      latitude: fix.latitude,
      longitude: fix.longitude,
      accuracyM: fix.accuracyM,
      isMock: fix.isMock,
      selfie: selfie,
      deviceClockOffsetS: _clockOffset(),
      createdAt: now,
    );
    final sync = _sync;

    if (sync != null && sync.pending.value > 0) {
      sync.enqueue(item);
      await sync.sync(force: true);
      return _fromQueue(sync, item);
    }

    try {
      final response = await _uploader.send(item, offline: false);
      return RecordSent(
        type,
        item.clientTime,
        accuracyM: fix.accuracyM,
        record: AttendanceRecord.fromJson(response.data),
        replayed: response.meta['replayed'] == true,
      );
    } on ApiError catch (e) {
      if (sync != null && SyncService.isTransient(e.code)) {
        sync.enqueue(item);
        return RecordQueued(type, item.clientTime, accuracyM: fix.accuracyM, cause: e);
      }
      return RecordRejected(type, item.clientTime, e, accuracyM: fix.accuracyM);
    }
  }

  static RecordOutcome _fromQueue(SyncService sync, PendingAttendance item) {
    final row = sync.find(item.clientUuid);
    if (row == null) {
      return RecordSent(item.type, item.clientTime, accuracyM: item.accuracyM);
    }
    if (row.status == QueueStatus.rejected) {
      return RecordRejected(
        item.type,
        item.clientTime,
        ApiError(code: ApiErrorCode.fromWire(row.lastErrorCode) ?? ApiErrorCode.unknown),
        accuracyM: item.accuracyM,
      );
    }
    return RecordQueued(item.type, item.clientTime, accuracyM: item.accuracyM);
  }
}
