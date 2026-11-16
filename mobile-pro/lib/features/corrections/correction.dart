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

/// Alasan koreksi (`reference_items` grup `correction_reason`, nilai awal `ReferenceSeeder`).
///
/// API v1 belum punya lookup referensi untuk HP (05 §5.1), jadi daftar ini salinan nilai
/// seeder. Server tetap memvalidasi kode aktif (`reason_code.exists`): kode yang dinonaktifkan
/// admin ditolak dengan pesan "Alasan koreksi tidak dikenal". Ganti dengan lookup API bila
/// endpointnya ditambahkan (catatan T-043).
const correctionReasons = <(String, String)>[
  ('forgot', 'Lupa presensi'),
  ('gps_error', 'GPS/HP bermasalah'),
  ('field_duty', 'Tugas luar'),
  ('system_error', 'Gangguan sistem'),
  ('phone_broken', 'HP rusak'),
  ('phone_lost', 'HP hilang'),
  ('phone_left', 'HP ketinggalan'),
  ('other', 'Lainnya'),
];
