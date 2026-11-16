import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/api/api_error.dart';
import '../../core/format.dart';
import '../../core/providers.dart';
import '../../shared/widgets/list_item_card.dart';
import '../../shared/widgets/status_badge.dart';
import 'correction.dart';
import 'my_corrections_screen.dart';

enum CorrectionDetailActions {
  none,

  /// Pemilik, status `pending` (05 §5.7 cancel).
  cancel,

  /// Penyetuju (05 §5.8): Setujui / Tolak dengan alasan wajib.
  decide,
}

/// Detail koreksi. Kembali dengan objek koreksi terbaru bila status berubah, atau null.
class CorrectionDetailScreen extends ConsumerStatefulWidget {
  const CorrectionDetailScreen({required this.correction, required this.actions, super.key});

  final Correction correction;
  final CorrectionDetailActions actions;

  static Future<Correction?> open(
    BuildContext context, {
    required Correction correction,
    required CorrectionDetailActions actions,
  }) => Navigator.of(context).push(
    MaterialPageRoute<Correction>(
      builder: (_) => CorrectionDetailScreen(correction: correction, actions: actions),
    ),
  );

  @override
  ConsumerState<CorrectionDetailScreen> createState() => _CorrectionDetailScreenState();
}

class _CorrectionDetailScreenState extends ConsumerState<CorrectionDetailScreen> {
  bool _busy = false;

  static const approvalsPath = '/api/v1/approvals/attendance-corrections';

  Future<void> _run(Future<Correction> Function() action, String done) async {
    setState(() => _busy = true);
    try {
      final updated = await action();
      if (!mounted) {
        return;
      }
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(done)));
      Navigator.of(context).pop(updated);
    } on ApiError catch (e) {
      if (!mounted) {
        return;
      }
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(correctionErrorMessage(e))));
      if (e.code == ApiErrorCode.stateConflict || e.code == ApiErrorCode.notFound) {
        // Sudah berubah di tempat lain: kembali agar daftar dimuat ulang.
        Navigator.of(context).pop(widget.correction);
      }
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  Future<void> _cancel() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Batalkan koreksi?'),
        content: Text('Koreksi ${widget.correction.number} tidak akan diproses.'),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('Kembali')),
          FilledButton(onPressed: () => Navigator.of(context).pop(true), child: const Text('Batalkan Koreksi')),
        ],
      ),
    );
    if (ok == true) {
      await _run(() => cancelCorrection(ref, widget.correction), 'Koreksi dibatalkan.');
    }
  }

  Future<void> _decide({required bool approve}) async {
    final note = await showDialog<String>(
      context: context,
      builder: (_) => _DecisionDialog(approve: approve),
    );
    if (note == null) {
      return;
    }
    final c = widget.correction;
    await _run(() async {
      final response = await ref
          .read(apiClientProvider)
          .post('$approvalsPath/${c.id}/${approve ? 'approve' : 'reject'}', data: {if (note.isNotEmpty) 'note': note});
      return Correction.fromJson(response.object);
    }, approve ? 'Koreksi disetujui.' : 'Koreksi ditolak.');
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.correction;
    final text = Theme.of(context).textTheme;
    final pending = c.isPending;

    return Scaffold(
      appBar: AppBar(title: Text(c.number.isEmpty ? 'Koreksi presensi' : c.number)),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    '${Fmt.isoDate(c.workDate)} · ${c.typeLabel}',
                    style: text.titleMedium?.copyWith(fontWeight: FontWeight.w700),
                  ),
                ),
                StatusBadge(status: c.status),
              ],
            ),
            const SizedBox(height: 12),
            if (c.employeeName != null)
              DetailRow(label: 'Pegawai', value: [c.employeeName!, ?c.employeeNumber].join(' · ')),
            if (c.proposedCheckIn != null)
              DetailRow(label: 'Jam masuk', value: c.proposedCheckIn!.replaceAll(':', '.')),
            if (c.proposedCheckOut != null)
              DetailRow(label: 'Jam pulang', value: c.proposedCheckOut!.replaceAll(':', '.')),
            if (c.reasonName != null) DetailRow(label: 'Alasan', value: c.reasonName!),
            if (c.note != null && c.note!.isNotEmpty) DetailRow(label: 'Catatan', value: c.note!),
            DetailRow(label: 'Lampiran', value: c.hasAttachment ? 'Ada (lihat di PAKIS ONE web)' : 'Tidak ada'),
            if (c.isManual) const DetailRow(label: 'Sumber', value: 'Presensi manual oleh admin/atasan'),
            if (c.submittedAt != null) DetailRow(label: 'Diajukan', value: _when(c.submittedAt!)),
            if (c.approverName != null) DetailRow(label: 'Atasan', value: c.approverName!),
            if (c.decidedAt != null)
              DetailRow(label: 'Diputuskan', value: [_when(c.decidedAt!), ?c.decidedByName].join(' · ')),
            if (c.decisionNote != null && c.decisionNote!.isNotEmpty)
              DetailRow(label: 'Catatan keputusan', value: c.decisionNote!),
            const SizedBox(height: 24),
            if (pending && widget.actions == CorrectionDetailActions.cancel)
              SizedBox(
                height: 48,
                child: OutlinedButton(onPressed: _busy ? null : _cancel, child: const Text('Batalkan Koreksi')),
              ),
            if (pending && widget.actions == CorrectionDetailActions.decide) ...[
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

  /// `2026-11-16T09:12:00+07:00` → `16 Nov 2026 09.12` (jam dinding server).
  static String _when(String iso) {
    final date = iso.length >= 10 ? Fmt.isoDate(iso.substring(0, 10)) : iso;
    final time = Fmt.wallClock(iso);
    return time == null ? date : '$date $time';
  }
}

/// Catatan keputusan: opsional saat menyetujui (≤ 500), wajib 5–500 saat menolak (05 §5.8).
class _DecisionDialog extends StatefulWidget {
  const _DecisionDialog({required this.approve});

  final bool approve;

  @override
  State<_DecisionDialog> createState() => _DecisionDialogState();
}

class _DecisionDialogState extends State<_DecisionDialog> {
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
    title: Text(widget.approve ? 'Setujui koreksi?' : 'Tolak koreksi?'),
    content: TextField(
      controller: _note,
      maxLength: 500,
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
