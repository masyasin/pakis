import 'package:flutter_test/flutter_test.dart';
import 'package:pakis_pro/core/api/api_client.dart';
import 'package:pakis_pro/core/api/api_error.dart';
import 'package:pakis_pro/core/api/ulid.dart';

import '../../helpers/fakes.dart';

void main() {
  late FakeAdapter adapter;
  ApiError? updateRequired;

  ApiClient client({String? token}) => ApiClient(
    baseUrl: 'https://pakis.test',
    appVersion: '1.0.3',
    deviceId: () async => 'f' * 64,
    token: () async => token,
    adapter: adapter,
    onUpdateRequired: (e) => updateRequired = e,
  );

  setUp(() {
    updateRequired = null;
    adapter = FakeAdapter(
      (_) => const FakeReply(200, {
        'data': {'ok': true},
        'meta': {'request_id': '01JBX8Q6V7M3T2K9R4N5P6W8YZ'},
      }),
    );
  });

  test('mengirim header wajib 05 §2.4', () async {
    await client().get('/api/v1/config');

    final headers = adapter.requests.single.headers;
    expect(headers['Accept'], 'application/json');
    expect(headers['X-App-Version'], '1.0.3');
    expect(headers['X-Device-Id'], 'f' * 64);
    expect(isUlid(headers['X-Request-Id'] as String), isTrue, reason: 'server hanya menerima ULID');
    expect(headers.containsKey('Authorization'), isFalse, reason: 'tanpa token tidak ada Authorization');
  });

  test('X-Request-Id baru di setiap request', () async {
    final api = client();
    await api.get('/a');
    await api.get('/b');

    expect(adapter.requests[0].headers['X-Request-Id'], isNot(adapter.requests[1].headers['X-Request-Id']));
  });

  test('token ada → Authorization Bearer', () async {
    await client(token: 'token-uji').get('/api/v1/me');

    expect(adapter.requests.single.headers['Authorization'], 'Bearer token-uji');
  });

  test('membuka amplop { data, meta }', () async {
    final response = await client().get('/x');

    expect(response.object, {'ok': true});
    expect(response.requestId, '01JBX8Q6V7M3T2K9R4N5P6W8YZ');
  });

  test('filter daftar dikirim sebagai filter[k][] berulang (05 §3.1)', () async {
    await client().get(
      '/api/v1/attendance',
      query: {
        'filter[status][]': ['late', 'absent'],
        'filter[work_date][from]': '2026-11-01',
      },
    );

    final uri = adapter.requests.single.uri;
    expect(uri.queryParametersAll['filter[status][]'], ['late', 'absent']);
    expect(uri.queryParameters['filter[work_date][from]'], '2026-11-01');
  });

  test('426 dari endpoint mana pun → ApiError + gerbang update dipicu (BR-027)', () async {
    adapter.handler = (_) =>
        FakeReply(426, errorJson('APP_UPDATE_REQUIRED', details: {'download_url': 'https://pakis.example/a.apk'}));

    await expectLater(
      client().get('/api/v1/attendance/today'),
      throwsA(isA<ApiError>().having((e) => e.code, 'code', ApiErrorCode.appUpdateRequired)),
    );
    expect(updateRequired?.details['download_url'], 'https://pakis.example/a.apk');
  });

  test('galat lain tidak memicu gerbang update', () async {
    adapter.handler = (_) => FakeReply(403, errorJson('FORBIDDEN'));

    await expectLater(client().get('/x'), throwsA(isA<ApiError>()));
    expect(updateRequired, isNull);
  });

  test('gagal membaca device id → ApiError, bukan crash', () async {
    final api = ApiClient(
      baseUrl: 'https://pakis.test',
      appVersion: '1.0.0',
      deviceId: () async => throw StateError('kanal native tidak ada'),
      adapter: adapter,
    );

    await expectLater(api.get('/x'), throwsA(isA<ApiError>()));
    expect(adapter.requests, isEmpty, reason: 'request tanpa X-Device-Id tidak boleh terkirim');
  });

  test('getBytes: berkas biner + Content-Type tanpa parameter, header wajib ikut', () async {
    adapter.handler = (_) => const FakeReply.bytes([1, 2, 3], contentType: 'image/png');

    final file = await client(token: '12|tokenuji').getBytes('/api/v1/me/avatar');

    expect(file.bytes, [1, 2, 3]);
    expect(file.contentType, 'image/png');
    expect(file.isImage, isTrue);
    expect(adapter.requests.single.headers['Authorization'], 'Bearer 12|tokenuji');
    expect(adapter.requests.single.headers['X-Device-Id'], 'f' * 64);
  });

  test('getBytes: galat JSON tetap terbaca (404) dan 426 memicu gerbang update', () async {
    adapter.handler = (_) => FakeReply(404, errorJson('NOT_FOUND'));
    await expectLater(
      client().getBytes('/api/v1/attendance-corrections/9/attachment'),
      throwsA(isA<ApiError>().having((e) => e.code, 'code', ApiErrorCode.notFound)),
    );

    adapter.handler = (_) => FakeReply(426, errorJson('APP_UPDATE_REQUIRED'));
    await expectLater(client().getBytes('/api/v1/me/avatar'), throwsA(isA<ApiError>()));
    expect(updateRequired?.code, ApiErrorCode.appUpdateRequired);
  });
}
