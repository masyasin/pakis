import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/api/api_error.dart';
import '../../core/format.dart';
import '../../core/listing/listing.dart';
import '../../core/providers.dart';
import '../../shared/widgets/list_item_card.dart';
import '../../shared/widgets/paged_list.dart';
import 'correction.dart';
import 'correction_detail_screen.dart';
import 'correction_form_screen.dart';

/// Tab Koreksi (07 §5 "Koreksi saya"): daftar milik sendiri + tombol "Ajukan koreksi".
class MyCorrectionsScreen extends ConsumerStatefulWidget {
  const MyCorrectionsScreen({super.key});

  static const path = '/api/v1/attendance-corrections';

  @override
  ConsumerState<MyCorrectionsScreen> createState() => _MyCorrectionsScreenState();
}

class _MyCorrectionsScreenState extends ConsumerState<MyCorrectionsScreen> {
  /// Dinaikkan untuk memuat ulang daftar setelah mengajukan / membatalkan.
  int _version = 0;

  void _reload() => setState(() => _version++);

  Future<void> _submit() async {
    final created = await CorrectionFormScreen.open(context);
    if (created != null && mounted) {
      _reload();
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Koreksi ${created.number} terkirim, menunggu persetujuan.')));
    }
  }

  Future<void> _open(Correction correction) async {
    final changed = await CorrectionDetailScreen.open(
      context,
      correction: correction,
      actions: correction.isPending ? CorrectionDetailActions.cancel : CorrectionDetailActions.none,
    );
    if (changed != null && mounted) {
      _reload();
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: Colors.transparent,
    floatingActionButton: FloatingActionButton.extended(
      onPressed: _submit,
      icon: const Icon(Icons.add),
      label: const Text('Ajukan koreksi'),
    ),
    body: PagedList<Correction>(
      key: ValueKey(_version),
      fetch: (q) => ref
          .read(apiClientProvider)
          .get(MyCorrectionsScreen.path, query: q.toQueryParameters())
          .then((r) => ListingPage.fromResponse(r, Correction.fromJson)),
      emptyTitle: 'Belum ada koreksi',
      emptyMessage: 'Lupa presensi atau jam tercatat salah? Ketuk "Ajukan koreksi".',
      itemBuilder: (context, c) => ListItemCard(
        icon: Icons.edit_calendar_outlined,
        title: '${Fmt.isoDate(c.workDate)} · ${c.typeLabel}',
        subtitle: [c.number, if (c.proposedSummary.isNotEmpty) c.proposedSummary].join(' · '),
        status: c.status,
        onTap: () => _open(c),
      ),
    ),
  );
}

/// `POST /attendance-corrections/{id}/cancel` (05 §5.7).
Future<Correction> cancelCorrection(WidgetRef ref, Correction c) async {
  final response = await ref.read(apiClientProvider).post('${MyCorrectionsScreen.path}/${c.id}/cancel');
  return Correction.fromJson(response.object);
}

/// Pesan galat untuk snackbar (409 `STATE_CONFLICT` = sudah diputuskan orang lain).
String correctionErrorMessage(ApiError e) => e.code == ApiErrorCode.stateConflict
    ? 'Koreksi ini sudah diputuskan atau dibatalkan. Daftar dimuat ulang.'
    : e.message;
