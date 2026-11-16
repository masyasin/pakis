import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:material_ui/material_ui.dart';
import 'package:pakis_pro/core/storage/secure_store.dart';
import 'package:pakis_pro/features/attendance/attendance_providers.dart';
import 'package:pakis_pro/features/corrections/correction_detail_screen.dart';
import 'package:pakis_pro/features/corrections/correction_form_screen.dart';
import 'package:pakis_pro/features/home/home_screen.dart';
import 'package:pakis_pro/features/notifications/notifications_screen.dart';

import '../helpers/app_harness.dart';
import '../helpers/fakes.dart';

/// Respons listing 05 §3 (data + meta.pagination + meta.listing).
Map<String, Object?> listing(
  String key,
  List<Map<String, Object?>> rows, {
  Map<String, Object?> filters = const {},
  List<Map<String, Object?>> specFilters = const [],
  String sort = '-created_at',
}) => {
  'data': rows,
  'meta': {
    'request_id': '01JBX8Q6V7M3T2K9R4N5P6W8YZ',
    'pagination': {'page': 1, 'per_page': 20, 'total': rows.length, 'last_page': 1},
    'listing': {
      'spec': {
        'key': key,
        'search_placeholder': 'Cari',
        'per_page_options': [20, 50],
        'default_sort': sort,
        'sorts': [
          {'key': sort, 'label': 'Terbaru'},
        ],
        'filters': specFilters,
      },
      'state': {'q': '', 'sort': sort, 'filters': filters},
    },
  },
};

Map<String, Object?> correctionJson({int id = 12, String status = 'pending', String name = 'Bayu Saputra'}) => {
  'id': id,
  'number': 'KOR-202611-00$id',
  'status': status,
  'work_date': '2026-11-16',
  'type': 'check_in',
  'proposed_check_in': '07:55',
  'proposed_check_out': null,
  'reason': {'code': 'forgot', 'name': 'Lupa presensi'},
  'note': 'Lupa menekan Masuk',
  'has_attachment': false,
  'employee': {'id': 7, 'employee_number': 'PJA-260007', 'name': name},
  'approver': {'name': 'Eko Wibowo'},
  'is_manual': false,
  'submitted_at': '2026-11-16T09:12:00+07:00',
  'decided_at': null,
  'decided_by': null,
  'decision_note': null,
};

typedef ServerRoute = FakeReply Function(RequestOptions o);

/// Server per "METHOD path-akhir"; `/config`, `/me`, `attendance/today` selalu ada.
/// Alasan aktif dari `GET /lookup/correction-reasons` (sengaja beda dari seeder).
const reasonsJson = {
  'data': [
    {'code': 'forgot', 'name': 'Lupa presensi'},
    {'code': 'meeting', 'name': 'Rapat di luar kantor'},
    {'code': 'other', 'name': 'Lainnya'},
  ],
  'meta': {'request_id': '01JBX8Q6V7M3T2K9R4N5P6W8YZ'},
};

/// PNG 1x1 transparan (foto profil / lampiran palsu yang sah).
final pngBytes = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
);

FakeAdapter server(Map<String, ServerRoute> routes, {List<String> permissions = const [], bool hasAvatar = false}) =>
    FakeAdapter((o) {
      final user = {
        ...userJson(),
        'has_avatar': hasAvatar,
        'permissions': ['attendance.self', 'attendance_corrections.create', ...permissions],
      };
      if (o.path.endsWith('/api/v1/config')) {
        return FakeReply(200, configJson());
      }
      for (final entry in routes.entries) {
        final [method, suffix] = entry.key.split(' ');
        if (o.method == method && o.path.endsWith(suffix)) {
          return entry.value(o);
        }
      }
      if (o.path.endsWith('/me')) {
        return FakeReply(200, {'data': user, 'meta': <String, Object?>{}});
      }
      if (o.path.endsWith('/lookup/correction-reasons')) {
        return const FakeReply(200, reasonsJson);
      }
      if (o.path.endsWith('/notifications')) {
        return FakeReply(200, listing('my_notifications', const []));
      }
      return FakeReply(404, errorJson('NOT_FOUND'));
    });

MemorySecureStore signedIn({List<String> permissions = const [], bool hasAvatar = false}) => MemorySecureStore({
  StoreKeys.authToken: '12|tokenuji',
  StoreKeys.authUser: jsonEncode({
    ...userJson(),
    'has_avatar': hasAvatar,
    'permissions': ['attendance.self', 'attendance_corrections.create', ...permissions],
  }),
});

Map<String, String> formFields(RequestOptions o) => {for (final f in (o.data! as FormData).fields) f.key: f.value};

Future<void> openTab(WidgetTester tester, String label) async {
  await tester.tap(find.descendant(of: find.byType(NavigationBar), matching: find.text(label)));
  await tester.pumpAndSettle();
}

void main() {
  group('tab bawah (07 §5)', () {
    testWidgets('kriteria T-043: tanpa attendance_corrections.approve → tidak ada tab Persetujuan', (tester) async {
      await pumpPakisPro(tester, adapter: server({}), store: signedIn());

      expect(find.byType(HomeScreen), findsOneWidget);
      final bar = find.byType(NavigationBar);
      for (final label in ['Presensi', 'Riwayat', 'Koreksi', 'Profil']) {
        expect(find.descendant(of: bar, matching: find.text(label)), findsOneWidget);
      }
      expect(find.text('Persetujuan'), findsNothing);
      expect(find.byType(NotificationBell), findsOneWidget);
    });

    testWidgets('pemegang approve melihat tab Persetujuan; tinggi tab ≥ 52 dp', (tester) async {
      final permissions = ['attendance_corrections.approve'];
      await pumpPakisPro(
        tester,
        adapter: server({}, permissions: permissions),
        store: signedIn(permissions: permissions),
      );

      expect(find.descendant(of: find.byType(NavigationBar), matching: find.text('Persetujuan')), findsOneWidget);
      expect(tester.getSize(find.byType(NavigationBar)).height, greaterThanOrEqualTo(52));
    });

    testWidgets('daftar tidak dimuat sebelum tabnya dibuka', (tester) async {
      final adapter = server({});
      await pumpPakisPro(tester, adapter: adapter, store: signedIn());

      expect(adapter.requests.where((o) => o.path.endsWith('/attendance-corrections')), isEmpty);
      expect(adapter.requests.where((o) => o.method == 'GET' && o.path.endsWith('/api/v1/attendance')), isEmpty);
    });
  });

  group('Riwayat (05 §5.5, §5.6)', () {
    testWidgets('daftar paging + rekap bulan mengikuti filter bulan dari server', (tester) async {
      final adapter = server({
        'GET /api/v1/attendance': (_) => FakeReply(
          200,
          listing(
            'my_attendance_days',
            [
              {
                'id': 1,
                'work_date': '2026-11-03',
                'status': 'late',
                'shift': {'code': 'SLS', 'name': 'Sales', 'start': '07:30', 'end': '16:30'},
                'check_in_at': '2026-11-03T07:37:02+07:00',
                'check_out_at': '2026-11-03T16:35:00+07:00',
                'late_minutes': 7,
                'early_leave_minutes': 0,
                'is_offline': true,
                'is_corrected': false,
              },
            ],
            filters: {'month': '2026-11'},
            sort: '-work_date',
            specFilters: [
              {
                'key': 'month',
                'type': 'select',
                'label': 'Bulan',
                'required': true,
                'options': [
                  {'value': '2026-11', 'label': 'November 2026'},
                ],
              },
              {
                'key': 'status',
                'type': 'multi_select',
                'label': 'Status',
                'options': [
                  {'value': 'late', 'label': 'Telat'},
                ],
              },
            ],
          ),
        ),
        'GET /attendance/summary': (o) => FakeReply(200, {
          'data': {
            'month': o.queryParameters['month'],
            'scheduled_days': 20,
            'present': 18,
            'late': 3,
            'early_leave': 0,
            'no_checkout': 0,
            'absent': 2,
            'holiday': 1,
            'off': 8,
            'late_minutes_total': 21,
            'corrections_pending': 1,
          },
        }),
      });
      await pumpPakisPro(tester, adapter: adapter, store: signedIn());
      await openTab(tester, 'Riwayat');

      expect(find.text('Sales · 07.37 → 16.35'), findsNothing, reason: 'baris kedua memuat catatan');
      expect(find.textContaining('Sales · 07.37 → 16.35'), findsOneWidget);
      expect(find.textContaining('telat 7 mnt, dikirim offline'), findsOneWidget);
      expect(find.text('REKAP NOVEMBER 2026'), findsOneWidget);
      expect(find.text('18'), findsOneWidget);
      expect(find.textContaining('Total telat 21 menit · 1 koreksi menunggu'), findsOneWidget);
      final summary = adapter.requests.singleWhere((o) => o.path.endsWith('/attendance/summary'));
      expect(summary.queryParameters['month'], '2026-11');
      expect(find.byTooltip('Filter & urutan'), findsOneWidget, reason: 'FilterSheet dari spec');
    });
  });

  group('Koreksi saya (05 §5.7)', () {
    testWidgets('form: validasi di HP, lalu kirim multipart + lampiran foto → daftar dimuat ulang', (tester) async {
      var listCalls = 0;
      RequestOptions? posted;
      final adapter = server({
        'GET /attendance-corrections': (_) {
          listCalls++;
          return FakeReply(200, listing('my_corrections', listCalls > 1 ? [correctionJson()] : const []));
        },
        'POST /attendance-corrections': (o) {
          posted = o;
          return FakeReply(201, {'data': correctionJson(), 'meta': <String, Object?>{}});
        },
      });
      await pumpPakisPro(
        tester,
        adapter: adapter,
        store: signedIn(),
        overrides: [
          attachmentCaptureProvider.overrideWithValue((context, maxBytes) async {
            expect(maxBytes, 500 * 1024, reason: 'config 2048 KB dibatasi 500 KB di HP');
            return Uint8List.fromList(List.filled(40 * 1024, 9));
          }),
        ],
      );
      await openTab(tester, 'Koreksi');
      expect(find.text('Belum ada koreksi'), findsOneWidget);

      await tester.tap(find.text('Ajukan koreksi'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Kirim Koreksi'));
      await tester.pumpAndSettle();

      expect(find.text('Jam masuk wajib diisi.'), findsOneWidget);
      expect(find.text('Pilih alasan koreksi.'), findsOneWidget);
      expect(find.text('Keterangan minimal 10 karakter.'), findsOneWidget);
      expect(posted, isNull);

      await tester.tap(find.text('Pilih jam'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('OKE'));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Lupa presensi').last);
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextField, 'Keterangan'), 'Lupa menekan Masuk karena rapat');
      await tester.tap(find.text('Foto'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Lampiran foto siap (40 KB)'), findsOneWidget);

      await tester.tap(find.text('Kirim Koreksi'));
      await tester.pumpAndSettle();

      final fields = formFields(posted!);
      expect(fields['type'], 'check_in');
      expect(fields['proposed_check_in'], '08:00');
      expect(fields.containsKey('proposed_check_out'), isFalse);
      expect(fields['reason_code'], 'forgot');
      expect(fields['note'], 'Lupa menekan Masuk karena rapat');
      expect(fields['work_date'], matches(RegExp(r'^\d{4}-\d{2}-\d{2}$')));
      expect((posted!.data! as FormData).files.single.key, 'attachment');

      expect(find.textContaining('Koreksi KOR-202611-0012 terkirim'), findsOneWidget);
      expect(find.textContaining('KOR-202611-0012'), findsWidgets, reason: 'daftar dimuat ulang');
    });

    testWidgets('galat server per field tampil di bawah isian (BR-060)', (tester) async {
      final adapter = server({
        'GET /attendance-corrections': (_) => FakeReply(200, listing('my_corrections', const [])),
        'POST /attendance-corrections': (_) => FakeReply(
          422,
          errorJson(
            'VALIDATION_FAILED',
            details: {
              'proposed_check_in': ['Jam masuk di luar jendela shift.'],
            },
          ),
        ),
      });
      await pumpPakisPro(tester, adapter: adapter, store: signedIn());
      await openTab(tester, 'Koreksi');
      await tester.tap(find.text('Ajukan koreksi'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Pilih jam'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('OKE'));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Lainnya').last);
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextField, 'Keterangan'), 'Jam masuk tercatat salah');
      await tester.tap(find.text('Kirim Koreksi'));
      await tester.pumpAndSettle();

      expect(find.text('Jam masuk di luar jendela shift.'), findsOneWidget);
    });

    testWidgets('detail pending → Batalkan Koreksi → POST cancel', (tester) async {
      final adapter = server({
        'GET /attendance-corrections': (_) => FakeReply(200, listing('my_corrections', [correctionJson()])),
        'POST /12/cancel': (_) => FakeReply(200, {'data': correctionJson(status: 'cancelled')}),
      });
      await pumpPakisPro(tester, adapter: adapter, store: signedIn());
      await openTab(tester, 'Koreksi');
      await tester.tap(find.textContaining('Jam masuk').first);
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(OutlinedButton, 'Batalkan Koreksi'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Batalkan Koreksi'));
      await tester.pumpAndSettle();

      expect(adapter.requests.where((o) => o.path.endsWith('/attendance-corrections/12/cancel')), hasLength(1));
      expect(find.text('Koreksi dibatalkan.'), findsOneWidget);
    });
  });

  group('Persetujuan (05 §5.8)', () {
    const permissions = ['attendance_corrections.approve'];

    testWidgets('Tolak: alasan wajib (≥ 5) → POST reject dengan note', (tester) async {
      RequestOptions? rejected;
      final adapter = server({
        'GET /approvals/attendance-corrections': (_) => FakeReply(
          200,
          listing(
            'approval_corrections',
            [correctionJson()],
            filters: {
              'status': ['pending'],
            },
          ),
        ),
        'POST /12/reject': (o) {
          rejected = o;
          return FakeReply(200, {'data': correctionJson(status: 'rejected')});
        },
      }, permissions: permissions);
      await pumpPakisPro(
        tester,
        adapter: adapter,
        store: signedIn(permissions: permissions),
      );
      await openTab(tester, 'Persetujuan');

      expect(find.text('Bayu Saputra'), findsOneWidget);
      await tester.tap(find.text('Bayu Saputra'));
      await tester.pumpAndSettle();
      expect(find.text('Masuk 07.55'), findsNothing);
      expect(find.text('07.55'), findsOneWidget);

      await tester.tap(find.widgetWithText(OutlinedButton, 'Tolak'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Tolak'));
      await tester.pumpAndSettle();
      expect(find.text('Alasan penolakan wajib diisi, minimal 5 karakter.'), findsOneWidget);
      expect(rejected, isNull);

      await tester.enterText(find.widgetWithText(TextField, 'Alasan penolakan'), 'Tidak ada bukti');
      await tester.tap(find.widgetWithText(FilledButton, 'Tolak'));
      await tester.pumpAndSettle();

      expect((rejected!.data! as Map)['note'], 'Tidak ada bukti');
      expect(find.text('Koreksi ditolak.'), findsOneWidget);
    });

    testWidgets('Setujui saat sudah diputuskan orang lain → 409 → pesan + kembali ke daftar', (tester) async {
      final adapter = server({
        'GET /approvals/attendance-corrections': (_) =>
            FakeReply(200, listing('approval_corrections', [correctionJson()])),
        'POST /12/approve': (_) => FakeReply(409, errorJson('STATE_CONFLICT')),
      }, permissions: permissions);
      await pumpPakisPro(
        tester,
        adapter: adapter,
        store: signedIn(permissions: permissions),
      );
      await openTab(tester, 'Persetujuan');
      await tester.tap(find.text('Bayu Saputra'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Setujui'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Setujui').last);
      await tester.pumpAndSettle();

      expect(find.textContaining('sudah diputuskan atau dibatalkan'), findsOneWidget);
      expect(find.widgetWithText(OutlinedButton, 'Tolak'), findsNothing, reason: 'kembali ke daftar');
    });
  });

  group('Notifikasi (05 §5.9)', () {
    testWidgets('lonceng = jumlah belum dibaca; ketuk item → tandai dibaca; tandai semua', (tester) async {
      final adapter = server({
        'GET /notifications': (o) => FakeReply(
          200,
          o.queryParameters['filter[read]'] == 'unread'
              ? {
                  'data': const <Object?>[],
                  'meta': {
                    'pagination': {'page': 1, 'per_page': 20, 'total': 3, 'last_page': 1},
                  },
                }
              : listing('my_notifications', [
                  {
                    'id': '9b1f0c1e-0000-4000-8000-000000000001',
                    'type': 'correction_decided',
                    'title': 'Koreksi disetujui',
                    'body': 'Koreksi KOR-202611-0001 disetujui.',
                    'read': false,
                    'created_at': '2026-11-16T09:12:00+07:00',
                  },
                ]),
        ),
        'POST /read': (o) => FakeReply(200, {'data': <String, Object?>{}}),
        'POST /read-all': (_) => const FakeReply(200, {
          'data': {'marked': 2},
        }),
      });
      await pumpPakisPro(tester, adapter: adapter, store: signedIn());

      expect(find.byTooltip('Notifikasi, 3 belum dibaca'), findsOneWidget);
      await tester.tap(find.byType(NotificationBell));
      await tester.pumpAndSettle();
      expect(find.byType(NotificationsScreen), findsOneWidget);

      await tester.tap(find.text('Koreksi disetujui'));
      await tester.pumpAndSettle();
      expect(
        adapter.requests.where((o) => o.path.endsWith('/notifications/9b1f0c1e-0000-4000-8000-000000000001/read')),
        hasLength(1),
      );
      await tester.tapAt(const Offset(180, 100)); // tutup lembar
      await tester.pumpAndSettle();

      await tester.tap(find.byTooltip('Tandai semua dibaca'));
      await tester.pumpAndSettle();
      expect(find.text('2 notifikasi ditandai dibaca.'), findsOneWidget);
    });
  });

  group('Profil', () {
    testWidgets('ubah nomor HP mengirim password saat ini; keluar dari sesi web lain', (tester) async {
      RequestOptions? put;
      final adapter = server({
        'PUT /me': (o) {
          put = o;
          return FakeReply(200, {'data': userJson()});
        },
        'POST /sessions/revoke-others': (_) => const FakeReply(200, {
          'data': {'revoked': 2},
        }),
      });
      await pumpPakisPro(tester, adapter: adapter, store: signedIn());
      await openTab(tester, 'Profil');

      expect(find.text('agus.s'), findsOneWidget);
      await tester.tap(find.text('Ubah nomor HP'));
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextField, 'Nomor HP'), '6289876543210');
      await tester.enterText(find.widgetWithText(TextField, 'Password saat ini'), 'kopi pagi di gudang');
      await tester.tap(find.widgetWithText(FilledButton, 'Simpan'));
      await tester.pumpAndSettle();

      expect((put!.data! as Map)['phone'], '6289876543210');
      expect((put!.data! as Map)['current_password'], 'kopi pagi di gudang');
      expect(find.text('Nomor HP diperbarui.'), findsOneWidget);

      await tester.scrollUntilVisible(find.text('Keluar dari sesi web lain'), 120);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Keluar dari sesi web lain'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Keluarkan'));
      await tester.pumpAndSettle();
      expect(find.text('2 sesi web dikeluarkan.'), findsOneWidget);
    });
  });

  group('keputusan Mas 16 Nov 2026: lookup alasan, lampiran, foto profil', () {
    testWidgets('alasan dari GET /lookup/correction-reasons (bukan salinan seeder)', (tester) async {
      final adapter = server({
        'GET /attendance-corrections': (_) => FakeReply(200, listing('my_corrections', const [])),
      });
      await pumpPakisPro(tester, adapter: adapter, store: signedIn());
      await openTab(tester, 'Koreksi');
      await tester.tap(find.text('Ajukan koreksi'));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();

      expect(find.text('Rapat di luar kantor'), findsWidgets);
      expect(find.text('HP rusak'), findsNothing, reason: 'tidak ada daftar hardcode');
      final lookup = adapter.requests.singleWhere((o) => o.path.endsWith('/lookup/correction-reasons'));
      expect(lookup.headers['Authorization'], 'Bearer 12|tokenuji');
    });

    testWidgets('lookup alasan gagal → pesan + Coba Lagi (tanpa daftar cadangan) → berhasil', (tester) async {
      var fail = true;
      final adapter = server({
        'GET /attendance-corrections': (_) => FakeReply(200, listing('my_corrections', const [])),
        'GET /lookup/correction-reasons': (_) => fail ? const FakeReply.offline() : const FakeReply(200, reasonsJson),
      });
      await pumpPakisPro(tester, adapter: adapter, store: signedIn());
      await openTab(tester, 'Koreksi');
      await tester.tap(find.text('Ajukan koreksi'));
      await tester.pumpAndSettle();

      expect(find.textContaining('Daftar alasan gagal dimuat.'), findsOneWidget);
      expect(find.byType(DropdownButtonFormField<String>), findsNothing);

      fail = false;
      await tester.tap(find.widgetWithText(TextButton, 'Coba Lagi'));
      await tester.pumpAndSettle();
      expect(find.byType(DropdownButtonFormField<String>), findsOneWidget);
    });

    testWidgets('detail koreksi: Lihat lampiran → foto layar penuh; PDF → arahan ke web', (tester) async {
      var pdf = false;
      final adapter = server({
        'GET /attendance-corrections': (_) => FakeReply(
          200,
          listing('my_corrections', [
            {...correctionJson(), 'has_attachment': true},
          ]),
        ),
        'GET /12/attachment': (_) => pdf
            ? FakeReply.bytes(utf8.encode('%PDF-1.4'), contentType: 'application/pdf')
            : FakeReply.bytes(pngBytes, contentType: 'image/png'),
      });
      await pumpPakisPro(tester, adapter: adapter, store: signedIn());
      await openTab(tester, 'Koreksi');
      await tester.tap(find.textContaining('Jam masuk').first);
      await tester.pumpAndSettle();

      await tester.tap(find.text('Lihat lampiran'));
      await tester.pumpAndSettle();
      expect(find.byType(AttachmentImageScreen), findsOneWidget);
      expect(find.text('Lampiran KOR-202611-0012'), findsOneWidget);
      final request = adapter.requests.lastWhere((o) => o.path.endsWith('/attendance-corrections/12/attachment'));
      expect(request.headers['X-Device-Id'], isNotNull);

      await tester.tap(find.byType(CloseButton)); // layar penuh = tombol tutup
      await tester.pumpAndSettle();
      pdf = true;
      await tester.tap(find.text('Lihat lampiran'));
      await tester.pumpAndSettle();
      expect(find.byType(AttachmentImageScreen), findsNothing);
      expect(find.text('Lampiran PDF — buka di PAKIS ONE web.'), findsOneWidget);
    });

    testWidgets('lampiran 404 → pesan, tidak membuka layar', (tester) async {
      final adapter = server({
        'GET /attendance-corrections': (_) => FakeReply(
          200,
          listing('my_corrections', [
            {...correctionJson(), 'has_attachment': true},
          ]),
        ),
        'GET /12/attachment': (_) => FakeReply(404, errorJson('NOT_FOUND')),
      });
      await pumpPakisPro(tester, adapter: adapter, store: signedIn());
      await openTab(tester, 'Koreksi');
      await tester.tap(find.textContaining('Jam masuk').first);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Lihat lampiran'));
      await tester.pumpAndSettle();

      expect(find.text('Lampiran tidak ditemukan.'), findsOneWidget);
      expect(find.byType(AttachmentImageScreen), findsNothing);
    });

    testWidgets('has_avatar → foto dari GET /me/avatar di header & profil; ganti foto → dimuat ulang', (tester) async {
      final adapter = server({
        'GET /me/avatar': (_) => FakeReply.bytes(pngBytes, contentType: 'image/png'),
        'POST /me': (_) => FakeReply(200, {'data': userJson()}),
      }, hasAvatar: true);
      await pumpPakisPro(
        tester,
        adapter: adapter,
        store: signedIn(hasAvatar: true),
        overrides: [selfieCaptureProvider.overrideWithValue((context) async => Uint8List.fromList(pngBytes))],
      );

      bool allPhotos() =>
          tester.widgetList<CircleAvatar>(find.byType(CircleAvatar)).every((a) => a.backgroundImage != null);
      expect(find.byType(CircleAvatar), findsOneWidget);
      expect(allPhotos(), isTrue, reason: 'header');
      expect(find.text('AS'), findsNothing);

      await openTab(tester, 'Profil');
      expect(find.byType(CircleAvatar), findsNWidgets(2));
      expect(allPhotos(), isTrue, reason: 'header + profil');
      final before = adapter.requests.where((o) => o.path.endsWith('/me/avatar')).length;

      await tester.tap(find.text('Ganti foto profil'));
      await tester.pumpAndSettle();
      final upload = adapter.requests.lastWhere((o) => o.method == 'POST' && o.path.endsWith('/api/v1/me'));
      expect(formFields(upload)['_method'], 'PUT');
      expect(adapter.requests.where((o) => o.path.endsWith('/me/avatar')).length, greaterThan(before));
      expect(find.text('Foto profil diperbarui.'), findsOneWidget);
    });

    testWidgets('tanpa foto → inisial, tanpa request /me/avatar', (tester) async {
      final adapter = server({});
      await pumpPakisPro(tester, adapter: adapter, store: signedIn());

      expect(find.text('AS'), findsOneWidget);
      expect(adapter.requests.where((o) => o.path.endsWith('/me/avatar')), isEmpty);
    });
  });
}
