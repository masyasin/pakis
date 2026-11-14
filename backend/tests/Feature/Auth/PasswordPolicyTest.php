<?php

use App\Domain\Access\Rules\PasswordPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/*
 * BR-011 / SEC-002: satu kebijakan password untuk semua tempat (web, API, reset, admin, impor).
 */

beforeEach(function () {
    $this->user = User::factory()->create(['username' => 'siti.aminah']);
    setSetting('security.password_check_breached', false);
});

function passwordErrors(string $password, ?User $user = null, ?string $confirmation = null): array
{
    $validator = Validator::make(
        ['password' => $password, 'password_confirmation' => $confirmation ?? $password],
        ['password' => app(PasswordPolicy::class)->rules($user)],
    );

    return $validator->errors()->get('password');
}

test('BR-011 panjang minimal mengikuti setting, maksimal 128', function () {
    expect(passwordErrors('pendek123'))->not->toBeEmpty()          // 9 < 10
        ->and(passwordErrors('cukup12345'))->toBeEmpty()             // 10
        ->and(passwordErrors(str_repeat('a', 129)))->not->toBeEmpty();

    setSetting('security.password_min_length', 16);
    expect(passwordErrors('cukup12345'))->not->toBeEmpty();
});

test('BR-011 tanpa aturan komposisi: kalimat huruf kecil diterima', function () {
    expect(passwordErrors('kopi pagi di gudang undaan'))->toBeEmpty();
});

test('BR-011 password yang memuat username ditolak (tanpa beda huruf)', function () {
    expect(passwordErrors('xxSITI.Aminah2026', $this->user))
        ->toContain('Password tidak boleh memuat username Anda.');
});

test('BR-011 password sama dengan password saat ini ditolak', function () {
    $this->user->update(['password' => 'kopi pagi di gudang']);
    expect(passwordErrors('kopi pagi di gudang', $this->user))
        ->toContain('Password baru tidak boleh sama dengan password saat ini.');
});

test('BR-011 konfirmasi harus cocok', function () {
    expect(passwordErrors('kopi pagi di gudang', confirmation: 'kopi pagi di gudangX'))->not->toBeEmpty();
});

test('SEC-002 password yang tercatat bocor ditolak; hanya 5 karakter awal SHA-1 yang dikirim', function () {
    setSetting('security.password_check_breached', true);
    $sha1 = strtoupper(sha1('kopi pagi di gudang'));
    Http::fake([
        'api.pwnedpasswords.com/range/*' => Http::response(substr($sha1, 5).":42\r\nAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:1"),
    ]);

    expect(passwordErrors('kopi pagi di gudang'))
        ->toContain('Password ini pernah bocor di internet. Pilih password lain.');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.pwnedpasswords.com/range/'.substr($sha1, 0, 5)
        && ! str_contains($request->url(), substr($sha1, 5)));
});

test('SEC-002 password yang tidak tercatat bocor diterima', function () {
    setSetting('security.password_check_breached', true);
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response("AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:1\r\n")]);

    expect(passwordErrors('kopi pagi di gudang'))->toBeEmpty();
});

test('BR-011 layanan cek bocor tidak bisa dihubungi → dilewati (fail-open) dan dicatat', function () {
    setSetting('security.password_check_breached', true);
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('', 503)]);
    Log::spy();

    expect(passwordErrors('kopi pagi di gudang'))->toBeEmpty();
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Cek password bocor dilewati'));
});

test('BR-011 setting cek bocor mati → tidak ada permintaan ke luar', function () {
    Http::fake();

    passwordErrors('kopi pagi di gudang');

    Http::assertNothingSent();
});

test('atribut passwordrules untuk browser mengikuti setting', function () {
    expect(app(PasswordPolicy::class)->toPasswordRulesString())->toBe('minlength: 10; maxlength: 128;');
});
