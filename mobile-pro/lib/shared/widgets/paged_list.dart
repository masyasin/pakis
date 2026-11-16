import 'dart:async';

import 'package:material_ui/material_ui.dart';

import '../../core/api/api_error.dart';
import '../../core/listing/listing.dart';
import 'empty_state.dart';
import 'filter_sheet.dart';

typedef ListingFetcher<T> = Future<ListingPage<T>> Function(ListingQuery query);

/// Daftar standar mobile (07 §5, CLAUDE.md §3): paging server (infinite scroll 20 per
/// halaman), pencarian, filter + urutan lewat [FilterSheet], chip filter aktif, tarik untuk
/// memuat ulang, serta keadaan kosong dan galat yang bisa ditindaklanjuti.
///
/// Spec filter/sort berasal dari respons server (`meta.listing.spec`), jadi layar daftar hanya
/// menyediakan [fetch] dan [itemBuilder].
class PagedList<T> extends StatefulWidget {
  const PagedList({
    required this.fetch,
    required this.itemBuilder,
    super.key,
    this.header,
    this.emptyTitle = 'Belum ada data',
    this.emptyMessage,
    this.searchable = true,
    this.searchDebounce = const Duration(milliseconds: 400),
    this.onLoaded,
  });

  final ListingFetcher<T> fetch;
  final Widget Function(BuildContext context, T item) itemBuilder;

  /// Mis. kartu rekap bulan di atas riwayat absen.
  final Widget? header;
  final String emptyTitle;
  final String? emptyMessage;
  final bool searchable;
  final Duration searchDebounce;

  /// Dipanggil setiap halaman pertama dimuat (cari/filter/tarik), dengan state filter efektif
  /// server, mis. agar rekap bulan di [header] mengikuti filter bulan riwayat.
  final void Function(ListingPage<T> page)? onLoaded;

  @override
  State<PagedList<T>> createState() => _PagedListState<T>();
}

class _PagedListState<T> extends State<PagedList<T>> {
  final _items = <T>[];
  final _search = TextEditingController();
  Timer? _debounce;

  ListingQuery _query = const ListingQuery();
  ListingSpec? _spec;
  bool _hasMore = false;
  int _total = 0;
  bool _loading = false;
  ApiError? _error;
  ApiError? _moreError;

  /// Menandai request terbaru: jawaban request lama (pencarian sebelumnya) diabaikan.
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  Future<void> _reload() => _load(_query.copyWith(page: 1), replace: true);

  Future<void> _loadMore() async {
    if (_loading || !_hasMore || _moreError != null) {
      return;
    }
    await _load(_query.copyWith(page: _query.page + 1), replace: false);
  }

  Future<void> _load(ListingQuery query, {required bool replace}) async {
    final generation = ++_generation;
    setState(() {
      _loading = true;
      if (replace) {
        _error = null;
      }
      _moreError = null;
    });

    try {
      final page = await widget.fetch(query);
      if (!mounted || generation != _generation) {
        return;
      }
      setState(() {
        _spec = page.spec ?? _spec;
        // Ambil state efektif server (default + filter sah) agar chip sesuai yang diterapkan.
        // Filter yang sengaja dikosongkan tetap diingat (dikirim `filter[x]=`), kalau tidak
        // halaman berikutnya akan memunculkan default server lagi.
        final cleared = {
          for (final e in query.filters.entries)
            if (isEmptyFilterValue(e.value)) e.key: null,
        };
        _query = query.copyWith(page: page.page, filters: {...cleared, ...page.filters}, sort: page.sort);
        if (replace) {
          _items.clear();
        }
        _items.addAll(page.items);
        _hasMore = page.hasMore;
        _total = page.total;
        _loading = false;
      });
      if (replace) {
        widget.onLoaded?.call(page);
      }
    } on ApiError catch (e) {
      if (!mounted || generation != _generation) {
        return;
      }
      setState(() {
        _loading = false;
        replace ? _error = e : _moreError = e;
      });
    }
  }

  void _onSearchChanged(String text) {
    _debounce?.cancel();
    _debounce = Timer(widget.searchDebounce, () {
      if (text.trim() != _query.q) {
        _query = _query.copyWith(q: text.trim());
        _reload();
      }
    });
  }

  Future<void> _openFilters() async {
    final spec = _spec;
    if (spec == null) {
      return;
    }
    final result = await showFilterSheet(context, spec: spec, filters: _query.filters, sort: _query.sort ?? '');
    if (result != null) {
      _query = _query.copyWith(filters: result.filters, sort: result.sort);
      await _reload();
    }
  }

  void _removeFilter(String key) {
    _query = _query.copyWith(filters: {..._query.filters, key: null});
    _reload();
  }

  void _clearAll() {
    _search.clear();
    _query = ListingQuery(
      filters: {
        for (final f in _spec?.filters ?? const <FilterSpec>[])
          if (!f.required) f.key: null,
      },
    );
    _reload();
  }

  List<(FilterSpec, String)> get _activeChips => [
    for (final f in _spec?.filters ?? const <FilterSpec>[])
      if (describeFilterValue(f, _query.filters[f.key]) case final text?) (f, text),
  ];

  bool get _isFiltered => _query.q.isNotEmpty || _activeChips.any((c) => !c.$1.required);

  @override
  Widget build(BuildContext context) {
    final chips = _activeChips;

    return Column(
      children: [
        _Toolbar(
          controller: _search,
          searchable: widget.searchable,
          placeholder: _spec?.searchPlaceholder ?? 'Cari',
          onSearchChanged: _onSearchChanged,
          filterCount: chips.length,
          onFilters: _spec == null || (_spec!.filters.every((f) => !f.editable) && _spec!.sorts.isEmpty)
              ? null
              : _openFilters,
        ),
        if (chips.isNotEmpty)
          SizedBox(
            height: 48,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 16),
              children: [
                for (final (spec, text) in chips)
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: InputChip(
                      label: Text('${spec.label}: $text'),
                      onDeleted: spec.required ? null : () => _removeFilter(spec.key),
                      deleteButtonTooltipMessage: 'Hapus filter ${spec.label}',
                    ),
                  ),
              ],
            ),
          ),
        Expanded(child: _body()),
      ],
    );
  }

  Widget _body() {
    if (_items.isEmpty) {
      if (_loading) {
        return const Center(child: CircularProgressIndicator());
      }
      final Widget state = _error != null
          ? EmptyState(
              icon: Icons.cloud_off_outlined,
              title: 'Gagal memuat data',
              message: _error!.message,
              actionLabel: 'Coba Lagi',
              onAction: _reload,
            )
          : EmptyState(
              title: widget.emptyTitle,
              message: _isFiltered ? 'Tidak ada yang cocok. Coba ubah pencarian atau filter.' : widget.emptyMessage,
              actionLabel: _isFiltered ? 'Hapus Filter' : null,
              onAction: _isFiltered ? _clearAll : null,
            );
      // Tetap bisa ditarik untuk memuat ulang.
      return RefreshIndicator(
        onRefresh: _reload,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            if (widget.header != null) widget.header!,
            SizedBox(height: 360, child: state),
          ],
        ),
      );
    }

    final headerCount = widget.header != null ? 1 : 0;
    return RefreshIndicator(
      onRefresh: _reload,
      child: NotificationListener<ScrollNotification>(
        onNotification: (n) {
          if (n.metrics.extentAfter < 400) {
            _loadMore();
          }
          return false;
        },
        child: ListView.builder(
          physics: const AlwaysScrollableScrollPhysics(),
          itemCount: headerCount + _items.length + 1,
          itemBuilder: (context, index) {
            if (index < headerCount) {
              return widget.header;
            }
            final i = index - headerCount;
            if (i < _items.length) {
              return widget.itemBuilder(context, _items[i]);
            }
            return _Footer(
              loading: _loading,
              error: _moreError,
              hasMore: _hasMore,
              total: _total,
              onRetry: () {
                setState(() => _moreError = null);
                _loadMore();
              },
            );
          },
        ),
      ),
    );
  }
}

class _Toolbar extends StatelessWidget {
  const _Toolbar({
    required this.controller,
    required this.searchable,
    required this.placeholder,
    required this.onSearchChanged,
    required this.filterCount,
    required this.onFilters,
  });

  final TextEditingController controller;
  final bool searchable;
  final String placeholder;
  final ValueChanged<String> onSearchChanged;
  final int filterCount;
  final VoidCallback? onFilters;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
    child: Row(
      children: [
        if (searchable)
          Expanded(
            child: TextField(
              controller: controller,
              onChanged: onSearchChanged,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(hintText: placeholder, prefixIcon: const Icon(Icons.search), isDense: true),
            ),
          )
        else
          const Spacer(),
        if (onFilters != null) ...[
          const SizedBox(width: 8),
          Badge(
            isLabelVisible: filterCount > 0,
            label: Text('$filterCount'),
            child: IconButton.outlined(tooltip: 'Filter & urutan', icon: const Icon(Icons.tune), onPressed: onFilters),
          ),
        ],
      ],
    ),
  );
}

class _Footer extends StatelessWidget {
  const _Footer({
    required this.loading,
    required this.error,
    required this.hasMore,
    required this.total,
    required this.onRetry,
  });

  final bool loading;
  final ApiError? error;
  final bool hasMore;
  final int total;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final Widget child;
    if (error != null) {
      child = Column(
        children: [
          Text(error!.message, textAlign: TextAlign.center),
          TextButton(onPressed: onRetry, child: const Text('Coba Lagi')),
        ],
      );
    } else if (loading || hasMore) {
      child = const SizedBox.square(dimension: 24, child: CircularProgressIndicator(strokeWidth: 2));
    } else {
      child = Text('$total data', style: Theme.of(context).textTheme.bodySmall);
    }
    return Padding(
      padding: const EdgeInsets.all(16),
      child: Center(child: child),
    );
  }
}
