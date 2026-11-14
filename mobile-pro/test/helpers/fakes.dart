import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:pakis_pro/core/device/native_device.dart';

/// Jawaban palsu server: status + body JSON (atau [error] untuk galat jaringan).
class FakeReply {
  const FakeReply(this.status, [this.body, this.error]);

  const FakeReply.offline() : status = 0, body = null, error = DioExceptionType.connectionError;

  final int status;
  final Object? body;
  final DioExceptionType? error;
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
    'auth': {
      'password_min_length': 10,
      'forgot_channels': ['email'],
    },
  },
  'meta': {'request_id': '01JBX8Q6V7M3T2K9R4N5P6W8YZ'},
};

Map<String, Object?> errorJson(String code, {Map<String, Object?> details = const {}}) => {
  'error': {'code': code, 'message': 'Pesan server.', 'details': details, 'request_id': '01JBX8Q6V7M3T2K9R4N5P6W8YZ'},
};
