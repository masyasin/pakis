<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * SEC-061: halaman galat web generik, tanpa detail teknis saat debug mati.
 */

beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('web')->group(function () {
        Route::get('/__test/boom', fn () => throw new RuntimeException('SQLSTATE rahasia tabel_internal'));
        Route::post('/__test/csrf', fn () => throw new TokenMismatchException);
    });
});

test('SEC-061 halaman 404 web generik', function () {
    $this->get('/halaman-yang-tidak-ada')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 404)
            ->has('requestId'));
});

test('SEC-061 halaman 500 web tanpa detail teknis', function () {
    $response = $this->get('/__test/boom');

    $response->assertStatus(500)
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 500));

    expect($response->getContent())
        ->not->toContain('SQLSTATE')
        ->not->toContain('tabel_internal')
        ->not->toContain('RuntimeException');
});

test('SEC-061 request_id di halaman galat sama dengan header', function () {
    $response = $this->get('/halaman-yang-tidak-ada');

    $response->assertInertia(fn (Assert $page) => $page
        ->where('requestId', $response->headers->get('X-Request-Id')));
});

test('sesi kedaluwarsa (419) kembali ke halaman sebelumnya, bukan halaman galat', function () {
    $this->from('/login')->post('/__test/csrf')->assertRedirect('/login');
});

test('SEC-061 saat debug nyala, galat web tetap ditangani Laravel (untuk developer)', function () {
    config(['app.debug' => true]);

    $this->get('/__test/boom')->assertStatus(500);
});

test('CSRF aktif di grup middleware web', function () {
    $web = app('router')->getMiddlewareGroups()['web'];

    expect($web)->toContain(PreventRequestForgery::class);
});
