import 'dart:math';
import 'dart:typed_data';

import 'package:sqlite3/sqlite3.dart';

import '../storage/secure_store.dart';

/// Status baris antrean.
enum QueueStatus {
  /// Menunggu dikirim (atau dikirim ulang setelah [PendingAttendance.nextAttemptAt]).
  pending,

  /// Ditolak server dengan galat yang tidak akan berubah bila dikirim ulang
  /// (mis. `ATTENDANCE_OFFLINE_TOO_OLD`): tidak dicoba lagi, ditampilkan ke pengguna.
  rejected,
}

/// Satu absen yang belum diterima server (05 §5.4, 03 §4.5).
class PendingAttendance {
  const PendingAttendance({
    required this.clientUuid,
    required this.type,
    required this.clientTime,
    required this.latitude,
    required this.longitude,
    required this.accuracyM,
    required this.isMock,
    required this.createdAt,
    this.selfie,
    this.deviceClockOffsetS,
    this.attempts = 0,
    this.nextAttemptAt,
    this.lastErrorCode,
    this.status = QueueStatus.pending,
  });

  /// UUID v4 dibuat di HP. Kunci idempotensi server (`UNIQUE client_uuid`).
  final String clientUuid;

  /// `in` / `out`.
  final String type;

  /// ISO-8601 dengan offset, mis. `2026-11-03T07:41:12+07:00`.
  final String clientTime;
  final double latitude;
  final double longitude;
  final double accuracyM;
  final bool isMock;

  /// JPEG terkompresi. Disimpan di dalam DB terenkripsi, tidak pernah sebagai berkas polos.
  final Uint8List? selfie;
  final int? deviceClockOffsetS;
  final int attempts;
  final DateTime? nextAttemptAt;
  final String? lastErrorCode;
  final QueueStatus status;
  final DateTime createdAt;
}

/// Antrean absen offline di SQLite terenkripsi SQLCipher (03 §4.5, kriteria T-042
/// "antrean tidak bisa dibaca tanpa kunci").
///
/// Kunci acak 256 bit dibuat sekali dan disimpan di [SecureStore] (Android Keystore). Paket
/// `sqlite3` langsung (keputusan Mas 15 Nov 2026): satu tabel, tanpa drift/codegen.
class QueueDb {
  QueueDb._(this._db);

  static const _schemaVersion = 1;

  final Database _db;

  /// Membuka (atau membuat) DB di [path]. Gagal keras bila SQLCipher tidak aktif, supaya
  /// antrean tidak pernah tersimpan tanpa enkripsi secara diam-diam.
  static Future<QueueDb> open(String path, {required SecureStore store, Random? random}) async {
    final key = await _key(store, random ?? Random.secure());
    final db = sqlite3.open(path);
    try {
      final cipher = db.select('PRAGMA cipher_version');
      if (cipher.isEmpty || '${cipher.first.values.first ?? ''}'.isEmpty) {
        throw StateError('SQLCipher tidak aktif: antrean offline wajib terenkripsi');
      }
      // Kunci mentah 256 bit (`x'…'`), tanpa derivasi password. Nilai hex dibuat sendiri,
      // bukan input pengguna, jadi aman disisipkan (PRAGMA tidak menerima parameter).
      db.execute('PRAGMA key = "x\'$key\'"');
      // Kunci salah / berkas bukan DB → melempar di sini, bukan saat menulis data.
      db.select('SELECT count(*) FROM sqlite_master');
      _migrate(db);
      return QueueDb._(db);
    } catch (_) {
      db.close();
      rethrow;
    }
  }

  static Future<String> _key(SecureStore store, Random random) async {
    final existing = await store.read(StoreKeys.queueDbKey);
    if (existing != null && RegExp(r'^[0-9a-f]{64}$').hasMatch(existing)) {
      return existing;
    }
    final key = [for (var i = 0; i < 32; i++) random.nextInt(256).toRadixString(16).padLeft(2, '0')].join();
    await store.write(StoreKeys.queueDbKey, key);
    return key;
  }

  static void _migrate(Database db) {
    final version = db.select('PRAGMA user_version').first.values.first! as int;
    if (version < 1) {
      db.execute('''
        CREATE TABLE pending_attendance (
          client_uuid TEXT PRIMARY KEY NOT NULL,
          type TEXT NOT NULL CHECK (type IN ('in', 'out')),
          client_time TEXT NOT NULL,
          latitude REAL NOT NULL,
          longitude REAL NOT NULL,
          accuracy_m REAL NOT NULL,
          is_mock INTEGER NOT NULL,
          selfie BLOB,
          device_clock_offset_s INTEGER,
          attempts INTEGER NOT NULL DEFAULT 0,
          next_attempt_at INTEGER,
          last_error_code TEXT,
          status TEXT NOT NULL DEFAULT 'pending',
          created_at INTEGER NOT NULL
        )
      ''');
      db.execute('CREATE INDEX pending_attendance_queue_idx ON pending_attendance (status, created_at)');
    }
    db.execute('PRAGMA user_version = $_schemaVersion');
  }

  /// Menambah absen. `client_uuid` yang sama tidak menggandakan baris (idempoten di HP juga).
  void enqueue(PendingAttendance item) {
    _db.execute(
      '''INSERT OR IGNORE INTO pending_attendance
         (client_uuid, type, client_time, latitude, longitude, accuracy_m, is_mock, selfie,
          device_clock_offset_s, attempts, next_attempt_at, last_error_code, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)''',
      [
        item.clientUuid,
        item.type,
        item.clientTime,
        item.latitude,
        item.longitude,
        item.accuracyM,
        item.isMock ? 1 : 0,
        item.selfie,
        item.deviceClockOffsetS,
        item.attempts,
        item.nextAttemptAt?.millisecondsSinceEpoch,
        item.lastErrorCode,
        item.status.name,
        item.createdAt.millisecondsSinceEpoch,
      ],
    );
  }

  /// Baris `pending` yang sudah boleh dikirim pada [now], urut waktu dibuat (FIFO): absen
  /// masuk harus sampai sebelum absen pulang hari yang sama.
  List<PendingAttendance> due(DateTime now, {int limit = 50}) => _rows(
    _db.select(
      '''SELECT * FROM pending_attendance
         WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
         ORDER BY created_at, client_uuid LIMIT ?''',
      [now.millisecondsSinceEpoch, limit],
    ),
  );

  List<PendingAttendance> all() =>
      _rows(_db.select('SELECT * FROM pending_attendance ORDER BY created_at, client_uuid'));

  PendingAttendance? find(String clientUuid) {
    final rows = _rows(_db.select('SELECT * FROM pending_attendance WHERE client_uuid = ?', [clientUuid]));
    return rows.isEmpty ? null : rows.first;
  }

  int count({QueueStatus status = QueueStatus.pending}) =>
      _db.select('SELECT count(*) AS n FROM pending_attendance WHERE status = ?', [status.name]).first['n'] as int;

  /// Server sudah menerima (201, atau 200 `replayed`): hapus beserta selfienya.
  void remove(String clientUuid) => _db.execute('DELETE FROM pending_attendance WHERE client_uuid = ?', [clientUuid]);

  void scheduleRetry(String clientUuid, {required int attempts, required DateTime nextAttemptAt, String? errorCode}) =>
      _db.execute(
        'UPDATE pending_attendance SET attempts = ?, next_attempt_at = ?, last_error_code = ? WHERE client_uuid = ?',
        [attempts, nextAttemptAt.millisecondsSinceEpoch, errorCode, clientUuid],
      );

  void reject(String clientUuid, String errorCode) => _db.execute(
    "UPDATE pending_attendance SET status = 'rejected', last_error_code = ?, selfie = NULL WHERE client_uuid = ?",
    [errorCode, clientUuid],
  );

  void close() => _db.close();

  static List<PendingAttendance> _rows(ResultSet rows) => [
    for (final r in rows)
      PendingAttendance(
        clientUuid: r['client_uuid'] as String,
        type: r['type'] as String,
        clientTime: r['client_time'] as String,
        latitude: (r['latitude'] as num).toDouble(),
        longitude: (r['longitude'] as num).toDouble(),
        accuracyM: (r['accuracy_m'] as num).toDouble(),
        isMock: r['is_mock'] == 1,
        selfie: r['selfie'] as Uint8List?,
        deviceClockOffsetS: r['device_clock_offset_s'] as int?,
        attempts: r['attempts'] as int,
        nextAttemptAt: r['next_attempt_at'] == null
            ? null
            : DateTime.fromMillisecondsSinceEpoch(r['next_attempt_at'] as int),
        lastErrorCode: r['last_error_code'] as String?,
        status: QueueStatus.values.byName(r['status'] as String),
        createdAt: DateTime.fromMillisecondsSinceEpoch(r['created_at'] as int),
      ),
  ];
}
