import 'dart:io';

import 'package:dio/dio.dart';

import '../env.dart';
import 'api_error.dart';
import 'ulid.dart';

/// Isi respons sukses API v1: `{ data, meta }` (05 §2.1, §3.3).
class ApiResponse {
  const ApiResponse(this.data, this.meta);

  final Object? data;
  final Map<String, Object?> meta;

  /// `data` sebagai objek. Melempar [ApiError] `unknown` bila bentuknya lain.
  Map<String, Object?> get object {
    final value = data;
    if (value is Map) {
      return value.cast<String, Object?>();
    }
    throw const ApiError(code: ApiErrorCode.unknown);
  }

  String? get requestId => meta['request_id'] as String?;
}

typedef TokenReader = Future<String?> Function();
typedef DeviceIdReader = Future<String> Function();

/// Klien HTTP tunggal untuk `/api/v1` (05 §2.4).
///
/// Setiap request membawa `Accept: application/json`, `X-App-Version`, `X-Device-Id`, dan
/// `X-Request-Id` (ULID baru), plus `Authorization` bila ada token. Semua kegagalan dilempar
/// sebagai [ApiError]; layar tidak pernah menangani `DioException` langsung.
class ApiClient {
  ApiClient({
    required String baseUrl,
    required String appVersion,
    required DeviceIdReader deviceId,
    TokenReader? token,
    this.onUpdateRequired,
    this.onSessionInvalid,
    this.onPasswordChangeRequired,
    this.onServerClock,
    HttpClientAdapter? adapter,
    String Function() requestId = newUlid,
    DateTime Function() now = DateTime.now,
  }) : _dio = Dio(
         BaseOptions(
           baseUrl: baseUrl,
           connectTimeout: AppEnv.connectTimeout,
           receiveTimeout: AppEnv.receiveTimeout,
           responseType: ResponseType.json,
           // `filter[status][]=late&filter[status][]=absent` (05 §3.1): kunci sudah memuat `[]`.
           listFormat: ListFormat.multi,
         ),
       ) {
    if (adapter != null) {
      _dio.httpClientAdapter = adapter;
    }
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          try {
            final bearer = token == null ? null : await token();
            options.headers.addAll({
              'Accept': 'application/json',
              'X-App-Version': appVersion,
              'X-Device-Id': await deviceId(),
              'X-Request-Id': requestId(),
              if (bearer != null && bearer.isNotEmpty) 'Authorization': 'Bearer $bearer',
            });
            handler.next(options);
          } catch (e) {
            handler.reject(DioException(requestOptions: options, error: e));
          }
        },
        onResponse: (response, handler) {
          _readServerClock(response.headers, now);
          handler.next(response);
        },
        onError: (error, handler) {
          final headers = error.response?.headers;
          if (headers != null) {
            _readServerClock(headers, now);
          }
          handler.next(error);
        },
      ),
    );
  }

  final Dio _dio;

  /// Dipanggil setiap kali server menjawab `426 APP_UPDATE_REQUIRED` (BR-027), dari endpoint
  /// mana pun, agar aplikasi langsung pindah ke layar wajib update.
  final void Function(ApiError error)? onUpdateRequired;

  /// Dipanggil bila request **ber-token** ditolak `401 UNAUTHENTICATED` / `DEVICE_MISMATCH`:
  /// token kedaluwarsa, dicabut admin, atau dipakai dari HP lain (BR-040). Sesi lokal dihapus.
  /// Login sendiri (tanpa token) tidak memicu ini: `INVALID_CREDENTIALS`/`MFA_*` bukan sesi.
  final void Function(ApiError error)? onSessionInvalid;

  /// Dipanggil saat server menjawab `403 PASSWORD_CHANGE_REQUIRED` (SEC-006) dari endpoint mana
  /// pun, agar aplikasi pindah ke layar wajib ganti password.
  final void Function(ApiError error)? onPasswordChangeRequired;

  /// Selisih detik jam server − jam HP dari header `Date` setiap respons (termasuk galat).
  /// Dikirim sebagai `device_clock_offset_s` saat absen (05 §5.4, BR-067).
  final void Function(int offsetSeconds)? onServerClock;

  void _readServerClock(Headers headers, DateTime Function() now) {
    final date = headers.value('date');
    if (date == null || onServerClock == null) {
      return;
    }
    try {
      onServerClock!(HttpDate.parse(date).difference(now()).inSeconds);
    } on FormatException {
      // Header rusak dari proxy: abaikan, pakai selisih terakhir yang diketahui.
    } on HttpException {
      // Sama seperti di atas (HttpDate.parse melempar HttpException untuk format salah).
    }
  }

  Future<ApiResponse> get(String path, {Map<String, Object>? query}) =>
      _send(() => _dio.get<Object?>(path, queryParameters: query));

  Future<ApiResponse> post(String path, {Object? data}) => _send(() => _dio.post<Object?>(path, data: data));

  Future<ApiResponse> put(String path, {Object? data}) => _send(() => _dio.put<Object?>(path, data: data));

  Future<ApiResponse> _send(Future<Response<Object?>> Function() call) async {
    final Response<Object?> response;
    try {
      response = await call();
    } on DioException catch (e) {
      final error = e.error is ApiError ? e.error! as ApiError : ApiError.fromDio(e);
      final hadToken = e.requestOptions.headers.containsKey('Authorization');
      switch (error.code) {
        case ApiErrorCode.appUpdateRequired:
          onUpdateRequired?.call(error);
        case ApiErrorCode.unauthenticated || ApiErrorCode.deviceMismatch when hadToken:
          onSessionInvalid?.call(error);
        case ApiErrorCode.passwordChangeRequired:
          onPasswordChangeRequired?.call(error);
        default:
          break;
      }
      throw error;
    }

    final body = response.data;
    if (body is! Map) {
      throw ApiError(code: ApiErrorCode.unknown, status: response.statusCode);
    }
    final meta = body['meta'];
    return ApiResponse(body['data'], meta is Map ? meta.cast<String, Object?>() : const {});
  }
}
