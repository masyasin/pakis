import 'package:material_ui/material_ui.dart';

import '../../core/format.dart';
import '../../core/listing/listing.dart';

/// Hasil lembar filter: filter + sort baru.
class FilterSheetResult {
  const FilterSheetResult({required this.filters, required this.sort});

  final Map<String, Object?> filters;
  final String sort;
}

/// Lembar bawah filter & urutan untuk semua daftar mobile (07 §5 "filter … (bottom sheet)").
///
/// Field dibangun dari `meta.listing.spec` server, jadi daftar baru tidak perlu menulis UI
/// filter sendiri. Mengembalikan null bila ditutup tanpa "Terapkan".
Future<FilterSheetResult?> showFilterSheet(
  BuildContext context, {
  required ListingSpec spec,
  required Map<String, Object?> filters,
  required String sort,
}) => showModalBottomSheet<FilterSheetResult>(
  context: context,
  isScrollControlled: true,
  useSafeArea: true,
  builder: (_) => FilterSheet(spec: spec, filters: filters, sort: sort),
);

class FilterSheet extends StatefulWidget {
  const FilterSheet({required this.spec, required this.filters, required this.sort, super.key});

  final ListingSpec spec;
  final Map<String, Object?> filters;
  final String sort;

  @override
  State<FilterSheet> createState() => _FilterSheetState();
}

class _FilterSheetState extends State<FilterSheet> {
  late Map<String, Object?> _filters = {...widget.filters};
  late String _sort = widget.sort.isEmpty ? widget.spec.defaultSort : widget.sort;

  void _set(String key, Object? value) => setState(() => _filters[key] = value);

  void _reset() => setState(() {
    _filters = {for (final f in widget.spec.filters) f.key: widget.spec.defaultFilters[f.key]};
    _sort = widget.spec.defaultSort;
  });

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final editable = widget.spec.filters.where((f) => f.editable).toList();

    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.7,
      maxChildSize: 0.95,
      builder: (context, controller) => Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: Row(
              children: [
                Expanded(child: Text('Filter & urutan', style: text.titleLarge)),
                TextButton(onPressed: _reset, child: const Text('Atur ulang')),
              ],
            ),
          ),
          Expanded(
            child: ListView(
              controller: controller,
              padding: const EdgeInsets.symmetric(horizontal: 16),
              children: [
                for (final f in editable) ...[
                  _FilterField(spec: f, value: _filters[f.key], onChanged: (v) => _set(f.key, v)),
                  const SizedBox(height: 20),
                ],
                if (widget.spec.sorts.isNotEmpty)
                  _SortField(spec: widget.spec, sort: _sort, onChanged: (v) => setState(() => _sort = v)),
                const SizedBox(height: 16),
              ],
            ),
          ),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: () => Navigator.of(context).pop(FilterSheetResult(filters: _filters, sort: _sort)),
                  child: const Text('Terapkan Filter'),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _FilterField extends StatelessWidget {
  const _FilterField({required this.spec, required this.value, required this.onChanged});

  final FilterSpec spec;
  final Object? value;
  final ValueChanged<Object?> onChanged;

  @override
  Widget build(BuildContext context) {
    final label = Text(spec.label, style: Theme.of(context).textTheme.titleSmall);

    final Widget field = switch (spec.type) {
      FilterType.select => Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          if (!spec.required)
            ChoiceChip(
              label: const Text('Semua'),
              selected: isEmptyFilterValue(value),
              onSelected: (_) => onChanged(null),
            ),
          for (final o in spec.options)
            ChoiceChip(label: Text(o.label), selected: '$value' == o.value, onSelected: (_) => onChanged(o.value)),
        ],
      ),
      FilterType.multiSelect => Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final o in spec.options)
            FilterChip(
              label: Text(o.label),
              selected: _selected.contains(o.value),
              onSelected: (on) =>
                  onChanged(on ? [..._selected, o.value] : _selected.where((v) => v != o.value).toList()),
            ),
        ],
      ),
      FilterType.boolean => Wrap(
        spacing: 8,
        children: [
          if (!spec.required)
            ChoiceChip(label: const Text('Semua'), selected: value == null, onSelected: (_) => onChanged(null)),
          ChoiceChip(label: const Text('Ya'), selected: _bool == true, onSelected: (_) => onChanged(true)),
          ChoiceChip(label: const Text('Tidak'), selected: _bool == false, onSelected: (_) => onChanged(false)),
        ],
      ),
      FilterType.dateRange => _DateRangeField(spec: spec, value: value, onChanged: onChanged),
      FilterType.numberRange => _NumberRangeField(value: value, onChanged: onChanged),
      FilterType.unknown => const SizedBox.shrink(),
    };

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [label, const SizedBox(height: 8), field]);
  }

  List<String> get _selected => value is List ? [for (final v in value! as List) '$v'] : const [];

  bool? get _bool => switch (value) {
    true || '1' || 'true' => true,
    false || '0' || 'false' => false,
    _ => null,
  };
}

class _DateRangeField extends StatelessWidget {
  const _DateRangeField({required this.spec, required this.value, required this.onChanged});

  final FilterSpec spec;
  final Object? value;
  final ValueChanged<Object?> onChanged;

  @override
  Widget build(BuildContext context) {
    final range = value is Map ? value! as Map : const <Object?, Object?>{};
    final from = Fmt.parseIsoDate('${range['from'] ?? ''}');
    final to = Fmt.parseIsoDate('${range['to'] ?? ''}');
    final label = describeFilterValue(spec, value) ?? 'Pilih rentang tanggal';

    return Row(
      children: [
        Expanded(
          child: OutlinedButton.icon(
            icon: const Icon(Icons.date_range),
            label: Align(alignment: Alignment.centerLeft, child: Text(label)),
            onPressed: () async {
              final now = DateTime.now();
              final picked = await showDateRangePicker(
                context: context,
                firstDate: DateTime(now.year - 5),
                lastDate: DateTime(now.year + 1, 12, 31),
                initialDateRange: from != null && to != null ? DateTimeRange(start: from, end: to) : null,
                helpText: spec.label,
                saveText: 'Pilih',
              );
              if (picked != null) {
                onChanged({'from': Fmt.toIsoDate(picked.start), 'to': Fmt.toIsoDate(picked.end)});
              }
            },
          ),
        ),
        if (!spec.required && !isEmptyFilterValue(value))
          IconButton(tooltip: 'Hapus ${spec.label}', icon: const Icon(Icons.close), onPressed: () => onChanged(null)),
      ],
    );
  }
}

class _NumberRangeField extends StatelessWidget {
  const _NumberRangeField({required this.value, required this.onChanged});

  final Object? value;
  final ValueChanged<Object?> onChanged;

  @override
  Widget build(BuildContext context) {
    final range = value is Map ? (value! as Map).cast<String, Object?>() : <String, Object?>{};

    Widget input(String key, String label) => Expanded(
      child: TextFormField(
        initialValue: range[key]?.toString() ?? '',
        keyboardType: TextInputType.number,
        decoration: InputDecoration(labelText: label),
        onChanged: (text) => onChanged({...range, key: num.tryParse(text.trim())}),
      ),
    );

    return Row(children: [input('min', 'Minimal'), const SizedBox(width: 12), input('max', 'Maksimal')]);
  }
}

class _SortField extends StatelessWidget {
  const _SortField({required this.spec, required this.sort, required this.onChanged});

  final ListingSpec spec;
  final String sort;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    final descending = sort.startsWith('-');
    final key = descending ? sort.substring(1) : sort;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Urutkan', style: Theme.of(context).textTheme.titleSmall),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final s in spec.sorts)
              ChoiceChip(
                label: Text(s.label),
                selected: s.key == key,
                onSelected: (_) => onChanged(descending ? '-${s.key}' : s.key),
              ),
          ],
        ),
        const SizedBox(height: 8),
        SegmentedButton<bool>(
          segments: const [
            ButtonSegment(value: false, label: Text('Naik'), icon: Icon(Icons.arrow_upward)),
            ButtonSegment(value: true, label: Text('Turun'), icon: Icon(Icons.arrow_downward)),
          ],
          selected: {descending},
          onSelectionChanged: (s) => onChanged(s.first ? '-$key' : key),
        ),
      ],
    );
  }
}

/// Teks chip filter aktif, mis. "Status: Telat, Tidak hadir" atau "1 Nov 2026 – 30 Nov 2026".
/// Null bila nilai kosong.
String? describeFilterValue(FilterSpec spec, Object? value) {
  if (isEmptyFilterValue(value)) {
    return null;
  }
  switch (spec.type) {
    case FilterType.select:
      return spec.optionLabel(value) ?? '$value';
    case FilterType.multiSelect:
      final values = value is List ? value : [value];
      return values.map((v) => spec.optionLabel(v) ?? '$v').join(', ');
    case FilterType.boolean:
      return value == true || value == '1' || value == 'true' ? 'Ya' : 'Tidak';
    case FilterType.dateRange:
      final range = value is Map ? value : const <Object?, Object?>{};
      final from = range['from'] is String ? Fmt.isoDate(range['from'] as String) : null;
      final to = range['to'] is String ? Fmt.isoDate(range['to'] as String) : null;
      if (from != null && to != null) {
        return from == to ? from : '$from – $to';
      }
      return from != null ? 'Sejak $from' : 'Sampai $to';
    case FilterType.numberRange:
      final range = value is Map ? value : const <Object?, Object?>{};
      final min = range['min'];
      final max = range['max'];
      if (min != null && max != null) {
        return '${Fmt.number(min as num)}–${Fmt.number(max as num)}';
      }
      return min != null ? '≥ ${Fmt.number(min as num)}' : '≤ ${Fmt.number(max! as num)}';
    case FilterType.unknown:
      return '$value';
  }
}
