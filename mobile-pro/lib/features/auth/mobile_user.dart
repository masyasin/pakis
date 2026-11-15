/// Pengguna Pakis Pro dari `POST /auth/login` dan `GET /me` (05 §5.3, `docs/openapi.yaml`).
class MobileUser {
  const MobileUser({
    required this.id,
    required this.name,
    required this.username,
    required this.mustChangePassword,
    required this.permissions,
    this.email,
    this.phone,
    this.hasAvatar = false,
    this.employee,
  });

  factory MobileUser.fromJson(Map<String, Object?> json) {
    final employee = json['employee'];
    final permissions = json['permissions'];
    return MobileUser(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: json['name'] as String? ?? '',
      username: json['username'] as String? ?? '',
      email: json['email'] as String?,
      phone: json['phone'] as String?,
      hasAvatar: json['has_avatar'] == true,
      mustChangePassword: json['must_change_password'] == true,
      employee: employee is Map ? MobileEmployee.fromJson(employee.cast<String, Object?>()) : null,
      permissions: permissions is List ? permissions.whereType<String>().toList() : const [],
    );
  }

  final int id;
  final String name;
  final String username;
  final String? email;
  final String? phone;
  final bool hasAvatar;
  final bool mustChangePassword;
  final MobileEmployee? employee;

  /// Permission mobile (= ability token): `attendance.self`, `attendance_corrections.*`.
  final List<String> permissions;

  bool can(String permission) => permissions.contains(permission);

  /// Baris kecil di bawah nama: "Sales · Kantor Pusat Kalirejo".
  String? get subtitle {
    final parts = [employee?.position, employee?.location].whereType<String>().where((p) => p.isNotEmpty);
    return parts.isEmpty ? null : parts.join(' · ');
  }

  MobileUser copyWith({bool? mustChangePassword}) => MobileUser(
    id: id,
    name: name,
    username: username,
    email: email,
    phone: phone,
    hasAvatar: hasAvatar,
    mustChangePassword: mustChangePassword ?? this.mustChangePassword,
    employee: employee,
    permissions: permissions,
  );

  Map<String, Object?> toJson() => {
    'id': id,
    'name': name,
    'username': username,
    'email': email,
    'phone': phone,
    'has_avatar': hasAvatar,
    'must_change_password': mustChangePassword,
    'employee': employee?.toJson(),
    'permissions': permissions,
  };
}

class MobileEmployee {
  const MobileEmployee({required this.id, required this.employeeNumber, this.position, this.location});

  factory MobileEmployee.fromJson(Map<String, Object?> json) => MobileEmployee(
    id: (json['id'] as num?)?.toInt() ?? 0,
    employeeNumber: json['employee_number'] as String? ?? '',
    position: json['position'] as String?,
    location: json['location'] as String?,
  );

  final int id;
  final String employeeNumber;
  final String? position;
  final String? location;

  Map<String, Object?> toJson() => {
    'id': id,
    'employee_number': employeeNumber,
    'position': position,
    'location': location,
  };
}
