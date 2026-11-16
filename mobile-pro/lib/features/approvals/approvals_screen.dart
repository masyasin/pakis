import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/format.dart';
import '../../core/listing/listing.dart';
import '../../core/providers.dart';
import '../../shared/widgets/list_item_card.dart';
import '../../shared/widgets/paged_list.dart';
import '../corrections/correction.dart';
import '../corrections/correction_detail_screen.dart';

/// Tab Persetujuan (07 §5, hanya pemegang `attendance_corrections.approve`): koreksi yang
/// boleh diputuskan (06 §6), default `pending` terlama dulu, detail → Setujui / Tolak.
class ApprovalsScreen extends ConsumerStatefulWidget {
  const ApprovalsScreen({super.key});

  static const path = '/api/v1/approvals/attendance-corrections';

  @override
  ConsumerState<ApprovalsScreen> createState() => _ApprovalsScreenState();
}

class _ApprovalsScreenState extends ConsumerState<ApprovalsScreen> {
  int _version = 0;

  Future<void> _open(Correction c) async {
    final changed = await CorrectionDetailScreen.open(
      context,
      correction: c,
      actions: c.isPending ? CorrectionDetailActions.decide : CorrectionDetailActions.none,
    );
    if (changed != null && mounted) {
      setState(() => _version++);
    }
  }

  @override
  Widget build(BuildContext context) => PagedList<Correction>(
    key: ValueKey(_version),
    fetch: (q) => ref
        .read(apiClientProvider)
        .get(ApprovalsScreen.path, query: q.toQueryParameters())
        .then((r) => ListingPage.fromResponse(r, Correction.fromJson)),
    emptyTitle: 'Tidak ada koreksi menunggu',
    emptyMessage: 'Koreksi presensi bawahan yang perlu diputuskan tampil di sini.',
    itemBuilder: (context, c) => ListItemCard(
      icon: Icons.fact_check_outlined,
      title: c.employeeName ?? c.number,
      subtitle: [
        '${Fmt.isoDate(c.workDate)} · ${c.typeLabel}',
        [if (c.proposedSummary.isNotEmpty) c.proposedSummary, ?c.reasonName].join(' · '),
      ].where((line) => line.isNotEmpty).join('\n'),
      status: c.status,
      onTap: () => _open(c),
    ),
  );
}
