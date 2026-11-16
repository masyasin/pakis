/// Tugas luar kota (BR-071, T-036A bagian 2). Bentuk = objek FieldTrip API v1
/// (`/api/v1/field-trips`, `/api/v1/approvals/field-trips`).
class FieldTrip {
  const FieldTrip({
    required this.id,
    required this.number,
    required this.status,
    required this.startDate,
    required this.endDate,
    required this.days,
    required this.destination,
    required this.attendanceRule,
    this.purpose,
    this.destinations = const [],
    this.employees = const [],
    this.requestedByName,
    this.isRequest = false,
    this.submittedAt,
    this.decidedAt,
    this.decidedByName,
    this.decisionNote,
  });

  factory FieldTrip.fromJson(Map<String, Object?> json) {
    String? nameOf(Object? v) => v is Map ? v['name'] as String? : null;
    return FieldTrip(
      id: (json['id'] as num?)?.toInt() ?? 0,
      number: json['number'] as String? ?? '',
      status: json['status'] as String? ?? '',
      startDate: json['start_date'] as String? ?? '',
      endDate: json['end_date'] as String? ?? '',
      days: (json['days'] as num?)?.toInt() ?? 1,
      destination: json['destination'] as String? ?? '',
      attendanceRule: json['attendance_rule'] as String? ?? '',
      purpose: json['purpose'] as String?,
      destinations: [
        for (final d in (json['destinations'] as List?) ?? const <Object?>[])
          if (d is Map) TripPoint(name: d['name'] as String? ?? '', radiusM: (d['radius_m'] as num?)?.toInt()),
      ],
      employees: [
        for (final e in (json['employees'] as List?) ?? const <Object?>[])
          if (e is Map) [e['name'], e['employee_number']].whereType<String>().join(' · '),
      ],
      requestedByName: nameOf(json['requested_by']),
      isRequest: json['is_request'] == true,
      submittedAt: json['submitted_at'] as String?,
      decidedAt: json['decided_at'] as String?,
      decidedByName: nameOf(json['decided_by']),
      decisionNote: json['decision_note'] as String?,
    );
  }

  final int id;
  final String number;

  /// `pending` / `approved` / `rejected` / `cancelled` (07 §6).
  final String status;

  /// `YYYY-MM-DD` tanggal lokal.
  final String startDate;
  final String endDate;
  final int days;
  final String destination;

  /// `anywhere` / `destination` / `any_location` (`FieldTripRule`).
  final String attendanceRule;
  final String? purpose;
  final List<TripPoint> destinations;

  /// "Nama · nomor pegawai".
  final List<String> employees;
  final String? requestedByName;
  final bool isRequest;
  final String? submittedAt;
  final String? decidedAt;
  final String? decidedByName;
  final String? decisionNote;

  bool get isPending => status == 'pending';

  String get ruleLabel => fieldTripRuleLabels[attendanceRule] ?? attendanceRule;
}

/// Titik tujuan (nama + radius); koordinat tidak dikirim balik ke HP.
class TripPoint {
  const TripPoint({required this.name, this.radiusM});

  final String name;
  final int? radiusM;
}

/// Label `FieldTripRule::label()` (sama dengan web).
const fieldTripRuleLabels = {
  'anywhere': 'Di mana saja',
  'destination': 'Radius titik tujuan',
  'any_location': 'Lokasi Pakis mana pun',
};

/// Koordinat dari tautan Google Maps yang ditempel pengguna.
typedef LatLng = ({double latitude, double longitude});

/// Mengurai tautan Google Maps lengkap: `…/@-6.80,110.84,17z`, `…?q=-6.80,110.84`,
/// `…?ll=…`, `…/place/Nama/@…`, `…!3d-6.80!4d110.84`, atau teks "lat, lng" langsung.
/// Tautan pendek (`maps.app.goo.gl`, `goo.gl/maps`) harus dibuka di server Google dulu,
/// jadi tidak bisa diurai di HP → null (layar meminta tautan lengkap).
LatLng? parseMapsLink(String input) {
  final text = Uri.decodeFull(input.trim());
  if (text.isEmpty) {
    return null;
  }
  const number = r'(-?\d{1,3}(?:\.\d+)?)';
  final patterns = [
    RegExp('!3d$number!4d$number'), // titik persis tempat (lebih akurat dari pusat peta @)
    RegExp('@$number,\\s*$number'),
    RegExp('[?&](?:q|ll|query|destination|center)=(?:loc:)?$number,\\s*$number'),
    RegExp('^$number\\s*,\\s*$number\$'),
  ];
  for (final pattern in patterns) {
    final match = pattern.firstMatch(text);
    if (match == null) {
      continue;
    }
    final lat = double.tryParse(match[1]!);
    final lng = double.tryParse(match[2]!);
    if (lat != null && lng != null && lat.abs() <= 90 && lng.abs() <= 180) {
      return (latitude: lat, longitude: lng);
    }
  }
  return null;
}

/// Tautan pendek Google Maps yang tidak bisa diurai tanpa jaringan ke Google.
bool isShortMapsLink(String input) => RegExp(r'(maps\.app\.goo\.gl|goo\.gl/maps)').hasMatch(input);
