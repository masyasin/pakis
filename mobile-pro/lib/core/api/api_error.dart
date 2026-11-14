import 'dart:io';

import 'package:dio/dio.dart';
import 'package:intl/intl.dart';

/// Kode galat API (05 §2.3) dengan pesan Bahasa Indonesia untuk pengguna akhir.
///
/// Kode server (`server: true`) dicocokkan dua arah dengan tabel 05 §2.3 oleh
/// `test/core/api/api_error_contract_test.dart`, sama seperti enum `ErrorCode` di backend.
/// Menambah kode di server berarti menambahkannya di sini juga.
///
/// Pesan dipetakan di klien (bukan memakai `error.message` server) agar kalimatnya konsisten
/// dengan layar mobile dan tetap ada ketika jaringan putus sebelum server menjawab.
enum ApiErrorCode {
  validationFailed('VALIDATION_FAILED', 'Data yang dikirim belum benar. Periksa isian yang ditandai.'),
  unauthenticated('UNAUTHENTICATED', 'Sesi Anda sudah berakhir. Silakan masuk lagi.'),
  invalidCredentials('INVALID_CREDENTIALS', 'Username/email atau password salah.'),
  mfaRequired('MFA_REQUIRED', 'Masukkan kode 6 digit dari aplikasi authenticator.'),
  mfaInvalid('MFA_INVALID', 'Kode authenticator atau kode pemulihan salah.'),
  mfaSetupRequired(
    'MFA_SETUP_REQUIRED',
    'Akun Anda wajib memakai verifikasi dua langkah. Aktifkan dulu di web: Profil → Keamanan.',
  ),
  accountLocked('ACCOUNT_LOCKED', 'Akun terkunci sementara karena terlalu banyak percobaan masuk.'),
  passwordChangeRequired('PASSWORD_CHANGE_REQUIRED', 'Anda wajib mengganti password sebelum melanjutkan.'),
  devicePending('DEVICE_PENDING', 'Perangkat ini menunggu persetujuan admin.'),
  deviceMismatch('DEVICE_MISMATCH', 'Sesi ini tidak berlaku di perangkat ini. Silakan masuk lagi.'),
  appUpdateRequired(
    'APP_UPDATE_REQUIRED',
    'Versi Pakis Pro ini sudah tidak didukung. Perbarui aplikasi untuk melanjutkan.',
  ),
  forbidden('FORBIDDEN', 'Anda tidak punya akses untuk tindakan ini.'),
  notFound('NOT_FOUND', 'Data tidak ditemukan.'),
  rateLimited('RATE_LIMITED', 'Terlalu banyak permintaan dalam waktu singkat.'),
  stateConflict('STATE_CONFLICT', 'Data sudah berubah. Muat ulang lalu coba lagi.'),
  attendanceNoSchedule('ATTENDANCE_NO_SCHEDULE', 'Tidak ada jadwal kerja untuk Anda hari ini.'),
  attendanceOutsideWindow('ATTENDANCE_OUTSIDE_WINDOW', 'Sekarang di luar jam absen untuk shift Anda.'),
  attendanceAlreadyCheckedIn('ATTENDANCE_ALREADY_CHECKED_IN', 'Anda sudah absen masuk hari ini.'),
  attendanceNotCheckedIn('ATTENDANCE_NOT_CHECKED_IN', 'Anda belum absen masuk hari ini.'),
  attendanceOutOfRadius('ATTENDANCE_OUT_OF_RADIUS', 'Anda berada di luar radius lokasi kerja.'),
  attendanceMockLocation(
    'ATTENDANCE_MOCK_LOCATION',
    'Lokasi palsu terdeteksi. Matikan aplikasi pengubah lokasi lalu coba lagi.',
  ),
  attendanceLowAccuracy('ATTENDANCE_LOW_ACCURACY', 'Sinyal GPS lemah. Pindah ke tempat terbuka lalu coba lagi.'),
  attendanceOfflineTooOld(
    'ATTENDANCE_OFFLINE_TOO_OLD',
    'Absen offline ini sudah terlalu lama tersimpan dan tidak bisa dikirim. Ajukan koreksi absen.',
  ),
  correctionDuplicatePending('CORRECTION_DUPLICATE_PENDING', 'Sudah ada koreksi yang menunggu untuk tanggal itu.'),
  correctionNotAllowed('CORRECTION_NOT_ALLOWED', 'Tanggal ini tidak bisa diajukan koreksi.'),
  correctionSelfApproval('CORRECTION_SELF_APPROVAL', 'Anda tidak bisa menyetujui koreksi milik sendiri.'),
  channelUnavailable(
    'CHANNEL_UNAVAILABLE',
    'Kanal pengiriman kode sedang tidak tersedia. Pilih kanal lain atau hubungi admin.',
  ),
  badRequest('BAD_REQUEST', 'Permintaan tidak bisa diproses. Perbarui aplikasi bila masalah berlanjut.'),
  methodNotAllowed('METHOD_NOT_ALLOWED', 'Permintaan tidak didukung server. Perbarui aplikasi bila masalah berlanjut.'),
  payloadTooLarge('PAYLOAD_TOO_LARGE', 'Ukuran berkas terlalu besar untuk dikirim.'),
  serviceUnavailable('SERVICE_UNAVAILABLE', 'Server sedang dalam perawatan. Coba lagi beberapa menit lagi.'),
  serverError('SERVER_ERROR', 'Terjadi kesalahan di server. Coba lagi nanti.'),

  // Khusus klien: server tidak pernah mengirim kode ini.
  networkOffline('NETWORK_OFFLINE', 'Tidak ada koneksi ke server. Periksa data seluler atau Wi-Fi.', server: false),
  timeout('TIMEOUT', 'Server terlalu lama merespons. Coba lagi.', server: false),
  unknown('UNKNOWN', 'Respons server tidak dikenali. Coba lagi.', server: false);

  const ApiErrorCode(this.wire, this.defaultMessage, {this.server = true});

  /// Nilai `error.code` di JSON.
  final String wire;
  final String defaultMessage;

  /// `false` = kode buatan klien (galat jaringan), tidak ada di 05 §2.3.
  final bool server;

  static ApiErrorCode? fromWire(String? wire) {
    for (final code in values) {
      if (code.server && code.wire == wire) {
        return code;
      }
    }
    return null;
  }
}

/// Galat dari API v1 (05 §2.2) atau dari jaringan, siap ditampilkan ke pengguna.
class ApiError implements Exception {
  const ApiError({required this.code, this.status, this.details = const {}, this.requestId, this.serverMessage});

  /// Bentuk galat 05 §2.2: `{ "error": { code, message, details, request_id } }`.
  factory ApiError.fromResponse(int? status, Object? body, {String? requestIdHeader}) {
    final error = body is Map ? body['error'] : null;
    if (error is Map) {
      final details = error['details'];
      return ApiError(
        code: ApiErrorCode.fromWire(error['code'] as String?) ?? _fromStatus(status),
        status: status,
        details: details is Map ? details.cast<String, Object?>() : const {},
        requestId: (error['request_id'] as String?) ?? requestIdHeader,
        serverMessage: error['message'] as String?,
      );
    }

    // Bukan JSON galat API (mis. halaman HTML proxy 502): tebak dari status.
    return ApiError(code: _fromStatus(status), status: status, requestId: requestIdHeader);
  }

  factory ApiError.fromDio(DioException e) {
    switch (e.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
      case DioExceptionType.transformTimeout:
        return const ApiError(code: ApiErrorCode.timeout);
      case DioExceptionType.connectionError:
        return const ApiError(code: ApiErrorCode.networkOffline);
      case DioExceptionType.badResponse:
        final response = e.response;
        return ApiError.fromResponse(
          response?.statusCode,
          response?.data,
          requestIdHeader: response?.headers.value('x-request-id'),
        );
      case DioExceptionType.unknown:
        return e.error is SocketException
            ? const ApiError(code: ApiErrorCode.networkOffline)
            : const ApiError(code: ApiErrorCode.unknown);
      case DioExceptionType.badCertificate:
      case DioExceptionType.cancel:
        return const ApiError(code: ApiErrorCode.unknown);
    }
  }

  final ApiErrorCode code;
  final int? status;
  final Map<String, Object?> details;
  final String? requestId;

  /// Pesan asli server, hanya untuk diagnosis. Yang ditampilkan adalah [message].
  final String? serverMessage;

  /// Galat yang berarti server tidak terjangkau (layar boleh memakai data tersimpan).
  bool get isConnectivity => code == ApiErrorCode.networkOffline || code == ApiErrorCode.timeout;

  /// Pesan untuk pengguna, dengan detail dari `details` bila ada (05 §2.3).
  String get message {
    switch (code) {
      case ApiErrorCode.attendanceOutOfRadius:
        final distance = _number(details['distance_m']);
        final radius = _number(details['radius_m']);
        final location = details['location_name'];
        if (distance != null && radius != null && location is String) {
          return 'Anda berjarak ${_format(distance)} m dari $location (batas ${_format(radius)} m). '
              'Mendekatlah ke lokasi lalu coba lagi.';
        }
        return code.defaultMessage;
      case ApiErrorCode.accountLocked:
      case ApiErrorCode.rateLimited:
        final retry = _number(details['retry_after_s']);
        return retry == null
            ? '${code.defaultMessage} Coba lagi nanti.'
            : '${code.defaultMessage} ${_retryText(retry)}';
      default:
        return code.defaultMessage;
    }
  }

  /// Galat per field untuk `VALIDATION_FAILED` (`details` = `{ field: [pesan] }`).
  Map<String, List<String>> get fieldErrors {
    if (code != ApiErrorCode.validationFailed) {
      return const {};
    }
    return {
      for (final entry in details.entries)
        if (entry.value is List) entry.key: (entry.value! as List).whereType<String>().toList(),
    };
  }

  @override
  String toString() => 'ApiError(${code.wire}, status: $status, request_id: $requestId)';

  static ApiErrorCode _fromStatus(int? status) => switch (status) {
    400 => ApiErrorCode.badRequest,
    401 => ApiErrorCode.unauthenticated,
    403 => ApiErrorCode.forbidden,
    404 => ApiErrorCode.notFound,
    405 => ApiErrorCode.methodNotAllowed,
    413 => ApiErrorCode.payloadTooLarge,
    426 => ApiErrorCode.appUpdateRequired,
    429 => ApiErrorCode.rateLimited,
    503 => ApiErrorCode.serviceUnavailable,
    final int s when s >= 500 => ApiErrorCode.serverError,
    _ => ApiErrorCode.unknown,
  };

  static num? _number(Object? value) => value is num ? value : null;

  static String _format(num value) => NumberFormat.decimalPattern('id_ID').format(value.round());

  static String _retryText(num seconds) {
    if (seconds < 60) {
      return 'Coba lagi dalam ${seconds.ceil()} detik.';
    }
    return 'Coba lagi dalam ${(seconds / 60).ceil()} menit.';
  }
}
