import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../auth/session.dart';

/// Objek koreksi presensi (05 §5.7a), dipakai "Koreksi saya" dan "Persetujuan".
class Correction {
  const Correction({
    required this.id,
    required this.number,
    required this.status,
    required this.workDate,
    required this.type,
    this.proposedCheckIn,
    this.proposedCheckOut,
    this.reasonName,
    this.note,
    this.hasAttachment = false,
    this.employeeName,
    this.employeeNumber,
    this.approverName,
    this.isManual = false,
    this.submittedAt,
    this.decidedAt,
    this.decidedByName,
    this.decisionNote,
  });

  factory Correction.fromJson(Map<String, Object?> json) {
    final reason = json['reason'];
    final employee = json['employee'];
    final approver = json['approver'];
    final decidedBy = json['decided_by'];
    return Correction(
      id: (json['id'] as num?)?.toInt() ?? 0,
      number: json['number'] as String? ?? '',
      status: json['status'] as String? ?? '',
      workDate: json['work_date'] as String? ?? '',
      type: json['type'] as String? ?? '',
      proposedCheckIn: json['proposed_check_in'] as String?,
      proposedCheckOut: json['proposed_check_out'] as String?,
      reasonName: reason is Map ? reason['name'] as String? : null,
      note: json['note'] as String?,
      hasAttachment: json['has_attachment'] == true,
      employeeName: employee is Map ? employee['name'] as String? : null,
      employeeNumber: employee is Map ? employee['employee_number'] as String? : null,
      approverName: approver is Map ? approver['name'] as String? : null,
      isManual: json['is_manual'] == true,
      submittedAt: json['submitted_at'] as String?,
      decidedAt: json['decided_at'] as String?,
      decidedByName: decidedBy is Map ? decidedBy['name'] as String? : null,
      decisionNote: json['decision_note'] as String?,
    );
  }

  final int id;
  final String number;

  /// `pending` / `approved` / `rejected` / `cancelled` (07 §6).
  final String status;
  final String workDate;

  /// `check_in` / `check_out` / `both`.
  final String type;

  /// `HH:mm` jam dinding lokal.
  final String? proposedCheckIn;
  final String? proposedCheckOut;
  final String? reasonName;
  final String? note;
  final bool hasAttachment;
  final String? employeeName;
  final String? employeeNumber;
  final String? approverName;
  final bool isManual;
  final String? submittedAt;
  final String? decidedAt;
  final String? decidedByName;
  final String? decisionNote;

  bool get isPending => status == 'pending';

  String get typeLabel => correctionTypeLabels[type] ?? type;

  /// "Masuk 07.55 · Pulang 16.30".
  String get proposedSummary => [
    if (proposedCheckIn != null) 'Masuk ${proposedCheckIn!.replaceAll(':', '.')}',
    if (proposedCheckOut != null) 'Pulang ${proposedCheckOut!.replaceAll(':', '.')}',
  ].join(' · ');
}

/// Jenis koreksi (05 §5.7 `type`).
const correctionTypeLabels = {'check_in': 'Jam masuk', 'check_out': 'Jam pulang', 'both': 'Masuk & pulang'};

/// Satu alasan koreksi aktif (`reference_items` grup `correction_reason`).
class CorrectionReason {
  const CorrectionReason(this.code, this.name);

  final String code;
  final String name;
}

/// `GET /api/v1/lookup/correction-reasons` (keputusan Mas 16 Nov 2026): item aktif urut
/// `sort_order`. Disimpan di memori selama sesi akun yang sama; galat dibiarkan sampai layar
/// (Coba Lagi = `ref.invalidate`), tidak ada daftar cadangan di HP agar tidak menyimpang dari
/// referensi yang diatur admin.
final correctionReasonsProvider = FutureProvider<List<CorrectionReason>>((ref) async {
  // Akun lain di HP yang sama → muat ulang.
  ref.watch(
    sessionProvider.select(
      (s) => switch (s.value) {
        SignedIn(:final user) => user.id,
        _ => null,
      },
    ),
  );
  final response = await ref.read(apiClientProvider).get('/api/v1/lookup/correction-reasons');
  final rows = response.data;
  return [
    if (rows is List)
      for (final row in rows)
        if (row is Map && row['code'] is String) CorrectionReason(row['code']! as String, row['name'] as String? ?? ''),
  ];
});
