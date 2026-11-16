import 'dart:async';
import 'dart:io';
import 'dart:math';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:pakis_pro/core/api/api_client.dart';
import 'package:pakis_pro/core/offline/queue_db.dart';
import 'package:pakis_pro/core/offline/sync_service.dart';
import 'package:pakis_pro/core/storage/secure_store.dart';

import '../../helpers/attendance_server.dart';
import '../../helpers/fakes.dart';

PendingAttendance item(String uuid, {String type = 'in', int minute = 0}) => PendingAttendance(
  clientUuid: uuid,
  type: type,
  clientTime: '2026-11-03T07:${minute.toString().padLeft(2, '0')}:00+07:00',
  latitude: -6.7891,
  longitude: 110.8412,
  accuracyM: 12,
  isMock: false,
  selfie: Uint8List.fromList(List.filled(90 * 1024, 1)),
  deviceClockOffsetS: 4,
  createdAt: DateTime(2026, 11, 3, 7, minute),
);

void main() {
  late Directory dir;
  late QueueDb queue;
  late FakeAttendanceServer server;
  late FakeAdapter adapter;
  late DateTime clock;
  late SyncService sync;

  setUp(() async {
    dir = Directory.systemTemp.createTempSync('pakis_sync_');
    queue = await QueueDb.open('${dir.path}${Platform.pathSeparator}q.db', store: MemorySecureStore());
    server = FakeAttendanceServer();
    adapter = FakeAdapter(server.handle);
    clock = DateTime(2026, 11, 3, 8);
    sync = SyncService(
      queue: queue,
      uploader: AttendanceUploader(
        ApiClient(baseUrl: 'https://pakis.test', appVersion: '1.0.0', deviceId: () async => 'a' * 64, adapter: adapter),
      ),
      now: () => clock,
    );
  });

  tearDown(() {
    sync.dispose();
    queue.close();
    dir.deleteSync(recursive: true);
  });

  test('kriteria T-042 / MOB-06: offline → tersimpan → online → terkirim tepat 1×', () async {
    server.online = false;
    sync.enqueue(item('u1'));
    expect(sync.pending.value, 1);

    final offline = await sync.sync();
    expect(offline.sent, 0);
    expect(queue.all().single.attempts, 1, reason: 'dijadwalkan ulang dengan backoff');

    server.online = true;
    final online = await sync.sync(force: true);
    expect(online.sent, 1);
    expect(sync.pending.value, 0);
    expect(server.stored.keys, ['u1']);

    // Field 05 §5.4.
    final sent = server.stored['u1']!;
    expect(sent['is_offline'], '1');
    expect(sent['type'], 'in');
    expect(sent['client_time'], '2026-11-03T07:00:00+07:00');
    expect(sent['device_clock_offset_s'], '4');
    expect(sent['is_mock'], '0');
    expect(server.selfies['u1'], 90 * 1024);
  });

  test('MOB-07: server menerima tapi jawaban hilang → kirim ulang → replayed, tetap 1 log', () async {
    server.dropResponseOnce = true;
    sync.enqueue(item('u1'));

    await sync.sync(force: true); // diterima server, HP mengira gagal
    expect(sync.pending.value, 1);
    await sync.sync(force: true);
    await sync.sync(force: true); // tidak ada apa-apa lagi untuk dikirim

    expect(sync.pending.value, 0);
    expect(server.stored, hasLength(1));
    expect(adapter.requests, hasLength(2));
  });

  test('galat permanen → rejected, baris berikutnya tetap dikirim', () async {
    server.override = (f) => f['client_uuid'] == 'tua' ? FakeReply(422, errorJson('ATTENDANCE_OFFLINE_TOO_OLD')) : null;
    sync
      ..enqueue(item('tua'))
      ..enqueue(item('baru', type: 'out', minute: 5));

    final result = await sync.sync(force: true);
    expect(result.sent, 1);
    expect(result.rejected, 1);
    expect(sync.rejected.value, 1);
    expect(queue.all().single.lastErrorCode, 'ATTENDANCE_OFFLINE_TOO_OLD');
  });

  test('perlu masuk ulang (401) → berhenti, antrean utuh tanpa menambah percobaan', () async {
    server.override = (_) => FakeReply(401, errorJson('UNAUTHENTICATED'));
    sync
      ..enqueue(item('a'))
      ..enqueue(item('b', minute: 1));

    final result = await sync.sync(force: true);
    expect(result.stoppedBy?.code.wire, 'UNAUTHENTICATED');
    expect(sync.pending.value, 2);
    expect(queue.all().every((e) => e.attempts == 0), isTrue);
    expect(adapter.requests, hasLength(1));
  });

  test('backoff dihormati tanpa force, dan absen pulang tidak mendahului absen masuk', () async {
    server.online = false;
    sync.enqueue(item('masuk'));
    await sync.sync(); // gagal → backoff
    server.online = true;
    sync.enqueue(item('pulang', type: 'out', minute: 30));

    final early = await sync.sync(); // belum jatuh tempo
    expect(early.sent, 0);
    expect(server.stored, isEmpty, reason: 'pulang tidak boleh terkirim sebelum masuk');

    clock = clock.add(const Duration(minutes: 20));
    final later = await sync.sync();
    expect(later.sent, 2);
    expect(server.stored.keys, ['masuk', 'pulang']);
  });

  test('server 503 → berhenti di baris pertama, urutan terjaga', () async {
    server.override = (_) => FakeReply(503, errorJson('SERVICE_UNAVAILABLE'));
    sync
      ..enqueue(item('a'))
      ..enqueue(item('b', minute: 1));

    await sync.sync(force: true);
    expect(adapter.requests, hasLength(1));
    expect(queue.all().first.lastErrorCode, 'SERVICE_UNAVAILABLE');
  });

  test('putaran tunggal: dua pemicu bersamaan tidak mengirim dua kali', () async {
    sync.enqueue(item('u1'));
    final results = await Future.wait([sync.sync(force: true), sync.sync(force: true)]);

    expect(identical(results[0], results[1]), isTrue);
    expect(adapter.requests, hasLength(1));
  });

  test('NFR-007: koneksi kembali memicu sinkron (tanpa menunggu backoff)', () async {
    server.online = false;
    sync.enqueue(item('u1'));
    final online = StreamController<bool>();
    addTearDown(online.close);

    sync.start(online: online.stream);
    await pumpEventQueue();
    expect(sync.pending.value, 1);

    server.online = true;
    online.add(true);
    await pumpEventQueue();
    expect(sync.pending.value, 0);
  });

  test('backoff eksponensial dengan batas', () {
    final r = Random(1);
    expect(sync.backoffFor(1, r).inSeconds, inInclusiveRange(27, 33));
    expect(sync.backoffFor(3, r).inSeconds, inInclusiveRange(108, 132));
    expect(sync.backoffFor(50, r).inMinutes, inInclusiveRange(13, 16));
  });

  test('ApiClient mencatat selisih jam dari header Date (05 §5.4)', () async {
    int? offset;
    final api = ApiClient(
      baseUrl: 'https://pakis.test',
      appVersion: '1.0.0',
      deviceId: () async => 'a' * 64,
      adapter: _DateAdapter('Tue, 03 Nov 2026 01:00:30 GMT'),
      now: () => DateTime.utc(2026, 11, 3, 1),
      onServerClock: (s) => offset = s,
    );
    await api.get('/api/v1/config');
    expect(offset, 30);
  });
}

class _DateAdapter implements HttpClientAdapter {
  _DateAdapter(this.date);

  final String date;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async => ResponseBody.fromString(
    '{"data":{},"meta":{}}',
    200,
    headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
      'date': [date],
    },
  );

  @override
  void close({bool force = false}) {}
}
