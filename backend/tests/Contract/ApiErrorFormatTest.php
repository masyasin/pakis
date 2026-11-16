<?php

use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/*
 * Kontrak format galat API (05 §2.2–2.3, SEC-061).
 * Route uji didaftarkan di sini agar setiap jenis exception bisa dipicu tanpa fitur nyata.
 */

beforeEach(function () {
    Route::prefix('api/v1/__test')->middleware('api')->group(function () {
        Route::post('validation', fn (Request $r) => $r->validate([
            'proposed_check_in' => ['required'],
        ]));
        Route::get('unauthenticated', fn () => throw new AuthenticationException);
        Route::get('forbidden', fn () => throw new AuthorizationException('Rahasia internal'));
        Route::get('model', fn () => throw (new ModelNotFoundException)->setModel('App\\Models\\User', [7]));
        Route::get('conflict', fn () => throw new ConflictHttpException);
        Route::get('throttled', fn () => throw new ThrottleRequestsException(headers: ['Retry-After' => 42]));
        Route::get('bad-request', fn () => throw new BadRequestHttpException);
        Route::get('too-large', fn () => throw new PostTooLargeException);
        Route::get('maintenance', fn () => throw new ServiceUnavailableHttpException);
        Route::get('domain', fn () => throw new ApiError(ErrorCode::ATTENDANCE_OUT_OF_RADIUS, details: [
            'distance_m' => 180, 'radius_m' => 100, 'location_name' => 'Gudang Uji',
        ]));
        Route::get('boom', fn () => throw new RuntimeException('SQLSTATE[42S02] tabel users_secret tidak ada'));
        Route::get('only-get', fn () => 'ok');
    });
});

function expectApiError($response, ErrorCode $code, int $status): void
{
    $response->assertStatus($status)
        ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']])
        ->assertJsonPath('error.code', $code->value);

    expect($response->json('error.request_id'))->toBe($response->headers->get('X-Request-Id'))
        ->and($response->json('error.message'))->toBeString()->not->toBeEmpty();
}

test('SEC-061 galat validasi memakai format 05 §2.2 dengan details per field', function () {
    $response = $this->postJson('/api/v1/__test/validation', []);

    expectApiError($response, ErrorCode::VALIDATION_FAILED, 422);
    expect($response->json('error.details.proposed_check_in'))->toBeArray()->not->toBeEmpty();
});

test('SEC-061 exception umum dipetakan ke kode kontrak', function (string $path, ErrorCode $code, int $status) {
    expectApiError($this->getJson("/api/v1/__test/{$path}"), $code, $status);
})->with([
    'unauthenticated' => ['unauthenticated', ErrorCode::UNAUTHENTICATED, 401],
    'forbidden' => ['forbidden', ErrorCode::FORBIDDEN, 403],
    'model tidak ada' => ['model', ErrorCode::NOT_FOUND, 404],
    'route tidak ada' => ['tidak-ada-sama-sekali', ErrorCode::NOT_FOUND, 404],
    'konflik' => ['conflict', ErrorCode::STATE_CONFLICT, 409],
    'bad request' => ['bad-request', ErrorCode::BAD_REQUEST, 400],
    'terlalu besar' => ['too-large', ErrorCode::PAYLOAD_TOO_LARGE, 413],
    'maintenance' => ['maintenance', ErrorCode::SERVICE_UNAVAILABLE, 503],
    'galat domain' => ['domain', ErrorCode::ATTENDANCE_OUT_OF_RADIUS, 422],
]);

test('SEC-061 method salah menjadi METHOD_NOT_ALLOWED', function () {
    expectApiError($this->postJson('/api/v1/__test/only-get'), ErrorCode::METHOD_NOT_ALLOWED, 405);
});

test('SEC-061 rate limit menyertakan retry_after_s', function () {
    $response = $this->getJson('/api/v1/__test/throttled');

    expectApiError($response, ErrorCode::RATE_LIMITED, 429);
    expect($response->json('error.details.retry_after_s'))->toBe(42);
});

test('SEC-061 galat domain membawa details-nya', function () {
    $response = $this->getJson('/api/v1/__test/domain');

    expect($response->json('error.details'))->toBe([
        'distance_m' => 180, 'radius_m' => 100, 'location_name' => 'Gudang Uji',
    ]);
});

test('SEC-061 pesan internal exception tidak bocor ke klien', function () {
    $body = $this->getJson('/api/v1/__test/forbidden')->getContent();

    expect($body)->not->toContain('Rahasia internal');
});

test('SEC-061 galat tak terduga menjadi SERVER_ERROR tanpa trace, juga saat debug', function (bool $debug) {
    config(['app.debug' => $debug]);

    $response = $this->getJson('/api/v1/__test/boom');

    expectApiError($response, ErrorCode::SERVER_ERROR, 500);
    expect($response->json())->toHaveKeys(['error'])->not->toHaveKeys(['exception', 'trace', 'file', 'line']);
    expect($response->getContent())
        ->not->toContain('SQLSTATE')
        ->not->toContain('users_secret')
        ->not->toContain('RuntimeException')
        ->not->toContain('.php');
})->with(['debug mati' => false, 'debug nyala' => true]);

test('SEC-061 galat server tercatat di log JSON dengan request_id yang sama dengan respons', function () {
    $path = storage_path('logs/test-server-error-'.Str::random(8).'.log');
    config([
        'logging.default' => 'json',
        'logging.channels.json.driver' => 'single',
        'logging.channels.json.path' => $path,
    ]);
    Log::forgetChannel('json');

    $response = $this->getJson('/api/v1/__test/boom');
    $requestId = $response->json('error.request_id');

    $records = collect(file($path))->map(fn ($line) => json_decode($line, true));
    $error = $records->first(fn ($r) => ($r['level_name'] ?? null) === 'ERROR');

    // Detail teknis ada di log (untuk developer), dikorelasikan lewat request_id, bukan di respons.
    expect($error)->not->toBeNull()
        ->and(data_get($error, 'extra.request_id') ?? data_get($error, 'context.request_id'))->toBe($requestId)
        ->and($error['message'])->toContain('users_secret');

    @unlink($path);
});

test('galat domain ApiError tidak dicatat sebagai error', function () {
    $path = storage_path('logs/test-domain-'.Str::random(8).'.log');
    config([
        'logging.default' => 'json',
        'logging.channels.json.driver' => 'single',
        'logging.channels.json.path' => $path,
    ]);
    Log::forgetChannel('json');

    $this->getJson('/api/v1/__test/domain')->assertStatus(422);

    expect(file_exists($path) ? trim(file_get_contents($path)) : '')->toBe('');

    @unlink($path);
});

/**
 * Baris tabel | `KODE` | HTTP | di satu bagian 05: dari heading `$heading` sampai heading berikutnya.
 *
 * @return array<string, int>
 */
function documentedErrorCodes(string $markdown, string $heading): array
{
    $section = str($markdown)->after($heading)->before("\n#")->toString();
    preg_match_all('/^\|\s*`([A-Z_]+)`\s*\|\s*(\d{3})\s*\|/m', $section, $m, PREG_SET_ORDER);

    return collect($m)->mapWithKeys(fn ($row) => [$row[1] => (int) $row[2]])->all();
}

test('PA-32 enum ErrorCode identik dengan tabel 05 §2.3 + §2.4a (kode khusus web)', function () {
    $markdown = file_get_contents(base_path('../docs/05-API-CONTRACT.md'));

    $api = documentedErrorCodes($markdown, '### 2.3 Kode Galat');
    // §2.4a sengaja di luar rentang §2.3–§2.4 yang dibaca test kontrak Dart (PA-32).
    $web = documentedErrorCodes($markdown, '### 2.4a Kode Galat Khusus Web');

    expect($api)->not->toBeEmpty()
        ->and($web)->not->toBeEmpty()
        ->and(array_intersect_key($api, $web))->toBe([]);

    $documented = collect($api + $web)->sortKeys()->all();
    $implemented = collect(ErrorCode::cases())->mapWithKeys(fn ($c) => [$c->value => $c->status()])->sortKeys()->all();

    expect($documented)->not->toBeEmpty()
        ->and($implemented)->toBe($documented);
});

test('setiap kode punya pesan Bahasa Indonesia yang tidak kosong', function () {
    foreach (ErrorCode::cases() as $code) {
        expect($code->message())->toBeString()->not->toBeEmpty();
    }
});
