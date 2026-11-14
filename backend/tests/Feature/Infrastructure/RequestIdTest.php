<?php

use App\Support\Api\RequestId;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * NFR-011: setiap request punya ID (ULID) yang sama di header respons, body galat, dan log.
 */

test('request tanpa X-Request-Id mendapat ULID baru di header respons', function () {
    $id = $this->get('/login')->headers->get('X-Request-Id');

    expect($id)->not->toBeNull()->and(Str::isUlid($id))->toBeTrue();
});

test('X-Request-Id ULID valid dari klien dipakai ulang', function () {
    $sent = (string) Str::ulid();

    $this->withHeader('X-Request-Id', $sent)->get('/login')
        ->assertHeader('X-Request-Id', $sent);
});

test('X-Request-Id tidak valid diganti, bukan dipantulkan', function (string $malicious) {
    $id = $this->withHeader('X-Request-Id', $malicious)->get('/login')->headers->get('X-Request-Id');

    expect($id)->not->toBe($malicious)->and(Str::isUlid($id))->toBeTrue();
})->with([
    'injeksi baris log' => "01JBX8Q6V7M3T2K9R4N5P6W8YZ\nINJECT",
    'terlalu panjang' => str_repeat('A', 500),
    'bukan ulid' => 'request-123',
]);

test('request_id tertulis di log JSON dan sama dengan header', function () {
    $path = storage_path('logs/test-request-id-'.Str::random(8).'.log');
    config(['logging.channels.json.path' => $path, 'logging.channels.json.driver' => 'single']);
    Log::forgetChannel('json');

    Route::get('/__test/log', function () {
        Log::channel('json')->info('uji request id');

        return response('ok');
    })->middleware('web');

    $id = $this->get('/__test/log')->headers->get('X-Request-Id');

    $line = collect(file($path))->last();
    $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

    expect($record['message'])->toBe('uji request id')
        ->and(data_get($record, 'extra.request_id') ?? data_get($record, 'context.request_id'))->toBe($id);

    @unlink($path);
});

test('RequestId::current() sama dengan header selama request', function () {
    Route::get('/__test/current', fn () => response(RequestId::current()))->middleware('web');

    $response = $this->get('/__test/current');

    expect($response->getContent())->toBe($response->headers->get('X-Request-Id'));
});
