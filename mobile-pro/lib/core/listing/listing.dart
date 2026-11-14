import '../api/api_client.dart';
import '../api/api_error.dart';

/// Model daftar API v1 (05 §3.3) dan penyandian parameter query (05 §3.1).
///
/// Aturan penyandian sama dengan web (`backend/resources/js/lib/listing-query.ts`), agar satu
/// `Listing` backend melayani web dan mobile tanpa kasus khusus.

enum FilterType {
  select,
  multiSelect,
  boolean,
  dateRange,
  numberRange,

  /// Tipe baru dari server yang belum dikenal aplikasi versi ini: diabaikan, tidak error.
  unknown;

  static FilterType parse(Object? value) => switch (value) {
    'select' => select,
    'multi_select' => multiSelect,
    'boolean' => boolean,
    'date_range' => dateRange,
    'number_range' => numberRange,
    _ => unknown,
  };
}

class ListingOption {
  const ListingOption(this.value, this.label);

  final String value;
  final String label;
}

class FilterSpec {
  const FilterSpec({
    required this.key,
    required this.type,
    required this.label,
    this.options = const [],
    this.optionsSource,
    this.required = false,
    this.defaultValue,
  });

  factory FilterSpec.fromJson(Map<String, Object?> json) => FilterSpec(
    key: json['key']! as String,
    type: FilterType.parse(json['type']),
    label: (json['label'] as String?) ?? json['key']! as String,
    options: [
      for (final o in (json['options'] as List?) ?? const <Object?>[])
        if (o is Map) ListingOption('${o['value']}', '${o['label']}'),
    ],
    optionsSource: json['options_source'] as String?,
    required: json['required'] == true,
    defaultValue: json['default'],
  );

  final String key;
  final FilterType type;
  final String label;
  final List<ListingOption> options;

  /// Opsi dari endpoint lookup. Belum ada lookup di API v1, jadi filter seperti ini
  /// tidak ditampilkan di lembar filter mobile (tetap bisa diterapkan lewat default server).
  final String? optionsSource;

  /// Tidak bisa dikosongkan, tanpa opsi "Semua" (`Filter::required()` backend).
  final bool required;
  final Object? defaultValue;

  bool get hasDefault => defaultValue != null;

  /// Bisa diubah dari lembar filter mobile.
  bool get editable =>
      type != FilterType.unknown && !((type == FilterType.select || type == FilterType.multiSelect) && options.isEmpty);

  String? optionLabel(Object? value) {
    for (final o in options) {
      if (o.value == '$value') {
        return o.label;
      }
    }
    return null;
  }
}

class SortSpec {
  const SortSpec(this.key, this.label);

  final String key;
  final String label;
}

class ListingSpec {
  const ListingSpec({
    required this.key,
    required this.searchPlaceholder,
    required this.perPageOptions,
    required this.defaultSort,
    required this.sorts,
    required this.filters,
  });

  factory ListingSpec.fromJson(Map<String, Object?> json) => ListingSpec(
    key: json['key']! as String,
    searchPlaceholder: (json['search_placeholder'] as String?) ?? 'Cari',
    perPageOptions: [
      for (final v in (json['per_page_options'] as List?) ?? const <Object?>[])
        if (v is int) v,
    ],
    defaultSort: (json['default_sort'] as String?) ?? '',
    sorts: [
      for (final s in (json['sorts'] as List?) ?? const <Object?>[])
        if (s is Map) SortSpec(s['key']! as String, s['label']! as String),
    ],
    filters: [
      for (final f in (json['filters'] as List?) ?? const <Object?>[])
        if (f is Map) FilterSpec.fromJson(f.cast<String, Object?>()),
    ],
  );

  final String key;
  final String searchPlaceholder;
  final List<int> perPageOptions;
  final String defaultSort;
  final List<SortSpec> sorts;
  final List<FilterSpec> filters;

  FilterSpec? filter(String key) {
    for (final f in filters) {
      if (f.key == key) {
        return f;
      }
    }
    return null;
  }

  /// Nilai filter bawaan, dipakai tombol "Atur ulang".
  Map<String, Object?> get defaultFilters => {
    for (final f in filters)
      if (f.hasDefault) f.key: f.defaultValue,
  };
}

/// Nilai filter kosong: null, '', [], atau rentang tanpa isi.
bool isEmptyFilterValue(Object? value) {
  if (value == null || value == '') {
    return true;
  }
  if (value is List) {
    return value.isEmpty;
  }
  if (value is Map) {
    return value.values.every((v) => v == null || v == '');
  }
  return false;
}

/// Permintaan satu halaman daftar.
class ListingQuery {
  const ListingQuery({this.q = '', this.sort, this.filters = const {}, this.page = 1, this.perPage = defaultPerPage});

  /// Opsi per halaman mobile `[20, 50]` (BR-001), 20 untuk infinite scroll (07 §5).
  static const defaultPerPage = 20;

  final String q;

  /// null = sort bawaan spec.
  final String? sort;
  final Map<String, Object?> filters;
  final int page;
  final int perPage;

  ListingQuery copyWith({String? q, String? sort, Map<String, Object?>? filters, int? page}) => ListingQuery(
    q: q ?? this.q,
    sort: sort ?? this.sort,
    filters: filters ?? this.filters,
    page: page ?? this.page,
    perPage: perPage,
  );

  /// Parameter query 05 §3.1, dengan kunci yang sudah memuat kurung (`filter[status][]`).
  ///
  /// Sebelum spec diketahui (halaman pertama), hanya filter yang diisi yang dikirim; server
  /// menerapkan default dan mengembalikan state efektif. Filter yang punya default lalu
  /// dikosongkan pengguna dikirim kosong (`filter[x]=`) agar server tidak menerapkan default
  /// lagi (BR-003).
  Map<String, Object> toQueryParameters([ListingSpec? spec]) {
    final params = <String, Object>{};
    final query = q.trim();
    if (query.isNotEmpty) {
      params['q'] = query;
    }
    if (sort != null && sort!.isNotEmpty && sort != spec?.defaultSort) {
      params['sort'] = sort!;
    }
    if (page > 1) {
      params['page'] = '$page';
    }
    params['per_page'] = '$perPage';

    final keys = {...?spec?.filters.map((f) => f.key), ...filters.keys};
    for (final key in keys) {
      final filter = spec?.filter(key);
      final value = filters[key];
      if (isEmptyFilterValue(value)) {
        if (filter != null && filter.hasDefault && filters.containsKey(key)) {
          params['filter[$key]'] = '';
        }
        continue;
      }
      _encode(params, key, filter?.type, value);
    }
    return params;
  }

  static void _encode(Map<String, Object> params, String key, FilterType? type, Object? value) {
    if (value is bool || type == FilterType.boolean) {
      params['filter[$key]'] = value == true || value == '1' || value == 'true' ? '1' : '0';
    } else if (value is List) {
      params['filter[$key][]'] = [for (final v in value) '$v'];
    } else if (value is Map) {
      for (final entry in value.entries) {
        if (entry.value != null && entry.value != '') {
          params['filter[$key][${entry.key}]'] = '${entry.value}';
        }
      }
    } else {
      params['filter[$key]'] = '$value';
    }
  }
}

/// Satu halaman hasil daftar (05 §3.3).
class ListingPage<T> {
  const ListingPage({
    required this.items,
    required this.page,
    required this.perPage,
    required this.total,
    required this.lastPage,
    this.spec,
    this.sort,
    this.filters = const {},
  });

  /// Dari respons API daftar: `data` = baris, `meta.pagination`, `meta.listing.{state,spec}`.
  factory ListingPage.fromResponse(ApiResponse response, T Function(Map<String, Object?> row) parse) {
    final rows = response.data;
    final pagination = response.meta['pagination'];
    final listing = response.meta['listing'];
    if (rows is! List || pagination is! Map) {
      throw const ApiError(code: ApiErrorCode.unknown);
    }
    final state = listing is Map ? listing['state'] : null;
    final spec = listing is Map ? listing['spec'] : null;
    final filters = state is Map ? state['filters'] : null;

    return ListingPage(
      items: [
        for (final row in rows)
          if (row is Map) parse(row.cast<String, Object?>()),
      ],
      page: (pagination['page'] as int?) ?? 1,
      perPage: (pagination['per_page'] as int?) ?? ListingQuery.defaultPerPage,
      total: (pagination['total'] as int?) ?? 0,
      lastPage: (pagination['last_page'] as int?) ?? 1,
      spec: spec is Map ? ListingSpec.fromJson(spec.cast<String, Object?>()) : null,
      sort: state is Map ? state['sort'] as String? : null,
      filters: filters is Map ? filters.cast<String, Object?>() : const {},
    );
  }

  final List<T> items;
  final int page;
  final int perPage;
  final int total;
  final int lastPage;
  final ListingSpec? spec;

  /// Sort & filter efektif menurut server (sudah termasuk default dan tanpa nilai tidak sah).
  final String? sort;
  final Map<String, Object?> filters;

  bool get hasMore => page < lastPage;
}
