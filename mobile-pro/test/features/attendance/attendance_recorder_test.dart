import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:pakis_pro/core/api/api_client.dart';
import 'package:pakis_pro/core/api/api_error.dart';
import 'package:pakis_pro/core/location/location_service.dart';
import 'package:pakis_pro/core/offline/queue_db.dart';
import 'package:pakis_pro/core/offline/sync_service.dart';
import 'package:pakis_pro/core/storage/secure_store.dart';
import 'package:pakis_pro/features/attendance/attendance_recorder.dart';

import '../../helpers/attendance_server.dart';
import '../../helpers/fakes.dart';

const fix = LocationFix(latitude: -6.7891, longitude: 110.8412, accuracyM: 12, isMock: false);

Map<String, Object?> lateDay(Map<String, String> fields) => {
  'work_date': '2026-11-03',
  'status': 'late',
  'late_minutes': 7,
  'shift': {'code': 'SLS', 'name': 'Sales', 'start': '07:30', 'end': '16:30'},
  'check_in_at': '2026-11-03T07:37:02+07:00',
  'check_out_at': fields['type'] == 'out' ? '2026-11-03T16:35:00+07:00' : null,
  'location': {'name': 'Kantor Pusat Kalirejo', 'distance_m': 42},
  'is_offline': fields['is_offline'] == '1',
};

void main() {
  late Directory dir;
  late QueueDb queue;
  late FakeAttendanceServer server;
  late SyncService sync;
  late AttendanceRecorder recorder;
  late DateTime clock;
  final selfie = Uint8List.fromList(List.filled(80 * 1024, 7));

  setUp(() async {
    dir = Directory.systemTemp.createTempSync('pakis_rec_');
    queue = await QueueDb.open('${dir.path}${Platform.pathSeparator}q.db', store: MemorySecureStore());
    server = FakeAttendanceServer()..respond = lateDay;
    final uploader = AttendanceUploader(
      ApiClient(
        baseUrl: 'https://pakis.test',
        appVersion: '1.0.0',
        deviceId: () async => 'a' * 64,
        adapter: FakeAdapter(server.handle),
      ),
    );
    clock = DateTime(2026, 11, 3, 7, 37, 2);
    sync = SyncService(queue: queue, uploader: uploader);
    recorder = AttendanceRecorder(
      uploader: uploader,
      sync: sync,
      clockOffset: () => -5,
      // Setiap presensi beberapa detik setelah sebelumnya (urutan antrean = waktu dibuat).
      now: () => clock = clock.add(const Duration(seconds: 5)),
    );
  });

  tearDown(() {
    sync.dispose();
    queue.close();
    dir.deleteSync(recursive: true);
  });

  test('online → langsung terkirim (is_offline 0), hasil berisi status telat dari server', () async {
    final outcome = await recorder.record(type: 'in', fix: fix, selfie: selfie);

    expect(outcome, isA<RecordSent>());
    final sent = outcome as RecordSent;
    expect(sent.record!.status, 'late');
    expect(sent.record!.lateMinutes, 7);
    expect(sent.record!.locationName, 'Kantor Pusat Kalirejo');
    expect(sent.accuracyM, 12);
    expect(sync.pending.value, 0);

    final fields = server.stored.values.single;
    expect(fields['is_offline'], '0');
    expect(fields['type'], 'in');
    expect(fields['client_time'], startsWith('2026-11-03T07:37:07'));
    expect(fields['client_time'], matches(RegExp(r'[+-]\d{2}:\d{2}$')), reason: 'ISO-8601 dengan offset (05 §5.4)');
    expect(fields['device_clock_offset_s'], '-5');
    expect(fields['client_uuid'], matches(RegExp(r'^[0-9a-f-]{36}$')));
    expect(server.selfies.values.single, 80 * 1024);
  });

  test('kriteria T-042: mode pesawat → presensi → online → terkirim 1× walau dikirim ulang', () async {
    server.online = false;
    final outcome = await recorder.record(type: 'in', fix: fix, selfie: selfie);
    expect(outcome, isA<RecordQueued>(), reason: '07 §5: "Tersimpan di HP, akan dikirim otomatis"');
    expect(sync.pending.value, 1);
    expect(server.stored, isEmpty);

    // Sinyal kembali, tetapi jawaban server hilang di jalan (MOB-07): HP mengira gagal.
    server
      ..online = true
      ..dropResponseOnce = true;
    await sync.sync(force: true);
    expect(sync.pending.value, 1);
    expect(server.stored, hasLength(1));

    // Pemicu berikutnya (koneksi kembali / timer / tombol Kirim) mengirim ulang uuid yang sama.
    await sync.sync(force: true);
    await sync.sync(force: true);

    expect(sync.pending.value, 0);
    expect(server.stored, hasLength(1), reason: 'client_uuid sama → replayed, tidak menggandakan log');
    expect(server.stored.values.single['is_offline'], '1');
  });

  test('antrean masih berisi presensi masuk → presensi pulang ikut antre, urutan masuk → pulang terjaga', () async {
    server.online = false;
    await recorder.record(type: 'in', fix: fix, selfie: selfie);

    server.online = true;
    final out = await recorder.record(type: 'out', fix: fix, selfie: selfie);

    expect(out, isA<RecordSent>());
    expect((out as RecordSent).record, isNull, reason: 'terkirim lewat antrean');
    expect(server.stored.values.map((f) => f['type']), ['in', 'out']);
    expect(sync.pending.value, 0);
  });

  test('ditolak aturan presensi → RecordRejected, tidak masuk antrean', () async {
    server.override = (_) =>
        FakeReply(422, errorJson('ATTENDANCE_OUT_OF_RADIUS', details: {'distance_m': 812, 'radius_m': 100}));

    final outcome = await recorder.record(type: 'in', fix: fix, selfie: selfie);

    expect(outcome, isA<RecordRejected>());
    expect((outcome as RecordRejected).error.code, ApiErrorCode.attendanceOutOfRadius);
    expect(sync.pending.value, 0);
    expect(queue.all(), isEmpty);
  });

  test('lokasi palsu tetap dikirim ke server (BR-065), tidak disaring di HP', () async {
    await recorder.record(
      type: 'in',
      fix: const LocationFix(latitude: -6.7891, longitude: 110.8412, accuracyM: 5, isMock: true),
      selfie: selfie,
    );

    expect(server.stored.values.single['is_mock'], '1');
  });

  test('antrean tidak tersedia → offline menjadi galat, bukan hilang diam-diam', () async {
    final onlineOnly = AttendanceRecorder(
      uploader: AttendanceUploader(
        ApiClient(
          baseUrl: 'https://pakis.test',
          appVersion: '1.0.0',
          deviceId: () async => 'a' * 64,
          adapter: FakeAdapter((_) => const FakeReply.offline()),
        ),
      ),
      sync: null,
      clockOffset: () => null,
    );

    final outcome = await onlineOnly.record(type: 'in', fix: fix, selfie: selfie);

    expect(outcome, isA<RecordRejected>());
    expect((outcome as RecordRejected).error.code, ApiErrorCode.networkOffline);
  });
}
