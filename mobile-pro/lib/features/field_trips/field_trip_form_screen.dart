import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/api/api_error.dart';
import '../../core/format.dart';
import '../../core/providers.dart';
import '../../core/theme/pakis_tokens.dart';
import 'field_trip.dart';
import 'field_trip_detail_screen.dart';

/// Titik tujuan yang sedang diisi (koordinat hanya dikirim, tidak ditampilkan ulang).
class _PointDraft {
  const _PointDraft({required this.name, required this.position, this.radiusM});

  final String name;
  final LatLng position;
  final int? radiusM;
}

/// Batas titik tujuan per tugas (keputusan Mas, `field_trip_destinations` 1–10).
const maxTripPoints = 10;

/// Form "Ajukan tugas luar kota" (T-036A bagian 2): pegawai = pengaju sendiri, status
/// `pending` sampai diputuskan atasan/kepala lokasi (06 §6). Batas lama tugas & tumpang tindih
/// diperiksa server (`attendance.field_trip_max_days` tidak ada di `/config`).
class FieldTripFormScreen extends ConsumerStatefulWidget {
  const FieldTripFormScreen({super.key});

  static Future<FieldTrip?> open(BuildContext context) =>
      Navigator.of(context).push(MaterialPageRoute<FieldTrip>(builder: (_) => const FieldTripFormScreen()));

  @override
  ConsumerState<FieldTripFormScreen> createState() => _FieldTripFormScreenState();
}

class _FieldTripFormScreenState extends ConsumerState<FieldTripFormScreen> {
  late DateTime _start;
  late DateTime _end;
  final _destination = TextEditingController();
  final _purpose = TextEditingController();
  String _rule = 'anywhere';
  final _points = <_PointDraft>[];
  Map<String, List<String>> _errors = const {};
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _start = DateUtils.dateOnly(DateTime.now());
    _end = _start;
  }

  @override
  void dispose() {
    _destination.dispose();
    _purpose.dispose();
    super.dispose();
  }

  Future<void> _pickRange() async {
    final today = DateUtils.dateOnly(DateTime.now());
    final picked = await showDateRangePicker(
      context: context,
      firstDate: today.subtract(const Duration(days: 30)),
      lastDate: today.add(const Duration(days: 365)),
      initialDateRange: DateTimeRange(start: _start, end: _end),
      helpText: 'Tanggal tugas',
    );
    if (picked != null) {
      setState(() {
        _start = picked.start;
        _end = picked.end;
      });
    }
  }

  Future<void> _addPoint() async {
    final point = await showDialog<_PointDraft>(context: context, builder: (_) => const _PointDialog());
    if (point != null) {
      setState(() => _points.add(point));
    }
  }

  Map<String, List<String>> _validate() {
    final destination = _destination.text.trim();
    return {
      if (destination.length < 2 || destination.length > 150) 'destination': ['Tujuan wajib diisi, 2–150 karakter.'],
      if (_rule == 'destination' && _points.isEmpty) 'destinations': ['Tambahkan minimal satu titik tujuan.'],
    };
  }

  Future<void> _submit() async {
    final local = _validate();
    setState(() {
      _errors = local;
      _error = null;
    });
    if (local.isNotEmpty) {
      return;
    }
    setState(() => _busy = true);
    try {
      final purpose = _purpose.text.trim();
      final response = await ref
          .read(apiClientProvider)
          .post(
            fieldTripsPath,
            data: {
              'start_date': Fmt.toIsoDate(_start),
              'end_date': Fmt.toIsoDate(_end),
              'destination': _destination.text.trim(),
              if (purpose.isNotEmpty) 'purpose': purpose,
              'attendance_rule': _rule,
              if (_rule == 'destination')
                'destinations': [
                  for (final p in _points)
                    {
                      'name': p.name,
                      'latitude': p.position.latitude,
                      'longitude': p.position.longitude,
                      'radius_m': p.radiusM,
                    },
                ],
            },
          );
      if (mounted) {
        Navigator.of(context).pop(FieldTrip.fromJson(response.object));
      }
    } on ApiError catch (e) {
      if (mounted) {
        setState(() {
          _errors = e.fieldErrors;
          _error = e.fieldErrors.isEmpty ? e.message : null;
        });
      }
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  /// Galat field termasuk turunan (`destinations.0.latitude` → `destinations`).
  String? _err(String field) {
    final messages = [
      for (final e in _errors.entries)
        if (e.key == field || e.key.startsWith('$field.')) ...e.value,
    ];
    return messages.isEmpty ? null : messages.toSet().join(' ');
  }

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    final days = _end.difference(_start).inDays + 1;

    return Scaffold(
      appBar: AppBar(title: const Text('Ajukan tugas luar kota')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 16),
                child: Text(_error!, style: text.bodyMedium?.copyWith(color: tokens.danger)),
              ),
            InkWell(
              onTap: _pickRange,
              borderRadius: BorderRadius.circular(8),
              child: InputDecorator(
                decoration: InputDecoration(
                  labelText: 'Tanggal',
                  errorText: _err('start_date') ?? _err('end_date'),
                  suffixIcon: const Icon(Icons.date_range),
                ),
                child: Text(
                  _start == _end
                      ? '${Fmt.date(_start)} (1 hari)'
                      : '${Fmt.date(_start)} – ${Fmt.date(_end)} (${Fmt.number(days)} hari)',
                ),
              ),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _destination,
              maxLength: 150,
              decoration: InputDecoration(
                labelText: 'Tujuan',
                hintText: 'Mis. Kios Tani Makmur, Pati',
                errorText: _err('destination'),
              ),
            ),
            TextField(
              controller: _purpose,
              maxLength: 500,
              maxLines: 3,
              decoration: InputDecoration(labelText: 'Keperluan (opsional)', errorText: _err('purpose')),
            ),
            DropdownButtonFormField<String>(
              initialValue: _rule,
              isExpanded: true,
              decoration: InputDecoration(labelText: 'Presensi selama tugas', errorText: _err('attendance_rule')),
              items: [
                for (final e in fieldTripRuleLabels.entries) DropdownMenuItem(value: e.key, child: Text(e.value)),
              ],
              onChanged: (v) => setState(() => _rule = v ?? _rule),
            ),
            if (_rule == 'destination') ...[
              const SizedBox(height: 16),
              Text('Titik tujuan', style: text.labelLarge),
              for (final (i, p) in _points.indexed)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.place_outlined),
                  title: Text(p.name),
                  subtitle: Text(p.radiusM == null ? 'Radius bawaan' : 'Radius ${Fmt.number(p.radiusM!)} m'),
                  trailing: IconButton(
                    tooltip: 'Hapus titik ${p.name}',
                    icon: const Icon(Icons.close),
                    onPressed: () => setState(() => _points.removeAt(i)),
                  ),
                ),
              if (_err('destinations') != null)
                Text(_err('destinations')!, style: text.bodySmall?.copyWith(color: tokens.danger)),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  onPressed: _points.length >= maxTripPoints ? null : _addPoint,
                  icon: const Icon(Icons.add_location_alt_outlined),
                  label: Text(
                    _points.length >= maxTripPoints ? 'Maksimal $maxTripPoints titik' : 'Tambah titik tujuan',
                  ),
                ),
              ),
            ],
            const SizedBox(height: 24),
            SizedBox(
              height: 48,
              child: FilledButton(
                onPressed: _busy ? null : _submit,
                child: _busy
                    ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2))
                    : const Text('Kirim Pengajuan'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Satu titik tujuan: nama + tautan Google Maps (atau "lat, lng") + radius opsional.
class _PointDialog extends StatefulWidget {
  const _PointDialog();

  @override
  State<_PointDialog> createState() => _PointDialogState();
}

class _PointDialogState extends State<_PointDialog> {
  final _name = TextEditingController();
  final _link = TextEditingController();
  final _radius = TextEditingController();
  String? _nameError;
  String? _linkError;
  String? _radiusError;

  @override
  void dispose() {
    _name.dispose();
    _link.dispose();
    _radius.dispose();
    super.dispose();
  }

  LatLng? get _parsed => parseMapsLink(_link.text);

  void _save() {
    final name = _name.text.trim();
    final position = _parsed;
    final radiusText = _radius.text.trim();
    final radius = radiusText.isEmpty ? null : int.tryParse(radiusText);
    setState(() {
      _nameError = name.length < 2 ? 'Nama titik wajib diisi.' : null;
      _linkError = position != null
          ? null
          : isShortMapsLink(_link.text)
          ? 'Tautan pendek tidak bisa dibaca. Buka tautan itu di Google Maps, lalu salin tautan lengkap dari kolom alamat.'
          : 'Tempel tautan Google Maps lengkap atau koordinat "-6.80, 110.84".';
      _radiusError = radiusText.isNotEmpty && (radius == null || radius <= 0) ? 'Radius berupa angka meter.' : null;
    });
    if (_nameError == null && _linkError == null && _radiusError == null) {
      Navigator.of(context).pop(_PointDraft(name: name, position: position!, radiusM: radius));
    }
  }

  @override
  Widget build(BuildContext context) {
    final parsed = _parsed;
    return AlertDialog(
      title: const Text('Titik tujuan'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: _name,
              decoration: InputDecoration(labelText: 'Nama titik', errorText: _nameError),
            ),
            TextField(
              controller: _link,
              maxLines: 2,
              onChanged: (_) => setState(() => _linkError = null),
              decoration: InputDecoration(
                labelText: 'Tautan Google Maps',
                hintText: 'https://www.google.com/maps/place/…/@-6.80,110.84,17z',
                helperText: parsed == null
                    ? null
                    : 'Koordinat terbaca: ${parsed.latitude.toStringAsFixed(5)}, ${parsed.longitude.toStringAsFixed(5)}',
                helperMaxLines: 2,
                errorText: _linkError,
                errorMaxLines: 4,
              ),
            ),
            TextField(
              controller: _radius,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(labelText: 'Radius (meter, opsional)', errorText: _radiusError),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Batal')),
        FilledButton(onPressed: _save, child: const Text('Tambah')),
      ],
    );
  }
}
