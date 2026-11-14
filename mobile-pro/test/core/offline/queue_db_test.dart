import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:pakis_pro/core/offline/queue_db.dart';
import 'package:pakis_pro/core/storage/secure_store.dart';
import 'package:sqlite3/sqlite3.dart';

PendingAttendance item(String uuid, {int minute = 0, String type = 'in'}) => PendingAttendance(
  clientUuid: uuid,
  type: type,
  clientTime: '2026-11-03T07:${minute.toString().padLeft(2, '0')}:00+07:00',
  latitude: -6.7891,
  longitude: 110.8412,
  accuracyM: 12.5,
  isMock: false,
  selfie: Uint8List.fromList(List.filled(2048, 7)),
  deviceClockOffsetS: -3,
  createdAt: DateTime(2026, 11, 3, 7, minute),
);

void main() {
  late Directory dir;
  late MemorySecureStore store;
  String path() => '${dir.path}${Platform.pathSeparator}queue.db';

  setUp(() {
    dir = Directory.systemTemp.createTempSync('pakis_queue_');
    store = MemorySecureStore();
  });
  tearDown(() => dir.deleteSync(recursive: true));

  test('memakai SQLCipher (bukan SQLite biasa)', () {
    final db = sqlite3.openInMemory();
    addTearDown(db.close);
    expect('${db.select('PRAGMA cipher_version').first.values.first}', isNotEmpty);
  });

  test('simpan, baca ulang setelah dibuka ulang, urut FIFO', () async {
    var queue = await QueueDb.open(path(), store: store);
    queue
      ..enqueue(item('b', minute: 41))
      ..enqueue(item('a', minute: 40, type: 'out'))
      ..close();

    queue = await QueueDb.open(path(), store: store);
    addTearDown(queue.close);
    final due = queue.due(DateTime(2026, 11, 3, 8));
    expect(due.map((e) => e.clientUuid), ['a', 'b']);
    expect(due.first.type, 'out');
    expect(due.first.selfie, hasLength(2048));
    expect(due.first.deviceClockOffsetS, -3);
    expect(queue.count(), 2);
  });

  test('kriteria T-042: antrean tidak bisa dibaca tanpa kunci', () async {
    (await QueueDb.open(path(), store: store))
      ..enqueue(item('rahasia'))
      ..close();

    // Tanpa kunci: berkas tidak dikenali sebagai database.
    final raw = sqlite3.open(path());
    addTearDown(raw.close);
    expect(() => raw.select('SELECT * FROM pending_attendance'), throwsA(isA<SqliteException>()));

    // Kunci lain: ditolak saat dibuka.
    final otherStore = MemorySecureStore({StoreKeys.queueDbKey: 'ab' * 32});
    await expectLater(QueueDb.open(path(), store: otherStore), throwsA(isA<SqliteException>()));

    // Isi tidak tampak sebagai teks polos di berkas.
    final bytes = File(path()).readAsBytesSync();
    expect(String.fromCharCodes(bytes).contains('rahasia'), isFalse);
    expect(String.fromCharCodes(bytes.sublist(0, 15)), isNot('SQLite format 3'));
  });

  test('kunci dibuat sekali, 256 bit, disimpan di penyimpanan aman', () async {
    (await QueueDb.open(path(), store: store)).close();
    final key = store.values[StoreKeys.queueDbKey];
    expect(key, matches(RegExp(r'^[0-9a-f]{64}$')));

    (await QueueDb.open(path(), store: store)).close();
    expect(store.values[StoreKeys.queueDbKey], key);
  });

  test('client_uuid sama tidak menggandakan baris', () async {
    final queue = await QueueDb.open(path(), store: store);
    addTearDown(queue.close);
    queue
      ..enqueue(item('x'))
      ..enqueue(item('x'));
    expect(queue.count(), 1);
  });

  test('retry tertunda tidak ikut due; reject menghapus selfie dan berhenti dicoba', () async {
    final queue = await QueueDb.open(path(), store: store);
    addTearDown(queue.close);
    queue
      ..enqueue(item('a'))
      ..enqueue(item('b', minute: 1))
      ..scheduleRetry('a', attempts: 1, nextAttemptAt: DateTime(2026, 11, 3, 9), errorCode: 'NETWORK_OFFLINE')
      ..reject('b', 'ATTENDANCE_OFFLINE_TOO_OLD');

    expect(queue.due(DateTime(2026, 11, 3, 8)), isEmpty);
    expect(queue.due(DateTime(2026, 11, 3, 9)).single.attempts, 1);
    final rejected = queue.all().firstWhere((e) => e.clientUuid == 'b');
    expect(rejected.status, QueueStatus.rejected);
    expect(rejected.selfie, isNull, reason: 'foto tidak disimpan lebih lama dari perlu');
    expect(queue.count(status: QueueStatus.rejected), 1);

    queue.remove('a');
    expect(queue.count(), 0);
  });

  test('NFR-007: menampung ≥ 50 absen tertunda', () async {
    final queue = await QueueDb.open(path(), store: store);
    addTearDown(queue.close);
    for (var i = 0; i < 60; i++) {
      queue.enqueue(item('u$i', minute: i % 60));
    }
    expect(queue.count(), 60);
    expect(queue.due(DateTime(2026, 11, 4), limit: 100), hasLength(60));
  });
}
