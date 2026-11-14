<?php

use App\Domain\Audit\Redactor;

/*
 * SEC-040 / SEC-010: field sensitif tidak pernah tersimpan di audit.
 */

test('SEC-040 field sensitif diganti ***', function (string $key) {
    expect(Redactor::redact([$key => 'rahasia', 'name' => 'Agus']))
        ->toBe([$key => '***', 'name' => 'Agus']);
})->with([
    'password', 'password_confirmation', 'current_password', 'PASSWORD',
    'nik', 'nik_enc', 'nik_hash',
    'two_factor_secret', 'two_factor_recovery_codes',
    'remember_token', 'api_token', 'access_token', 'otp_code', 'client_secret', 'api_key',
]);

test('redaksi berlaku rekursif di struktur bersarang', function () {
    expect(Redactor::redact(['user' => ['name' => 'Agus', 'nik_enc' => 'x', 'meta' => ['token' => 'y']]]))
        ->toBe(['user' => ['name' => 'Agus', 'nik_enc' => '***', 'meta' => ['token' => '***']]]);
});

test('nilai null tetap null (bukan *** yang menyesatkan)', function () {
    expect(Redactor::redact(['nik_hash' => null]))->toBe(['nik_hash' => null]);
});

test('kunci biasa tidak terkena', function () {
    $values = ['name' => 'Agus', 'email' => 'a@b.test', 'request_id' => '01J', 'status' => 'active'];

    expect(Redactor::redact($values))->toBe($values)
        ->and(Redactor::redact(null))->toBeNull();
});
