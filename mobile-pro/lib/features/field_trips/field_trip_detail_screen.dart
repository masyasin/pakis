import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/api/api_error.dart';
import '../../core/format.dart';
import '../../core/providers.dart';
import '../../shared/widgets/list_item_card.dart';
import '../../shared/widgets/status_badge.dart';
import 'field_trip.dart';

const fieldTripsPath = '/api/v1/field-trips';
const fieldTripApprovalsPath = '/api/v1/approvals/field-trips';

/// "3 Nov 2026 – 5 Nov 2026 (3 hari)" / "3 Nov 2026 (1 hari)".
String fieldTripPeriod(FieldTrip t) {
  final start = Fmt.isoDate(t.startDate);
  final range = t.endDate.isEmpty || t.endDate == t.startDate ? start : '$start – ${Fmt.isoDate(t.endDate)}';
  return '$range (${Fmt.number(t.days)} hari)';
}

/// Detail tugas luar kota. [decide] = penyetuju (06 §6): Setujui / Tolak. Pengaju hanya
/// melihat (tidak ada pembatalan oleh pengaju, 06 §7.2a). Kembali dengan tugas terbaru bila
/// status berubah (atau bila server menyatakan sudah berubah), selain itu null.
class FieldTripDetailScreen extends ConsumerStatefulWidget {
  const FieldTripDetailScreen({required this.trip, this.decide = false, super.key});

  final FieldTrip trip;
  final bool decide;

  static Future<FieldTrip?> open(BuildContext context, {required FieldTrip trip, bool decide = false}) =>
      Navigator.of(context).push(
        MaterialPageRoute<FieldTrip>(
          builder: (_) => FieldTripDetailScreen(trip: trip, decide: decide),
        ),
      );

  @override
  ConsumerState<FieldTripDetailScreen> createState() => _FieldTripDetailScreenState();
}

class _FieldTripDetailScreenState extends ConsumerState<FieldTripDetailScreen> {
  bool _busy = false;

  Future<void> _decide({required bool approve}) async {
    final note = await showDialog<String>(
      context: context,
      builder: (_) => _NoteDialog(approve: approve),
    );
    if (note == null || !mounted) {
      return;
    }
    setState(() => _busy = true);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final response = await ref
          .read(apiClientProvider)
          .post(
            '$fieldTripApprovalsPath/${widget.trip.id}/${approve ? 'approve' : 'reject'}',
            data: {if (note.isNotEmpty) 'note': note},
          );
      if (!mounted) {
        return;
      }
      messenger.showSnackBar(
        SnackBar(content: Text(approve ? 'Tugas luar kota disetujui.' : 'Tugas luar kota ditolak.')),
      );
      Navigator.of(context).pop(FieldTrip.fromJson(response.object));
    } on ApiError catch (e) {
      if (!mounted) {
        return;
      }
      // 409 = sudah diputuskan / tumpang tindih saat disetujui: pesan server lebih spesifik.
      final text = e.code == ApiErrorCode.stateConflict ? (e.serverMessage ?? e.message) : e.message;
      messenger.showSnackBar(SnackBar(content: Text(text)));
      if (e.code == ApiErrorCode.stateConflict || e.code == ApiErrorCode.notFound) {
        Navigator.of(context).pop(widget.trip);
      }
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = widget.trip;
    final text = Theme.of(context).textTheme;
    return Scaffold(
      appBar: AppBar(title: Text(t.number.isEmpty ? 'Tugas luar kota' : t.number)),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(t.destination, style: text.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                ),
                StatusBadge(status: t.status),
              ],
            ),
            const SizedBox(height: 12),
            DetailRow(label: 'Tanggal', value: fieldTripPeriod(t)),
            if (t.employees.isNotEmpty) DetailRow(label: 'Pegawai', value: t.employees.join('\n')),
            if (t.purpose != null && t.purpose!.isNotEmpty) DetailRow(label: 'Keperluan', value: t.purpose!),
            DetailRow(label: 'Aturan presensi', value: t.ruleLabel),
            if (t.destinations.isNotEmpty)
              DetailRow(
                label: 'Titik tujuan',
                value: [
                  for (final d in t.destinations)
                    d.radiusM == null ? d.name : '${d.name} (radius ${Fmt.number(d.radiusM!)} m)',
                ].join('\n'),
              ),
            if (t.requestedByName != null)
              DetailRow(label: t.isRequest ? 'Diajukan oleh' : 'Dibuat oleh', value: t.requestedByName!),
            if (t.submittedAt != null) DetailRow(label: 'Diajukan', value: _when(t.submittedAt!)),
            if (t.decidedAt != null)
              DetailRow(label: 'Diputuskan', value: [_when(t.decidedAt!), ?t.decidedByName].join(' · ')),
            if (t.decisionNote != null && t.decisionNote!.isNotEmpty)
              DetailRow(label: 'Catatan keputusan', value: t.decisionNote!),
            const SizedBox(height: 24),
            if (widget.decide && t.isPending) ...[
              SizedBox(
                height: 48,
                child: FilledButton(
                  onPressed: _busy ? null : () => _decide(approve: true),
                  child: const Text('Setujui'),
                ),
              ),
              const SizedBox(height: 8),
              SizedBox(
                height: 48,
                child: OutlinedButton(
                  onPressed: _busy ? null : () => _decide(approve: false),
                  child: const Text('Tolak'),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  static String _when(String iso) {
    final date = iso.length >= 10 ? Fmt.isoDate(iso.substring(0, 10)) : iso;
    final time = Fmt.wallClock(iso);
    return time == null ? date : '$date $time';
  }
}

/// Catatan keputusan tugas: opsional saat menyetujui (≤ 255), wajib 5–255 saat menolak.
class _NoteDialog extends StatefulWidget {
  const _NoteDialog({required this.approve});

  final bool approve;

  @override
  State<_NoteDialog> createState() => _NoteDialogState();
}

class _NoteDialogState extends State<_NoteDialog> {
  final _note = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  void _submit() {
    final note = _note.text.trim();
    if (!widget.approve && note.length < 5) {
      setState(() => _error = 'Alasan penolakan wajib diisi, minimal 5 karakter.');
      return;
    }
    Navigator.of(context).pop(note);
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(widget.approve ? 'Setujui tugas luar kota?' : 'Tolak tugas luar kota?'),
    content: TextField(
      controller: _note,
      maxLength: 255,
      maxLines: 3,
      decoration: InputDecoration(
        labelText: widget.approve ? 'Catatan (opsional)' : 'Alasan penolakan',
        errorText: _error,
      ),
    ),
    actions: [
      TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Batal')),
      FilledButton(onPressed: _submit, child: Text(widget.approve ? 'Setujui' : 'Tolak')),
    ],
  );
}
