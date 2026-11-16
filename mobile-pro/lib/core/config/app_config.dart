import 'semver.dart';

/// Konfigurasi publik dari `GET /api/v1/config` (05 §5.2, FR-042).
///
/// Bentuk persis: `backend/app/Http/Controllers/Api/V1/ConfigController.php::MAP`. Parsing
/// toleran (nilai hilang/salah tipe → bawaan registri `08` §2) karena config lama yang
/// tersimpan bisa berasal dari versi server sebelumnya.
class AppConfig {
  const AppConfig({
    required this.appName,
    required this.companyName,
    required this.timezone,
    required this.primaryColor,
    required this.mobile,
    required this.attendance,
    required this.auth,
    this.logoUrl,
    this.logoDarkUrl,
    this.faviconUrl,
    this.loginTagline,
    this.raw = const {},
  });

  factory AppConfig.fromJson(Map<String, Object?> json) {
    Map<String, Object?> group(String key) {
      final value = json[key];
      return value is Map ? value.cast<String, Object?>() : const {};
    }

    final mobile = group('mobile');
    final attendance = group('attendance');
    final auth = group('auth');

    return AppConfig(
      appName: _string(json['app_name']) ?? 'PAKIS ONE',
      companyName: _string(json['company_name']) ?? '',
      timezone: _string(json['timezone']) ?? 'Asia/Jakarta',
      logoUrl: _string(json['logo_url']),
      logoDarkUrl: _string(json['logo_dark_url']),
      faviconUrl: _string(json['favicon_url']),
      primaryColor: _string(json['primary_color']) ?? '#2F6B3B',
      loginTagline: _string(json['login_tagline']),
      mobile: MobileConfig(
        minVersion: _string(mobile['min_version']) ?? '0.0.0',
        latestVersion: _string(mobile['latest_version']) ?? '0.0.0',
        downloadUrl: _string(mobile['download_url']),
        updateMessage: _string(mobile['update_message']),
      ),
      attendance: AttendanceConfig(
        requireSelfie: attendance['require_selfie'] is bool ? attendance['require_selfie']! as bool : true,
        maxAccuracyM: _int(attendance['max_accuracy_m']) ?? 100,
        offlineMaxHours: _int(attendance['offline_max_hours']) ?? 72,
        correctionMaxDays: _int(attendance['correction_max_days']) ?? 7,
        correctionAttachmentMaxKb: _int(attendance['correction_attachment_max_kb']) ?? 2048,
      ),
      auth: AuthConfig(
        passwordMinLength: _int(auth['password_min_length']) ?? 10,
        forgotChannels: auth['forgot_channels'] is List
            ? (auth['forgot_channels']! as List).whereType<String>().toList()
            : const ['email'],
      ),
      raw: json,
    );
  }

  final String appName;
  final String companyName;
  final String timezone;
  final String? logoUrl;
  final String? logoDarkUrl;
  final String? faviconUrl;

  /// `branding.primary_color` (`#RRGGBB`). Diolah menjadi tema oleh `BrandColor`.
  final String primaryColor;
  final String? loginTagline;
  final MobileConfig mobile;
  final AttendanceConfig attendance;
  final AuthConfig auth;

  /// JSON asli, disimpan apa adanya sebagai config terakhir.
  final Map<String, Object?> raw;

  /// Versi aplikasi di bawah `mobile.min_version` → wajib update (BR-027).
  bool requiresUpdate(String appVersion) => Semver.lessThan(appVersion, mobile.minVersion);

  static String? _string(Object? value) => value is String && value.isNotEmpty ? value : null;

  static int? _int(Object? value) => value is int ? value : (value is num ? value.toInt() : null);
}

class MobileConfig {
  const MobileConfig({required this.minVersion, required this.latestVersion, this.downloadUrl, this.updateMessage});

  final String minVersion;
  final String latestVersion;
  final String? downloadUrl;
  final String? updateMessage;
}

class AttendanceConfig {
  const AttendanceConfig({
    required this.requireSelfie,
    required this.maxAccuracyM,
    required this.offlineMaxHours,
    required this.correctionMaxDays,
    this.correctionAttachmentMaxKb = 2048,
  });

  final bool requireSelfie;
  final int maxAccuracyM;
  final int offlineMaxHours;
  final int correctionMaxDays;

  /// Batas lampiran koreksi (`attendance.correction_attachment_max_kb`, 05 §5.7).
  final int correctionAttachmentMaxKb;
}

class AuthConfig {
  const AuthConfig({required this.passwordMinLength, required this.forgotChannels});

  final int passwordMinLength;
  final List<String> forgotChannels;
}
