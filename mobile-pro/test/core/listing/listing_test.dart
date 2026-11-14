import 'package:flutter_test/flutter_test.dart';
import 'package:pakis_pro/core/api/api_client.dart';
import 'package:pakis_pro/core/listing/listing.dart';

/// Spec contoh berbentuk `ListingSpec::toArray()` backend.
final spec = ListingSpec.fromJson({
  'key': 'attendance_days',
  'search_placeholder': 'Cari tanggal',
  'per_page_options': [20, 50],
  'default_sort': '-work_date',
  'sorts': [
    {'key': 'work_date', 'label': 'Tanggal'},
  ],
  'filters': [
    {
      'key': 'work_date',
      'type': 'date_range',
      'label': 'Tanggal',
      'default': {'from': '2026-11-01', 'to': '2026-11-30'},
    },
    {
      'key': 'status',
      'type': 'multi_select',
      'label': 'Status',
      'options': [
        {'value': 'late', 'label': 'Telat'},
        {'value': 'absent', 'label': 'Tidak hadir'},
      ],
    },
    {'key': 'is_corrected', 'type': 'boolean', 'label': 'Dikoreksi'},
    {'key': 'location_id', 'type': 'select', 'label': 'Lokasi', 'options_source': 'locations'},
    {'key': 'baru', 'type': 'tipe_masa_depan', 'label': 'Baru'},
  ],
  'exportable': false,
});

void main() {
  test('halaman pertama tanpa spec: hanya per_page (server menerapkan default)', () {
    expect(const ListingQuery().toQueryParameters(), {'per_page': '20'});
  });

  test('menyandikan semua tipe filter seperti web (05 §3.1)', () {
    const query = ListingQuery(
      q: '  agus  ',
      sort: 'work_date',
      page: 2,
      filters: {
        'work_date': {'from': '2026-11-01', 'to': ''},
        'status': ['late', 'absent'],
        'is_corrected': true,
      },
    );

    expect(query.toQueryParameters(spec), {
      'q': 'agus',
      'sort': 'work_date',
      'page': '2',
      'per_page': '20',
      'filter[work_date][from]': '2026-11-01',
      'filter[status][]': ['late', 'absent'],
      'filter[is_corrected]': '1',
    });
  });

  test('sort bawaan tidak dikirim', () {
    expect(const ListingQuery(sort: '-work_date').toQueryParameters(spec).containsKey('sort'), isFalse);
  });

  test('filter ber-default yang dikosongkan dikirim kosong agar default tidak dipakai lagi (BR-003)', () {
    const query = ListingQuery(filters: {'work_date': null, 'status': <String>[]});

    final params = query.toQueryParameters(spec);
    expect(params['filter[work_date]'], '');
    expect(params.containsKey('filter[status]'), isFalse, reason: 'tanpa default: cukup tidak dikirim');
  });

  test('spec: filter tanpa opsi (lookup) dan tipe tak dikenal tidak bisa diedit di HP', () {
    expect(spec.filter('status')!.editable, isTrue);
    expect(spec.filter('location_id')!.editable, isFalse);
    expect(spec.filter('baru')!.type, FilterType.unknown);
    expect(spec.filter('baru')!.editable, isFalse);
    expect(spec.defaultFilters, {
      'work_date': {'from': '2026-11-01', 'to': '2026-11-30'},
    });
  });

  test('membaca respons daftar 05 §3.3', () {
    final page = ListingPage<String>.fromResponse(
      const ApiResponse(
        [
          {'id': 1, 'name': 'a'},
          {'id': 2, 'name': 'b'},
        ],
        {
          'pagination': {'page': 1, 'per_page': 20, 'total': 23, 'last_page': 2},
          'listing': {
            'state': {
              'q': null,
              'sort': '-work_date',
              'filters': {
                'work_date': {'from': '2026-11-01', 'to': '2026-11-30'},
              },
            },
            'spec': {'key': 'attendance_days', 'filters': <Object?>[], 'sorts': <Object?>[]},
          },
        },
      ),
      (row) => row['name']! as String,
    );

    expect(page.items, ['a', 'b']);
    expect(page.total, 23);
    expect(page.hasMore, isTrue);
    expect(page.sort, '-work_date');
    expect(page.filters.keys, ['work_date']);
    expect(page.spec?.key, 'attendance_days');
  });
}
