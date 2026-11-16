import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/format.dart';
import '../../core/listing/listing.dart';
import '../../core/providers.dart';
import '../../shared/widgets/list_item_card.dart';
import '../../shared/widgets/paged_list.dart';
import '../auth/session.dart';
import '../corrections/correction.dart';
import '../corrections/correction_detail_screen.dart';
import '../field_trips/field_trip.dart';
import '../field_trips/field_trip_detail_screen.dart';
import '../field_trips/my_field_trips_screen.dart';

enum ApprovalKind { corrections, fieldTrips }

/// Jenis persetujuan yang boleh dilihat pengguna (permission token).
List<ApprovalKind> approvalKindsFor(bool Function(String permission) can) => [
  if (can('attendance_corrections.approve')) ApprovalKind.corrections,
  if (can('field_trips.approve')) ApprovalKind.fieldTrips,
];

/// Tab Persetujuan (07 §5): koreksi presensi (`attendance_corrections.approve`) dan pengajuan
/// tugas luar kota (`field_trips.approve`, T-036A bagian 2). Segmen hanya tampil bila
/// pengguna berhak atas keduanya. Keduanya = yang boleh diputuskan (06 §6), default `pending`.
class ApprovalsScreen extends ConsumerStatefulWidget {
  const ApprovalsScreen({super.key});

  static const path = '/api/v1/approvals/attendance-corrections';

  @override
  ConsumerState<ApprovalsScreen> createState() => _ApprovalsScreenState();
}

class _ApprovalsScreenState extends ConsumerState<ApprovalsScreen> {
  ApprovalKind? _selected;
  int _version = 0;

  Future<void> _openCorrection(Correction c) async {
    final changed = await CorrectionDetailScreen.open(
      context,
      correction: c,
      actions: c.isPending ? CorrectionDetailActions.decide : CorrectionDetailActions.none,
    );
    if (changed != null && mounted) {
      setState(() => _version++);
    }
  }

  Future<void> _openTrip(FieldTrip t) async {
    final changed = await FieldTripDetailScreen.open(context, trip: t, decide: true);
    if (changed != null && mounted) {
      setState(() => _version++);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = ref.watch(sessionProvider).value;
    final kinds = session is SignedIn ? approvalKindsFor(session.user.can) : const <ApprovalKind>[];
    if (kinds.isEmpty) {
      return const SizedBox.shrink();
    }
    final kind = kinds.contains(_selected) ? _selected! : kinds.first;

    final list = switch (kind) {
      ApprovalKind.corrections => PagedList<Correction>(
        key: ValueKey('corrections-$_version'),
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
          onTap: () => _openCorrection(c),
        ),
      ),
      ApprovalKind.fieldTrips => PagedList<FieldTrip>(
        key: ValueKey('field-trips-$_version'),
        fetch: (q) => ref
            .read(apiClientProvider)
            .get(fieldTripApprovalsPath, query: q.toQueryParameters())
            .then((r) => ListingPage.fromResponse(r, FieldTrip.fromJson)),
        emptyTitle: 'Tidak ada pengajuan tugas menunggu',
        emptyMessage: 'Pengajuan tugas luar kota bawahan yang perlu diputuskan tampil di sini.',
        itemBuilder: (context, t) => FieldTripTile(trip: t, showEmployees: true, onTap: () => _openTrip(t)),
      ),
    };

    if (kinds.length < 2) {
      return list;
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
          child: SegmentedButton<ApprovalKind>(
            segments: const [
              ButtonSegment(value: ApprovalKind.corrections, label: Text('Koreksi')),
              ButtonSegment(value: ApprovalKind.fieldTrips, label: Text('Tugas luar kota')),
            ],
            selected: {kind},
            showSelectedIcon: false,
            onSelectionChanged: (s) => setState(() => _selected = s.first),
          ),
        ),
        Expanded(child: list),
      ],
    );
  }
}
