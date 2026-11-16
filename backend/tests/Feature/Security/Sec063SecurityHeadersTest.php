<?php

/*
 * SEC-063: security headers di respons web. CSP memakai nonce per request; modenya dari env CSP_MODE.
 */

test('SEC-063 semua header keamanan ada di respons web', function () {
    config(['pakis.csp.mode' => 'enforce']);

    $response = $this->get('/login');

    $response->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        // T-073: geolocation hanya origin sendiri (titik kios); kamera & mikrofon tetap diblok.
        ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(self), microphone=()')
        ->assertHeaderMissing('X-Powered-By');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->not->toContain("'unsafe-eval'");
});

test('SEC-063 nonce CSP sama dengan nonce di tag inline HTML', function () {
    config(['pakis.csp.mode' => 'enforce']);

    $response = $this->get('/login');

    preg_match("/script-src [^;]*'nonce-([A-Za-z0-9+\/=_-]+)'/", $response->headers->get('Content-Security-Policy'), $m);
    $nonce = $m[1] ?? null;

    expect($nonce)->not->toBeNull();
    expect($response->getContent())
        ->toContain('<script nonce="'.$nonce.'"')
        ->toContain('<style nonce="'.$nonce.'"');
});

test('SEC-063 script wajib nonce, style boleh inline (style runtime dari library)', function () {
    config(['pakis.csp.mode' => 'enforce']);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    preg_match('/script-src ([^;]*)/', $csp, $script);
    preg_match('/style-src ([^;]*)/', $csp, $style);

    expect($script[1])->toContain("'nonce-")->not->toContain("'unsafe-inline'")
        // Nonce di style-src akan membuat browser mengabaikan 'unsafe-inline'.
        ->and($style[1])->toContain("'unsafe-inline'")->not->toContain("'nonce-");
});

test('SEC-063 nonce berbeda di setiap request', function () {
    config(['pakis.csp.mode' => 'enforce']);

    $a = $this->get('/login')->headers->get('Content-Security-Policy');
    $b = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($a)->not->toBe($b);
});

test('SEC-063 mode report-only memakai header Report-Only', function () {
    config(['pakis.csp.mode' => 'report-only']);

    $response = $this->get('/login');

    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeTrue()
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

test('SEC-063 mode off tidak mengirim CSP tetapi header lain tetap ada', function () {
    config(['pakis.csp.mode' => 'off']);

    $response = $this->get('/login');

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
    $response->assertHeader('X-Frame-Options', 'DENY');
});

test('SEC-063 respons API juga membawa header dasar', function () {
    $this->getJson('/api/v1/tidak-ada')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');
});
