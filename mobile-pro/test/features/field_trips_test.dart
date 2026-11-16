import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:material_ui/material_ui.dart';
import 'package:pakis_pro/features/approvals/approvals_screen.dart';
import 'package:pakis_pro/features/field_trips/field_trip.dart';
import 'package:pakis_pro/features/field_trips/field_trip_detail_screen.dart';
import 'package:pakis_pro/features/field_trips/my_field_trips_screen.dart';

import '../helpers/app_harness.dart';
import '../helpers/fakes.dart';
import 'home_tabs_test.dart' show listing, openTab, server, signedIn;

Map<String, Object?> tripJson({int id = 5, String status = 'pending', String rule = 'destination'}) => {
  'id': id,
  'number': 'DL/2026/11/000$id',
  'status': status,
  'start_date': '2026-11-18',
  'end_date': '2026-11-20',
  'days': 3,
  'destination': 'Kios Tani Makmur, Pati',
  'purpose': 'Kunjungan pelanggan',
  'attendance_rule': rule,
  'destinations': [
    {'name': 'Kios Tani Makmur', 'radius_m': 200},
  ],
  'employees': [
    {'id': 7, 'employee_number': 'PJA-260007', 'name': 'Bayu Saputra'},
  ],
  'requested_by': {'name': 'Bayu Saputra'},
  'is_request': true,
  'submitted_at': '2026-11-16T09:12:00+07:00',
  'decided_at': null,
  'decided_by': null,
  'decision_note': null,
};

Map<String, Object?> todayJson({Map<String, Object?>? fieldTrip}) => {
  'data': {
    'work_date': '2026-11-18',
    'schedule': {
      'kind': 'shift',
      'shift': {'code': 'SLS', 'name': 'Sales', 'start': '07:30', 'end': '16:30'},
      'holiday': null,
    },
    'attendance': null,
    'next_action': 'in',
    'field_trip': fieldTrip,
  },
  'meta': {'request_id': '01JBX8Q6V7M3T2K9R4N5P6W8YZ'},
};

void main() {
  group('parseMapsLink (tempel tautan Google Maps)', () {
    test('pola @lat,lng, q=, ll=, /place/…/@ dan !3d!4d', () {
      expect(parseMapsLink('https://www.google.com/maps/@-6.8041,110.8412,17z'), (
        latitude: -6.8041,
        longitude: 110.8412,
      ));
      expect(parseMapsLink('https://maps.google.com/?q=-6.75,111.03'), (latitude: -6.75, longitude: 111.03));
      expect(parseMapsLink('https://maps.google.com/maps?ll=-6.75,111.03&z=16'), (latitude: -6.75, longitude: 111.03));
      expect(
        parseMapsLink('https://www.google.com/maps/place/Kios+Tani/@-6.7001,111.0402,17z/data=!3d-6.7005!4d111.0410'),
        (latitude: -6.7005, longitude: 111.041),
        reason: '!3d!4d = titik tempat, lebih tepat daripada pusat peta @',
      );
      expect(parseMapsLink('-6.80, 110.84'), (latitude: -6.8, longitude: 110.84));
    });

    test('tautan pendek & teks lain tidak bisa diurai di HP', () {
      expect(parseMapsLink('https://maps.app.goo.gl/AbCdEf123'), isNull);
      expect(isShortMapsLink('https://maps.app.goo.gl/AbCdEf123'), isTrue);
      expect(isShortMapsLink('https://goo.gl/maps/xyz'), isTrue);
      expect(parseMapsLink('Kios Tani Makmur'), isNull);
      expect(parseMapsLink('@-96.0,110.0'), isNull, reason: 'lintang di luar rentang');
    });
  });

  group('beranda: kartu tugas luar kota', () {
    testWidgets('tugas approved hari ini → "Tugas luar kota aktif"', (tester) async {
      final adapter = server(
        {
          'GET /attendance/today': (_) => FakeReply(
            200,
            todayJson(
              fieldTrip: {
                'number': 'DL/2026/11/0005',
                'destination': 'Pati',
                'end_date': '2026-11-20',
                'attendance_rule': 'any_location',
              },
            ),
          ),
        },
        permissions: ['field_trips.request'],
      );
      await pumpPakisPro(
        tester,
        adapter: adapter,
        store: signedIn(permissions: ['field_trips.request']),
      );

      expect(find.text('Tugas luar kota aktif'), findsOneWidget);
      expect(find.textContaining('DL/2026/11/0005 · Pati'), findsOneWidget);
      expect(find.textContaining('presensi: lokasi pakis mana pun'), findsOneWidget);
    });

    testWidgets('tanpa field_trips.request dan tanpa tugas aktif → kartu tidak tampil', (tester) async {
      final adapter = server({'GET /attendance/today': (_) => FakeReply(200, todayJson())});
      await pumpPakisPro(tester, adapter: adapter, store: signedIn());

      expect(find.textContaining('Tugas luar kota'), findsNothing);
    });
  });

  group('ajukan tugas luar kota', () {
    const permissions = ['field_trips.request'];

    testWidgets('kartu → daftar → form: validasi, titik dari tautan Maps, kirim JSON', (tester) async {
      var created = false;
      RequestOptions? posted;
      final adapter = server({
        'GET /attendance/today': (_) => FakeReply(200, todayJson()),
        'GET /field-trips': (_) => FakeReply(200, listing('my_field_trips', created ? [tripJson()] : const [])),
        'POST /field-trips': (o) {
          posted = o;
          created = true;
          return FakeReply(201, {'data': tripJson()});
        },
      }, permissions: permissions);
      await pumpPakisPro(
        tester,
        adapter: adapter,
        store: signedIn(permissions: permissions),
      );

      await tester.tap(find.text('Tugas luar kota'));
      await tester.pumpAndSettle();
      expect(find.byType(MyFieldTripsScreen), findsOneWidget);
      expect(find.text('Belum ada tugas luar kota'), findsOneWidget);

      await tester.tap(find.text('Ajukan'));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Radius titik tujuan').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Kirim Pengajuan'));
      await tester.pumpAndSettle();
      expect(find.text('Tujuan wajib diisi, 2–150 karakter.'), findsOneWidget);
      expect(find.text('Tambahkan minimal satu titik tujuan.'), findsOneWidget);
      expect(posted, isNull);

      await tester.enterText(find.widgetWithText(TextField, 'Tujuan'), 'Kios Tani Makmur, Pati');
      await tester.tap(find.text('Tambah titik tujuan'));
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextField, 'Nama titik'), 'Kios Tani Makmur');
      await tester.enterText(find.widgetWithText(TextField, 'Tautan Google Maps'), 'https://maps.app.goo.gl/AbCd');
      await tester.tap(find.widgetWithText(FilledButton, 'Tambah'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Tautan pendek tidak bisa dibaca.'), findsOneWidget);

      await tester.enterText(
        find.widgetWithText(TextField, 'Tautan Google Maps'),
        'https://www.google.com/maps/place/Kios/@-6.7001,111.0402,17z',
      );
      await tester.pump();
      expect(find.textContaining('Koordinat terbaca: -6.70010, 111.04020'), findsOneWidget);
      await tester.enterText(find.widgetWithText(TextField, 'Radius (meter, opsional)'), '150');
      await tester.tap(find.widgetWithText(FilledButton, 'Tambah'));
      await tester.pumpAndSettle();
      expect(find.text('Radius 150 m'), findsOneWidget);

      await tester.tap(find.text('Kirim Pengajuan'));
      await tester.pumpAndSettle();

      final body = (posted!.data! as Map).cast<String, Object?>();
      expect(body['destination'], 'Kios Tani Makmur, Pati');
      expect(body['attendance_rule'], 'destination');
      expect(body['start_date'], body['end_date'], reason: 'bawaan 1 hari');
      expect(body.containsKey('purpose'), isFalse);
      final point = ((body['destinations']! as List).single as Map).cast<String, Object?>();
      expect(point, {'name': 'Kios Tani Makmur', 'latitude': -6.7001, 'longitude': 111.0402, 'radius_m': 150});

      expect(find.textContaining('Pengajuan DL/2026/11/0005 terkirim'), findsOneWidget);
      expect(find.text('Kios Tani Makmur, Pati'), findsOneWidget, reason: 'daftar dimuat ulang');
    });

    testWidgets('aturan selain titik tujuan tidak mengirim destinations; galat server per field', (tester) async {
      RequestOptions? posted;
      final adapter = server({
        'GET /attendance/today': (_) => FakeReply(200, todayJson()),
        'GET /field-trips': (_) => FakeReply(200, listing('my_field_trips', const [])),
        'POST /field-trips': (o) {
          posted = o;
          return FakeReply(
            422,
            errorJson(
              'VALIDATION_FAILED',
              details: {
                'start_date': ['Tanggal tumpang tindih dengan tugas DL/2026/11/0003.'],
              },
            ),
          );
        },
      }, permissions: permissions);
      await pumpPakisPro(
        tester,
        adapter: adapter,
        store: signedIn(permissions: permissions),
      );
      await tester.tap(find.text('Tugas luar kota'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Ajukan'));
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextField, 'Tujuan'), 'Semarang');
      await tester.tap(find.text('Kirim Pengajuan'));
      await tester.pumpAndSettle();

      final body = (posted!.data! as Map).cast<String, Object?>();
      expect(body['attendance_rule'], 'anywhere');
      expect(body.containsKey('destinations'), isFalse);
      expect(find.text('Tanggal tumpang tindih dengan tugas DL/2026/11/0003.'), findsOneWidget);
    });

    testWidgets('detail milik sendiri: tanpa tombol Setujui/Tolak/Batalkan (06 §7.2a)', (tester) async {
      final adapter = server({
        'GET /attendance/today': (_) => FakeReply(200, todayJson()),
        'GET /field-trips': (_) => FakeReply(200, listing('my_field_trips', [tripJson()])),
      }, permissions: permissions);
      await pumpPakisPro(
        tester,
        adapter: adapter,
        store: signedIn(permissions: permissions),
      );
      await tester.tap(find.text('Tugas luar kota'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Kios Tani Makmur, Pati'));
      await tester.pumpAndSettle();

      expect(find.byType(FieldTripDetailScreen), findsOneWidget);
      expect(find.text('18 Nov 2026 – 20 Nov 2026 (3 hari)'), findsOneWidget);
      expect(find.text('Kios Tani Makmur (radius 200 m)'), findsOneWidget);
      expect(find.text('Setujui'), findsNothing);
      expect(find.text('Tolak'), findsNothing);
      expect(find.textContaining('Batal'), findsNothing);
    });
  });

  group('persetujuan tugas luar kota', () {
    testWidgets('dua izin → segmen; Tugas luar kota → Tolak (alasan wajib) → POST reject', (tester) async {
      const permissions = ['attendance_corrections.approve', 'field_trips.approve'];
      RequestOptions? rejected;
      final adapter = server({
        'GET /approvals/attendance-corrections': (_) => FakeReply(200, listing('approval_corrections', const [])),
        'GET /approvals/field-trips': (_) => FakeReply(200, listing('approval_field_trips', [tripJson()])),
        'POST /5/reject': (o) {
          rejected = o;
          return FakeReply(200, {'data': tripJson(status: 'rejected')});
        },
      }, permissions: permissions);
      await pumpPakisPro(
        tester,
        adapter: adapter,
        store: signedIn(permissions: permissions),
      );
      await openTab(tester, 'Persetujuan');

      expect(find.byType(SegmentedButton<ApprovalKind>), findsOneWidget);
      expect(find.text('Tidak ada koreksi menunggu'), findsOneWidget);
      await tester.tap(find.text('Tugas luar kota'));
      await tester.pumpAndSettle();
      expect(find.text('Bayu Saputra'), findsOneWidget);

      await tester.tap(find.text('Bayu Saputra'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(OutlinedButton, 'Tolak'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Tolak'));
      await tester.pumpAndSettle();
      expect(find.text('Alasan penolakan wajib diisi, minimal 5 karakter.'), findsOneWidget);

      await tester.enterText(find.widgetWithText(TextField, 'Alasan penolakan'), 'Jadwal bentrok');
      await tester.tap(find.widgetWithText(FilledButton, 'Tolak'));
      await tester.pumpAndSettle();
      expect((rejected!.data! as Map)['note'], 'Jadwal bentrok');
      expect(find.text('Tugas luar kota ditolak.'), findsOneWidget);
    });

    testWidgets('hanya field_trips.approve → tab Persetujuan langsung daftar tugas; 409 → pesan server', (
      tester,
    ) async {
      const permissions = ['field_trips.approve'];
      final adapter = server({
        'GET /approvals/field-trips': (_) => FakeReply(200, listing('approval_field_trips', [tripJson()])),
        'POST /5/approve': (_) => const FakeReply(409, {
          'error': {
            'code': 'STATE_CONFLICT',
            'message': 'Tanggal tumpang tindih dengan tugas DL/2026/11/0003 yang sudah disetujui.',
            'details': <String, Object?>{},
            'request_id': '01JBX8Q6V7M3T2K9R4N5P6W8YZ',
          },
        }),
      }, permissions: permissions);
      await pumpPakisPro(
        tester,
        adapter: adapter,
        store: signedIn(permissions: permissions),
      );
      await openTab(tester, 'Persetujuan');

      expect(find.byType(SegmentedButton<ApprovalKind>), findsNothing, reason: 'satu jenis → tanpa segmen');
      await tester.tap(find.text('Bayu Saputra'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Setujui'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Setujui').last);
      await tester.pumpAndSettle();

      expect(find.text('Tanggal tumpang tindih dengan tugas DL/2026/11/0003 yang sudah disetujui.'), findsOneWidget);
      expect(find.byType(FieldTripDetailScreen), findsNothing, reason: 'kembali ke daftar');
    });
  });
}
