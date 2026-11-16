import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:pakis_pro/core/device/native_device.dart';
import 'package:pakis_pro/features/auth/session.dart';

/// Jawaban palsu server: status + body JSON (atau [error] untuk galat jaringan).
class FakeReply {
  const FakeReply(this.status, [this.body, this.error]) : bytes = null, contentType = null;

  const FakeReply.offline()
    : status = 0,
      body = null,
      error = DioExceptionType.connectionError,
      bytes = null,
      contentType = null;

  /// Berkas biner (lampiran, foto profil).
  const FakeReply.bytes(this.bytes, {this.contentType = 'image/jpeg', this.status = 200}) : body = null, error = null;

  final int status;
  final Object? body;
  final DioExceptionType? error;
  final List<int>? bytes;
  final String? contentType;
}

/// Adapter Dio palsu: mencatat setiap request dan menjawab lewat [handler].
class FakeAdapter implements HttpClientAdapter {
  FakeAdapter(this.handler);

  FakeReply Function(RequestOptions options) handler;
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    final reply = handler(options);
    if (reply.error != null) {
      throw DioException(requestOptions: options, type: reply.error!);
    }
    if (reply.bytes != null) {
      return ResponseBody.fromBytes(
        reply.bytes!,
        reply.status,
        headers: {
          Headers.contentTypeHeader: [reply.contentType!],
          'cache-control': ['no-store'],
        },
      );
    }
    return ResponseBody.fromString(
      jsonEncode(reply.body),
      reply.status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

/// Native palsu. `sha256Hex` bukan SHA-256 sungguhan (itu `MessageDigest` di Kotlin), tetapi
/// deterministik 64 heksadesimal, dan setiap input dicatat agar rumus di Dart bisa diuji.
class FakeNativeDevice implements NativeDevice {
  FakeNativeDevice({this.androidIdValue = 'a1b2c3d4e5f60718'});

  String? androidIdValue;
  final hashInputs = <String>[];
  final openedUrls = <String>[];
  bool openSucceeds = true;

  @override
  Future<String?> androidId() async => androidIdValue;

  @override
  Future<String> sha256Hex(String input) async {
    hashInputs.add(input);
    return fakeHash(input);
  }

  @override
  Future<DeviceInfo> deviceInfo() async => const DeviceInfo(model: 'Samsung SM-A045F', osVersion: 'Android 13');

  @override
  Future<String> privateDir() async => 'memory';

  @override
  Future<bool> openUrl(String url) async {
    openedUrls.add(url);
    return openSucceeds;
  }

  static String fakeHash(String input) {
    final bytes = List<int>.filled(32, 0);
    final units = utf8.encode(input);
    for (var i = 0; i < units.length; i++) {
      bytes[i % 32] = (bytes[i % 32] * 31 + units[i] + i) & 0xFF;
    }
    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }
}

/// Respons `/config` sesuai `ConfigController::MAP` (05 §5.2).
Map<String, Object?> configJson({
  String minVersion = '1.0.0',
  String primaryColor = '#2F6B3B',
  String? downloadUrl,
  List<String> forgotChannels = const ['email'],
}) => {
  'data': {
    'app_name': 'PAKIS ONE',
    'company_name': 'CV Pakis Jaya Abadi',
    'timezone': 'Asia/Jakarta',
    'logo_url': null,
    'logo_dark_url': null,
    'favicon_url': null,
    'primary_color': primaryColor,
    'login_tagline': null,
    'mobile': {
      'min_version': minVersion,
      'latest_version': '1.0.3',
      'download_url': downloadUrl ?? 'https://pakis.example/unduh/pakis-pro.apk',
      'update_message': 'Versi baru tersedia.',
    },
    'attendance': {'require_selfie': true, 'max_accuracy_m': 100, 'offline_max_hours': 72, 'correction_max_days': 7},
    'auth': {'password_min_length': 10, 'forgot_channels': forgotChannels},
  },
  'meta': {'request_id': '01JBX8Q6V7M3T2K9R4N5P6W8YZ'},
};

Map<String, Object?> errorJson(String code, {Map<String, Object?> details = const {}}) => {
  'error': {'code': code, 'message': 'Pesan server.', 'details': details, 'request_id': '01JBX8Q6V7M3T2K9R4N5P6W8YZ'},
};

/// Pengganti hook sesi (sinkron antrean T-042): hanya mencatat panggilan.
class FakeSessionHooks implements SessionHooks {
  final calls = <String>[];

  @override
  Future<void> signedIn() async => calls.add('signedIn');

  @override
  Future<void> signedOut() async => calls.add('signedOut');
}

/// Pengguna `/auth/login` & `/me` (05 §5.3).
Map<String, Object?> userJson({bool mustChangePassword = false, String name = 'Agus Santoso'}) => {
  'id': 31,
  'name': name,
  'username': 'agus.s',
  'email': null,
  'phone': '6281234567890',
  'has_avatar': false,
  'must_change_password': mustChangePassword,
  'employee': {'id': 24, 'employee_number': 'PJA-260024', 'position': 'Sales', 'location': 'Kantor Pusat Kalirejo'},
  'permissions': ['attendance.self', 'attendance_corrections.create'],
};

Map<String, Object?> loginJson({bool mustChangePassword = false}) => {
  'data': {
    'token': '12|tokenuji',
    'token_type': 'Bearer',
    'expires_at': '2027-02-13T03:00:00+00:00',
    'user': userJson(mustChangePassword: mustChangePassword),
    'device': {'status': 'active'},
  },
  'meta': {'request_id': '01JBX8Q6V7M3T2K9R4N5P6W8YZ'},
};
