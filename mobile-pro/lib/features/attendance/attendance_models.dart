/// Bentuk respons presensi API v1 (05 §5.4, §5.4a). Nilai jam disimpan apa adanya dari server
/// (sudah dalam `general.timezone`) dan ditampilkan lewat `Fmt.wallClock`.
library;

class ShiftInfo {
  const ShiftInfo({required this.code, required this.name, required this.start, required this.end});

  static ShiftInfo? fromJson(Object? json) {
    if (json is! Map) {
      return null;
    }
    return ShiftInfo(
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      start: json['start'] as String? ?? '',
      end: json['end'] as String? ?? '',
    );
  }

  final String code;
  final String name;

  /// `HH:MM` jam dinding lokal.
  final String start;
  final String end;

  Map<String, Object?> toJson() => {'code': code, 'name': name, 'start': start, 'end': end};
}

/// `data` 05 §5.4: satu hari kerja pegawai.
class AttendanceRecord {
  const AttendanceRecord({
    required this.workDate,
    required this.status,
    this.lateMinutes = 0,
    this.shift,
    this.checkInAt,
    this.checkOutAt,
    this.locationName,
    this.distanceM,
    this.isOffline = false,
  });

  static AttendanceRecord? fromJson(Object? json) {
    if (json is! Map) {
      return null;
    }
    final location = json['location'];
    return AttendanceRecord(
      workDate: json['work_date'] as String? ?? '',
      status: json['status'] as String? ?? '',
      lateMinutes: (json['late_minutes'] as num?)?.toInt() ?? 0,
      shift: ShiftInfo.fromJson(json['shift']),
      checkInAt: json['check_in_at'] as String?,
      checkOutAt: json['check_out_at'] as String?,
      locationName: location is Map ? location['name'] as String? : null,
      distanceM: location is Map ? (location['distance_m'] as num?)?.toInt() : null,
      isOffline: json['is_offline'] == true,
    );
  }

  final String workDate;

  /// Status harian (07 §6). Dihitung ulang server saat hari ditutup.
  final String status;
  final int lateMinutes;
  final ShiftInfo? shift;

  /// ISO-8601 dengan offset zona tampilan, atau null.
  final String? checkInAt;
  final String? checkOutAt;
  final String? locationName;
  final int? distanceM;
  final bool isOffline;

  Map<String, Object?> toJson() => {
    'work_date': workDate,
    'status': status,
    'late_minutes': lateMinutes,
    'shift': shift?.toJson(),
    'check_in_at': checkInAt,
    'check_out_at': checkOutAt,
    'location': locationName == null && distanceM == null ? null : {'name': locationName, 'distance_m': distanceM},
    'is_offline': isOffline,
  };
}

/// `data` 05 §5.4a: hari kerja "sekarang" untuk beranda.
class AttendanceToday {
  const AttendanceToday({
    required this.workDate,
    required this.scheduleKind,
    this.shift,
    this.holiday,
    this.attendance,
    this.nextAction,
  });

  static AttendanceToday? fromJson(Object? json) {
    if (json is! Map) {
      return null;
    }
    final schedule = json['schedule'];
    final next = json['next_action'];
    return AttendanceToday(
      workDate: json['work_date'] as String? ?? '',
      scheduleKind: schedule is Map ? schedule['kind'] as String? ?? 'unscheduled' : 'unscheduled',
      shift: schedule is Map ? ShiftInfo.fromJson(schedule['shift']) : null,
      holiday: schedule is Map ? schedule['holiday'] as String? : null,
      attendance: AttendanceRecord.fromJson(json['attendance']),
      nextAction: next == 'in' || next == 'out' ? next as String : null,
    );
  }

  final String workDate;

  /// `shift` / `holiday` / `off` / `unscheduled` (`EffectiveSchedule::kind`), atau
  /// [unknownKind] bila HP belum pernah mendapat jadwal dari server (hanya presensi lokal).
  final String scheduleKind;

  static const unknownKind = 'unknown';

  bool get isKnown => scheduleKind != unknownKind;
  final ShiftInfo? shift;
  final String? holiday;
  final AttendanceRecord? attendance;

  /// `in` / `out` / null (tidak ada jadwal, atau jendela pulang sudah lewat).
  final String? nextAction;

  AttendanceToday copyWith({AttendanceRecord? attendance, String? nextAction, bool clearNextAction = false}) =>
      AttendanceToday(
        workDate: workDate,
        scheduleKind: scheduleKind,
        shift: shift,
        holiday: holiday,
        attendance: attendance ?? this.attendance,
        nextAction: clearNextAction ? null : (nextAction ?? this.nextAction),
      );

  Map<String, Object?> toJson() => {
    'work_date': workDate,
    'schedule': {'kind': scheduleKind, 'shift': shift?.toJson(), 'holiday': holiday},
    'attendance': attendance?.toJson(),
    'next_action': nextAction,
  };
}
