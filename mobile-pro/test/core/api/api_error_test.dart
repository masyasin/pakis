import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:pakis_pro/core/api/api_error.dart';

import '../../helpers/fakes.dart';

void main() {
  DioException bad(int status, Object? body) {
    final options = RequestOptions(path: '/x');
    return DioException(
      requestOptions: options,
      type: DioExceptionType.badResponse,
      response: Response<Object?>(requestOptions: options, statusCode: status, data: body),
    );
  }

  test('membaca format galat 05 §2.2', () {
    final e = ApiError.fromDio(bad(401, errorJson('DEVICE_MISMATCH')));

    expect(e.code, ApiErrorCode.deviceMismatch);
    expect(e.status, 401);
    expect(e.requestId, '01JBX8Q6V7M3T2K9R4N5P6W8YZ');
    expect(e.message, 'Sesi ini tidak berlaku di perangkat ini. Silakan masuk lagi ke akun.');
  });

  test('body bukan JSON galat (proxy 502/503) → ditebak dari status', () {
    expect(ApiError.fromDio(bad(503, '<html>')).code, ApiErrorCode.serviceUnavailable);
    expect(ApiError.fromDio(bad(502, null)).code, ApiErrorCode.serverError);
    expect(ApiError.fromDio(bad(426, null)).code, ApiErrorCode.appUpdateRequired);
    expect(ApiError.fromDio(bad(418, null)).code, ApiErrorCode.unknown);
  });

  test('kode tak dikenal dari server versi lebih baru → ditebak dari status', () {
    expect(ApiError.fromDio(bad(403, errorJson('KODE_BARU'))).code, ApiErrorCode.forbidden);
  });

  test('galat jaringan → pesan koneksi, bukan galat server', () {
    final options = RequestOptions(path: '/x');
    final offline = ApiError.fromDio(DioException(requestOptions: options, type: DioExceptionType.connectionError));
    final socket = ApiError.fromDio(DioException(requestOptions: options, error: const SocketException('putus')));
    final slow = ApiError.fromDio(DioException(requestOptions: options, type: DioExceptionType.receiveTimeout));

    expect(offline.code, ApiErrorCode.networkOffline);
    expect(socket.code, ApiErrorCode.networkOffline);
    expect(slow.code, ApiErrorCode.timeout);
    expect([offline, socket, slow].every((e) => e.isConnectivity), isTrue);
  });

  test('VALIDATION_FAILED → galat per field', () {
    final e = ApiError.fromDio(
      bad(
        422,
        errorJson(
          'VALIDATION_FAILED',
          details: {
            'proposed_check_in': ['Jam masuk wajib diisi.'],
          },
        ),
      ),
    );

    expect(e.fieldErrors, {
      'proposed_check_in': ['Jam masuk wajib diisi.'],
    });
  });

  test('ATTENDANCE_OUT_OF_RADIUS memakai jarak & nama lokasi (05 §2.3)', () {
    final e = ApiError.fromDio(
      bad(
        422,
        errorJson(
          'ATTENDANCE_OUT_OF_RADIUS',
          details: {'distance_m': 1250.4, 'radius_m': 100, 'location_name': 'Gudang Undaan'},
        ),
      ),
    );

    expect(e.message, 'Anda berjarak 1.250 m dari Gudang Undaan (batas 100 m). Mendekatlah ke lokasi lalu coba lagi.');
  });

  test('ACCOUNT_LOCKED / RATE_LIMITED menyebut kapan bisa mencoba lagi', () {
    expect(
      ApiError.fromDio(bad(423, errorJson('ACCOUNT_LOCKED', details: {'retry_after_s': 600}))).message,
      endsWith('Coba lagi dalam 10 menit.'),
    );
    expect(
      ApiError.fromDio(bad(429, errorJson('RATE_LIMITED', details: {'retry_after_s': 30}))).message,
      endsWith('Coba lagi dalam 30 detik.'),
    );
    expect(ApiError.fromDio(bad(429, errorJson('RATE_LIMITED'))).message, endsWith('Coba lagi nanti.'));
  });
}
