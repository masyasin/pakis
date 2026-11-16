import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../core/api/api_error.dart';
import '../../core/format.dart';
import '../../core/listing/listing.dart';
import '../../core/providers.dart';
import '../../core/theme/pakis_tokens.dart';
import '../../shared/widgets/list_item_card.dart';
import '../../shared/widgets/paged_list.dart';
import '../attendance/attendance_models.dart';

/// Satu hari di riwayat presensi milik sendiri (05 §5.5).
class AttendanceDayItem {
  const AttendanceDayItem({
    required this.id,
    required this.workDate,
    required this.status,
    this.shift,
    this.checkInAt,
    this.checkOutAt,
    this.lateMinutes = 0,
    this.earlyLeaveMinutes = 0,
    this.isOffline = false,
    this.isCorrected = false,
  });

  factory AttendanceDayItem.fromJson(Map<String, Object?> json) => AttendanceDayItem(
    id: (json['id'] as num?)?.toInt() ?? 0,
    workDate: json['work_date'] as String? ?? '',
    status: json['status'] as String? ?? '',
    shift: ShiftInfo.fromJson(json['shift']),
    checkInAt: json['check_in_at'] as String?,
    checkOutAt: json['check_out_at'] as String?,
    lateMinutes: (json['late_minutes'] as num?)?.toInt() ?? 0,
    earlyLeaveMinutes: (json['early_leave_minutes'] as num?)?.toInt() ?? 0,
    isOffline: json['is_offline'] == true,
    isCorrected: json['is_corrected'] == true,
  );

  final int id;
  final String workDate;
  final String status;
  final ShiftInfo? shift;
  final String? checkInAt;
  final String? checkOutAt;
  final int lateMinutes;
  final int earlyLeaveMinutes;
  final bool isOffline;
  final bool isCorrected;
}

/// Rekap bulan (05 §5.6, BR-072).
class MonthSummary {
  const MonthSummary(this.json);

  final Map<String, Object?> json;

  int operator [](String key) => (json[key] as num?)?.toInt() ?? 0;
}

final monthSummaryProvider = FutureProvider.autoDispose.family<MonthSummary, String>((ref, month) async {
  final response = await ref.watch(apiClientProvider).get('/api/v1/attendance/summary', query: {'month': month});
  return MonthSummary(response.object);
});

/// Tab Riwayat (07 §5): daftar paging + cari + filter bulan/status, header rekap bulan.
class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key});

  static const path = '/api/v1/attendance';

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  String? _month;

  @override
  Widget build(BuildContext context) => Consumer(
    builder: (context, ref, _) => PagedList<AttendanceDayItem>(
      fetch: (q) => ref
          .read(apiClientProvider)
          .get(HistoryScreen.path, query: q.toQueryParameters())
          .then((r) => ListingPage.fromResponse(r, AttendanceDayItem.fromJson)),
      onLoaded: (page) {
        final month = page.filters['month'];
        if (month is String && month != _month) {
          setState(() => _month = month);
        }
      },
      header: _month == null ? null : _SummaryCard(month: _month!),
      emptyTitle: 'Belum ada presensi',
      emptyMessage: 'Presensi bulan ini akan tampil di sini.',
      itemBuilder: (context, day) => _DayTile(day: day),
    ),
  );
}

class _DayTile extends StatelessWidget {
  const _DayTile({required this.day});

  final AttendanceDayItem day;

  @override
  Widget build(BuildContext context) {
    final date = Fmt.parseIsoDate(day.workDate);
    final inAt = Fmt.wallClock(day.checkInAt) ?? '—';
    final outAt = Fmt.wallClock(day.checkOutAt) ?? '—';
    final notes = [
      if (day.lateMinutes > 0) 'telat ${Fmt.number(day.lateMinutes)} mnt',
      if (day.earlyLeaveMinutes > 0) 'pulang cepat ${Fmt.number(day.earlyLeaveMinutes)} mnt',
      if (day.isCorrected) 'dikoreksi',
      if (day.isOffline) 'dikirim offline',
    ];
    return ListItemCard(
      icon: day.isCorrected ? Icons.edit_calendar_outlined : Icons.event_available_outlined,
      title: date == null ? day.workDate : Fmt.longDate(date),
      subtitle: [
        [if (day.shift != null) day.shift!.name, '$inAt → $outAt'].join(' · '),
        if (notes.isNotEmpty) notes.join(', '),
      ].join('\n'),
      status: day.status,
    );
  }
}

class _SummaryCard extends ConsumerWidget {
  const _SummaryCard({required this.month});

  final String month;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tokens = context.tokens;
    final text = Theme.of(context).textTheme;
    final summary = ref.watch(monthSummaryProvider(month));
    final parsed = Fmt.parseIsoDate('$month-01');

    Widget cell(String label, int value, StatusTone tone) => Expanded(
      child: Column(
        children: [
          Text(
            Fmt.number(value),
            style: text.headlineSmall?.copyWith(fontWeight: FontWeight.w700, color: tokens.status[tone]),
          ),
          Text(label, style: text.labelMedium?.copyWith(color: tokens.muted)),
        ],
      ),
    );

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: tokens.surface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: tokens.border),
        ),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'REKAP ${parsed == null ? month : Fmt.monthYear(parsed).toUpperCase()}',
                style: text.labelMedium?.copyWith(color: tokens.muted, letterSpacing: 0.6),
              ),
              const SizedBox(height: 12),
              switch (summary) {
                AsyncData(:final value) => Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        cell('Hadir', value['present'], StatusTone.success),
                        cell('Telat', value['late'], StatusTone.warning),
                        cell('Tidak hadir', value['absent'], StatusTone.danger),
                      ],
                    ),
                    if (value['late_minutes_total'] > 0 || value['corrections_pending'] > 0) ...[
                      const SizedBox(height: 12),
                      Text(
                        [
                          if (value['late_minutes_total'] > 0)
                            'Total telat ${Fmt.number(value['late_minutes_total'])} menit',
                          if (value['corrections_pending'] > 0)
                            '${Fmt.number(value['corrections_pending'])} koreksi menunggu',
                        ].join(' · '),
                        style: text.bodyMedium?.copyWith(color: tokens.muted),
                      ),
                    ],
                  ],
                ),
                AsyncError(:final error) => Text(
                  error is ApiError ? error.message : 'Rekap belum bisa dimuat.',
                  style: text.bodyMedium?.copyWith(color: tokens.muted),
                ),
                _ => const Center(child: CircularProgressIndicator()),
              },
            ],
          ),
        ),
      ),
    );
  }
}
