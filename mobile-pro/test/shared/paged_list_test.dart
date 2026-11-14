import 'package:flutter_test/flutter_test.dart';
import 'package:material_ui/material_ui.dart';
import 'package:pakis_pro/core/api/api_error.dart';
import 'package:pakis_pro/core/format.dart';
import 'package:pakis_pro/core/listing/listing.dart';
import 'package:pakis_pro/core/theme/app_theme.dart';
import 'package:pakis_pro/shared/widgets/paged_list.dart';

final _spec = ListingSpec.fromJson({
  'key': 'demo',
  'search_placeholder': 'Cari nama',
  'per_page_options': [20, 50],
  'default_sort': 'name',
  'sorts': [
    {'key': 'name', 'label': 'Nama'},
  ],
  'filters': [
    {
      'key': 'status',
      'type': 'select',
      'label': 'Status',
      'options': [
        {'value': 'late', 'label': 'Telat'},
        {'value': 'absent', 'label': 'Tidak hadir'},
      ],
    },
    {
      'key': 'month',
      'type': 'date_range',
      'label': 'Bulan',
      'default': {'from': '2026-11-01', 'to': '2026-11-30'},
    },
  ],
});

/// Server palsu: 45 baris, filter `status` dan pencarian `q` diterapkan, default bulan ditambahkan.
class _FakeServer {
  final queries = <ListingQuery>[];
  ApiError? failWith;

  Future<ListingPage<String>> fetch(ListingQuery query) async {
    queries.add(query);
    final error = failWith;
    if (error != null) {
      throw error;
    }
    var rows = [for (var i = 1; i <= 45; i++) 'Pegawai $i'];
    if (query.q.isNotEmpty) {
      rows = rows.where((r) => r.contains(query.q)).toList();
    }
    if (query.filters['status'] == 'absent') {
      rows = [];
    }
    final filters = <String, Object?>{
      if (!query.filters.containsKey('month')) 'month': {'from': '2026-11-01', 'to': '2026-11-30'},
      for (final e in query.filters.entries)
        if (!isEmptyFilterValue(e.value)) e.key: e.value,
    };
    final start = (query.page - 1) * query.perPage;
    return ListingPage(
      items: rows.skip(start).take(query.perPage).toList(),
      page: query.page,
      perPage: query.perPage,
      total: rows.length,
      lastPage: rows.isEmpty ? 1 : (rows.length / query.perPage).ceil(),
      spec: _spec,
      sort: query.sort ?? 'name',
      filters: filters,
    );
  }
}

Future<_FakeServer> _pump(WidgetTester tester, {_FakeServer? server}) async {
  await Fmt.init();
  // Ukuran HP sungguhan (360×800 dp), bukan kanvas uji bawaan 800×600.
  tester.view
    ..physicalSize = const Size(1080, 2400)
    ..devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  final s = server ?? _FakeServer();
  await tester.pumpWidget(
    MaterialApp(
      theme: AppTheme.build(Brightness.light),
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: Scaffold(
        body: PagedList<String>(
          fetch: s.fetch,
          itemBuilder: (_, item) => ListTile(title: Text(item)),
          emptyTitle: 'Belum ada absen',
          searchDebounce: const Duration(milliseconds: 10),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return s;
}

void main() {
  testWidgets('halaman pertama 20 baris, gulir → halaman berikutnya (infinite scroll)', (tester) async {
    final server = await _pump(tester);

    expect(find.text('Pegawai 1'), findsOneWidget);
    expect(server.queries.single.page, 1);
    expect(server.queries.single.perPage, 20);

    await tester.dragUntilVisible(find.text('Pegawai 25'), find.byType(ListView).last, const Offset(0, -300));
    await tester.pumpAndSettle();
    expect(server.queries.map((q) => q.page), containsAll([1, 2]));
  });

  testWidgets('filter default dari server tampil sebagai chip', (tester) async {
    await _pump(tester);

    expect(find.text('Bulan: 1 Nov 2026 – 30 Nov 2026'), findsOneWidget);
  });

  testWidgets('pencarian mengirim q dan memuat ulang dari halaman 1', (tester) async {
    final server = await _pump(tester);

    await tester.enterText(find.byType(TextField), 'Pegawai 4');
    await tester.pump(const Duration(milliseconds: 20));
    await tester.pumpAndSettle();

    expect(server.queries.last.q, 'Pegawai 4');
    expect(server.queries.last.page, 1);
    expect(find.text('Pegawai 41'), findsOneWidget);
    expect(find.text('Pegawai 1'), findsNothing);
  });

  testWidgets('lembar filter → terapkan → param filter terkirim + chip; hapus chip default tetap kosong', (
    tester,
  ) async {
    final server = await _pump(tester);

    await tester.tap(find.byTooltip('Filter & urutan'));
    await tester.pumpAndSettle();
    expect(find.text('Filter & urutan'), findsOneWidget);
    await tester.tap(find.text('Telat'));
    await tester.tap(find.text('Terapkan Filter'));
    await tester.pumpAndSettle();

    expect(server.queries.last.filters['status'], 'late');
    expect(find.text('Status: Telat'), findsOneWidget);

    // Hapus chip bulan (ber-default): request berikutnya mengirim filter[month]= (kosong).
    await tester.ensureVisible(find.byTooltip('Hapus filter Bulan')); // baris chip digulir horizontal
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Hapus filter Bulan'));
    await tester.pumpAndSettle();
    expect(server.queries.last.toQueryParameters(_spec)['filter[month]'], '');
    expect(find.textContaining('Bulan:'), findsNothing);

    // Halaman berikutnya tidak memunculkan default lagi.
    final removedAt = server.queries.length;
    await tester.dragUntilVisible(find.text('Pegawai 25'), find.byType(ListView).last, const Offset(0, -300));
    await tester.pumpAndSettle();
    final later = server.queries.skip(removedAt).toList();
    expect(later.map((q) => q.page), contains(2));
    for (final q in later) {
      expect(q.toQueryParameters(_spec)['filter[month]'], '', reason: 'halaman ${q.page}');
    }
  });

  testWidgets('hasil kosong karena filter → pesan + tombol Hapus Filter', (tester) async {
    final server = await _pump(tester);

    await tester.tap(find.byTooltip('Filter & urutan'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Tidak hadir'));
    await tester.tap(find.text('Terapkan Filter'));
    await tester.pumpAndSettle();

    expect(find.text('Belum ada absen'), findsOneWidget);
    expect(find.text('Tidak ada yang cocok. Coba ubah pencarian atau filter.'), findsOneWidget);

    await tester.tap(find.text('Hapus Filter'));
    await tester.pumpAndSettle();
    expect(server.queries.last.filters['status'], isNull);
    expect(find.text('Pegawai 1'), findsOneWidget);
  });

  testWidgets('galat → pesan Indonesia + Coba Lagi', (tester) async {
    final server = _FakeServer()..failWith = const ApiError(code: ApiErrorCode.networkOffline);
    await _pump(tester, server: server);

    expect(find.text('Gagal memuat data'), findsOneWidget);
    expect(find.text('Tidak ada koneksi ke server. Periksa data seluler atau Wi-Fi.'), findsOneWidget);

    server.failWith = null;
    await tester.tap(find.text('Coba Lagi'));
    await tester.pumpAndSettle();
    expect(find.text('Pegawai 1'), findsOneWidget);
  });

  testWidgets('urutan dari lembar filter dikirim sebagai sort', (tester) async {
    final server = await _pump(tester);

    await tester.tap(find.byTooltip('Filter & urutan'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Turun'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Turun'));
    await tester.tap(find.text('Terapkan Filter'));
    await tester.pumpAndSettle();

    expect(server.queries.last.sort, '-name');
    expect(server.queries.last.toQueryParameters(_spec)['sort'], '-name');
  });
}
