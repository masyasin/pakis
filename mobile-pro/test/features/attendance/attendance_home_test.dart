import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:material_ui/material_ui.dart';
import 'package:pakis_pro/core/location/location_service.dart';
import 'package:pakis_pro/core/offline/offline_providers.dart';
import 'package:pakis_pro/core/offline/queue_db.dart';
import 'package:pakis_pro/core/storage/secure_store.dart';
import 'package:pakis_pro/features/attendance/attendance_providers.dart';
import 'package:pakis_pro/features/attendance/result/attendance_submit_screen.dart';

import '../../core/location/location_service_test.dart' show FakeGeo;
import '../../helpers/app_harness.dart';
import '../../helpers/attendance_server.dart';
import '../../helpers/fakes.dart';

Map<String, Object?> todayJson({Map<String, Object?>? attendance, String? next = 'in'}) => {
  'data': {
    'work_date': '2026-11-03',
    'schedule': {
      'kind': 'shift',
      'shift': {'code': 'SLS', 'name': 'Sales', 'start': '07:30', 'end': '16:30'},
      'holiday': null,
    },
    'attendance': attendance,
    'next_action': next,
  },
  'meta': {'request_id': '01JBX8Q6V7M3T2K9R4N5P6W8YZ'},
};

Map<String, Object?> lateDay(Map<String, String> fields) => {
  'work_date': '2026-11-03',
  'status': 'late',
  'late_minutes': 7,
  'shift': {'code': 'SLS', 'name': 'Sales', 'start': '07:30', 'end': '16:30'},
  'check_in_at': '2026-11-03T07:37:02+07:00',
  'check_out_at': null,
  'location': {'name': 'Kantor Pusat Kalirejo', 'distance_m': 42},
  'is_offline': fields['is_offline'] == '1',
};

/// Server lengkap untuk beranda: config, /me, attendance/today, dan POST presensi.
class HomeServer {
  final attendance = FakeAttendanceServer()..respond = lateDay;
  var todayOnline = true;
  var today = todayJson();

  late final adapter = FakeAdapter((o) {
    if (o.path.endsWith('/api/v1/config')) {
      return FakeReply(200, configJson());
    }
    if (o.path.endsWith('/me')) {
      return FakeReply(200, {'data': userJson(), 'meta': <String, Object?>{}});
    }
    if (o.path.endsWith('/attendance/today')) {
      return todayOnline ? FakeReply(200, today) : const FakeReply.offline();
    }
    if (o.path.endsWith('/api/v1/attendance') && o.method == 'POST') {
      return attendance.handle(o);
    }
    return FakeReply(404, errorJson('NOT_FOUND'));
  });
}

void main() {
  late Directory dir;
  late HomeServer server;
  late FakeGeo geo;
  late QueueDb queue;
  late List<int> selfieCalls;
  late MemorySecureStore queueStore;
  Uint8List? selfie;

  MemorySecureStore signedInStore() =>
      MemorySecureStore({StoreKeys.authToken: '12|tokenuji', StoreKeys.authUser: jsonEncode(userJson())});

  setUp(() {
    dir = Directory.systemTemp.createTempSync('pakis_home_');
    server = HomeServer();
    geo = FakeGeo();
    selfieCalls = [];
    // Kunci SQLCipher antrean tetap sama bila HP "dibuka ulang" di test yang sama.
    queueStore = MemorySecureStore();
    selfie = Uint8List.fromList(List.filled(60 * 1024, 3));
  });

  tearDown(() {
    queue.close();
    dir.deleteSync(recursive: true);
  });

  Future<void> pumpHome(WidgetTester tester, {MemorySecureStore? store}) async {
    queue = (await tester.runAsync(() => QueueDb.open('${dir.path}${Platform.pathSeparator}q.db', store: queueStore)))!;
    await pumpPakisPro(
      tester,
      adapter: server.adapter,
      store: store ?? signedInStore(),
      overrides: [
        queueDbProvider.overrideWith((ref) async => queue),
        locationServiceProvider.overrideWithValue(LocationService(geo)),
        selfieCaptureProvider.overrideWithValue((context) async {
          selfieCalls.add(1);
          return selfie;
        }),
      ],
    );
  }

  Future<void> tapAttendance(WidgetTester tester) async {
    await tester.tap(find.byKey(const ValueKey('attendance-button')));
    await tester.pumpAndSettle();
  }

  testWidgets('beranda: kartu shift + tombol Masuk → selfie → GPS → kirim → hasil telat → tombol Pulang', (
    tester,
  ) async {
    await pumpHome(tester);

    expect(find.text('Shift Sales · 07.30–16.30'), findsOneWidget);
    expect(find.text('Belum presensi. Ketuk Masuk saat tiba di lokasi kerja.'), findsOneWidget);
    expect(find.text('Masuk'), findsWidgets);

    await tapAttendance(tester);

    expect(selfieCalls, hasLength(1));
    expect(find.byType(AttendanceSubmitScreen), findsOneWidget);
    expect(find.text('Presensi masuk tercatat pukul 07.37'), findsOneWidget);
    expect(find.text('Telat'), findsOneWidget, reason: 'label status 07 §6');
    expect(find.textContaining('Telat 7 menit.'), findsOneWidget);
    expect(find.textContaining('Kantor Pusat Kalirejo · 42 m'), findsOneWidget);
    expect(server.attendance.stored.values.single['is_offline'], '0');
    expect(server.attendance.stored.values.single['accuracy_m'], '12.0');

    await tester.tap(find.widgetWithText(FilledButton, 'Selesai'));
    await tester.pumpAndSettle();

    expect(find.text('Sudah masuk pukul 07.37. Ketuk Pulang saat selesai kerja.'), findsOneWidget);
    expect(find.text('Pulang'), findsWidgets);
    expect(find.text('07.37'), findsOneWidget, reason: 'jam masuk di kartu shift');
  });

  testWidgets('offline (mode pesawat): tersimpan di HP → indikator antrean → online → Kirim → terkirim 1×', (
    tester,
  ) async {
    server
      ..todayOnline = false
      ..attendance.online = false;
    await pumpHome(tester);

    expect(find.textContaining('Tidak terhubung ke server.'), findsOneWidget);
    await tapAttendance(tester);

    expect(find.text('Tersimpan di HP, akan dikirim otomatis'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'Selesai'));
    await tester.pumpAndSettle();

    expect(find.text('1 presensi menunggu sinyal'), findsOneWidget);
    expect(find.textContaining('Ketuk Pulang'), findsOneWidget, reason: 'tombol berganti walau offline');
    expect(queue.all().single.selfie, hasLength(60 * 1024));

    server
      ..todayOnline = true
      ..attendance.online = true
      ..today = todayJson(attendance: lateDay({'is_offline': '1'}), next: 'out');
    // ListView membangun kartu secara lazy: gulir sampai tombol benar-benar tampil.
    await tester.scrollUntilVisible(find.widgetWithText(TextButton, 'Kirim'), 120);
    await tester.pumpAndSettle(); // tunggu gulir berhenti, kalau tidak ketukan hanya menghentikan gulir
    await tester.tap(find.widgetWithText(TextButton, 'Kirim'));
    await tester.pumpAndSettle();

    expect(find.text('Semua presensi tertunda sudah terkirim.'), findsOneWidget, reason: 'snackbar');
    expect(find.text('Semua presensi terkirim'), findsOneWidget);
    expect(server.attendance.stored, hasLength(1));
    expect(server.attendance.stored.values.single['is_offline'], '1');
    expect(queue.all(), isEmpty);
  });

  testWidgets('akurasi GPS buruk → arahan pindah ke tempat terbuka, tanpa kiriman; Coba Lagi berhasil', (tester) async {
    geo.accuracy = 250;
    await pumpHome(tester);
    await tapAttendance(tester);

    expect(find.text('Lokasi belum didapat'), findsOneWidget);
    expect(find.textContaining('Pindah ke tempat terbuka'), findsOneWidget);
    expect(server.attendance.stored, isEmpty);

    geo.accuracy = 20;
    await tester.tap(find.widgetWithText(FilledButton, 'Coba Lagi'));
    await tester.pumpAndSettle();

    expect(find.text('Presensi masuk tercatat pukul 07.37'), findsOneWidget);
    expect(server.attendance.stored, hasLength(1));
  });

  testWidgets('di luar radius → pesan jarak dari server + Coba Lagi; tidak masuk antrean', (tester) async {
    server.attendance.override = (_) => FakeReply(
      422,
      errorJson(
        'ATTENDANCE_OUT_OF_RADIUS',
        details: {'distance_m': 812, 'radius_m': 100, 'location_name': 'Kantor Pusat Kalirejo'},
      ),
    );
    await pumpHome(tester);
    await tapAttendance(tester);

    expect(find.text('Presensi masuk belum tercatat'), findsOneWidget);
    expect(find.textContaining('Anda berjarak 812 m dari Kantor Pusat Kalirejo (batas 100 m)'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Coba Lagi'), findsOneWidget);
    expect(queue.all(), isEmpty);
  });

  testWidgets('kamera dibatalkan → tidak ada kiriman', (tester) async {
    selfie = null;
    await pumpHome(tester);
    await tapAttendance(tester);

    expect(find.byType(AttendanceSubmitScreen), findsNothing);
    expect(server.attendance.stored, isEmpty);
  });

  testWidgets('presensi hari ini selesai → tombol nonaktif', (tester) async {
    server.today = todayJson(
      attendance: {...lateDay(const {}), 'check_out_at': '2026-11-03T16:35:00+07:00'},
      next: null,
    );
    await pumpHome(tester);

    expect(find.text('Presensi hari ini selesai. Pulang pukul 16.35.'), findsOneWidget);
    final button = tester.widget<FilledButton>(find.byKey(const ValueKey('attendance-button')));
    expect(button.onPressed, isNull);
  });

  testWidgets('dibuka tanpa sinyal setelah pernah online → jadwal dari salinan terakhir', (tester) async {
    final store = signedInStore();
    await pumpHome(tester, store: store);
    expect(store.values.containsKey(StoreKeys.attendanceToday), isTrue);
    queue.close();

    server.todayOnline = false;
    await pumpHome(tester, store: store);

    expect(find.text('Shift Sales · 07.30–16.30'), findsOneWidget);
    expect(find.textContaining('Data jadwal dari pukul'), findsOneWidget);
  });
}
