<?php

use App\Exceptions\InertiaApiErrorRenderer;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * PA-16 (T-067): satu jalur galat domain untuk rute web Inertia (15 §11.3). Route uji didaftarkan
 * di sini (pola ExportFlowTest) agar jalur diuji tanpa fitur Paket A nyata.
 */

beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('web')->prefix('__uji/galat')->group(function () {
        Route::post('domain', fn () => throw new ApiError(ErrorCode::APPROVAL_ALREADY_PENDING));
        Route::post('field', fn () => throw new ApiError(ErrorCode::VALIDATION_FAILED, details: [
            'note' => 'Catatan wajib diisi.',
            'lines' => ['Baris 2 belum lengkap.', 'Baris 3 belum lengkap.'],
            'distance_m' => 180,
            'meta' => ['number' => 7],
        ]));
        Route::post('pesan-publik', fn () => throw new ApiError(ErrorCode::STATE_CONFLICT, publicMessage: 'Langkah ini sudah diputuskan orang lain.'));
        Route::post('self', fn () => throw new ApiError(ErrorCode::APPROVAL_SELF_DECISION));
        Route::get('tidak-ada', fn () => throw new ApiError(ErrorCode::NOT_FOUND));
        Route::get('terlarang', fn () => throw new ApiError(ErrorCode::FORBIDDEN));
        Route::get('ulang', fn () => throw new ApiError(ErrorCode::STATE_CONFLICT));
    });
});

/** @return array<string, string> */
function inertiaHeaders(): array
{
    return ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];
}

test('PA-16 T-067 ApiError di request Inertia kembali ke form dengan galat umum + toast pesan kode, bukan 500', function () {
    $response = $this->from('/asal')->withHeaders(inertiaHeaders())->post('/__uji/galat/domain');

    $message = ErrorCode::APPROVAL_ALREADY_PENDING->message();
    $response->assertRedirect('/asal')
        ->assertSessionHasErrors([InertiaApiErrorRenderer::FORM_KEY => $message])
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', $message);

    // Pesan exception (nilai kode mentah) tidak pernah jadi teks untuk pengguna.
    expect(session('errors')->get(InertiaApiErrorRenderer::FORM_KEY))->not->toContain('APPROVAL_ALREADY_PENDING');
});

test('PA-16 T-067 details berbentuk field => pesan menjadi galat field; nilai bukan pesan diabaikan', function () {
    $response = $this->from('/asal')->withHeaders(inertiaHeaders())->post('/__uji/galat/field');

    $response->assertRedirect('/asal')
        ->assertSessionHasErrors(['note' => 'Catatan wajib diisi.', 'lines'])
        ->assertSessionDoesntHaveErrors(['distance_m', 'meta', InertiaApiErrorRenderer::FORM_KEY])
        ->assertInertiaFlash('toast.message', ErrorCode::VALIDATION_FAILED->message());

    expect(session('errors')->get('lines'))->toBe(['Baris 2 belum lengkap.', 'Baris 3 belum lengkap.']);
});

test('PA-16 T-067 publicMessage dari Action dipakai untuk toast dan galat', function () {
    $this->from('/asal')->withHeaders(inertiaHeaders())->post('/__uji/galat/pesan-publik')
        ->assertRedirect('/asal')
        ->assertSessionHasErrors([InertiaApiErrorRenderer::FORM_KEY => 'Langkah ini sudah diputuskan orang lain.'])
        ->assertInertiaFlash('toast.message', 'Langkah ini sudah diputuskan orang lain.');
});

test('PA-16 T-067 kode aturan bisnis berstatus 403 (APPROVAL_SELF_DECISION) tetap galat form, bukan halaman 403', function () {
    $this->from('/asal')->withHeaders(inertiaHeaders())->post('/__uji/galat/self')
        ->assertRedirect('/asal')
        ->assertSessionHasErrors([InertiaApiErrorRenderer::FORM_KEY => ErrorCode::APPROVAL_SELF_DECISION->message()])
        ->assertInertiaFlash('toast.message', ErrorCode::APPROVAL_SELF_DECISION->message());
});

test('PA-16 T-067 SEC-033 NOT_FOUND di web menjadi halaman 404 generik', function () {
    $this->get('/__uji/galat/tidak-ada')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 404));
});

test('PA-16 T-067 FORBIDDEN di web menjadi halaman 403 generik', function () {
    $this->get('/__uji/galat/terlarang')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 403));
});

test('PA-16 T-067 GET yang gagal ke URL yang sama diarahkan ke dasbor, tidak berputar', function () {
    $this->from('/__uji/galat/ulang')->get('/__uji/galat/ulang')
        ->assertRedirect(route('admin.dashboard'))
        ->assertInertiaFlash('toast.message', ErrorCode::STATE_CONFLICT->message());
});

test('PA-16 T-067 request web yang meminta JSON menerima format 05 §2.2', function () {
    $response = $this->postJson('/__uji/galat/field');

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('error.message', ErrorCode::VALIDATION_FAILED->message())
        ->assertJsonPath('error.details.note', 'Catatan wajib diisi.')
        ->assertJsonPath('error.request_id', $response->headers->get('X-Request-Id'));

    $this->getJson('/__uji/galat/tidak-ada')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

test('PA-16 T-067 rute api/* tetap memakai ApiExceptionRenderer (05 §2.2)', function () {
    Route::middleware('api')->get('api/v1/__uji/galat-web', fn () => throw new ApiError(ErrorCode::APPROVAL_RULE_MISSING));

    $this->get('/api/v1/__uji/galat-web')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'APPROVAL_RULE_MISSING')
        ->assertJsonPath('error.message', ErrorCode::APPROVAL_RULE_MISSING->message());
});

test('PA-32 T-067 kode galat web Paket A berstatus sesuai 15 §11.3', function () {
    expect(ErrorCode::APPROVAL_RULE_MISSING->status())->toBe(422)
        ->and(ErrorCode::APPROVAL_SELF_DECISION->status())->toBe(403)
        ->and(ErrorCode::APPROVAL_ALREADY_PENDING->status())->toBe(409);
});
