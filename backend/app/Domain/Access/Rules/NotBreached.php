<?php

namespace App\Domain\Access\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SEC-002 / BR-011: tolak password yang tercatat bocor (layanan Pwned Passwords).
 *
 * Privasi: model k-anonymity — hanya 5 karakter pertama SHA-1 yang dikirim, pencocokan sisanya
 * dilakukan di sini. Password tidak pernah dikirim maupun dicatat.
 *
 * FAIL-OPEN (terdokumentasi di BR-011): bila layanan tidak bisa dihubungi, pengecekan dilewati
 * dan dicatat, karena bisa mengganti password lebih penting daripada pengecekan tambahan ini.
 */
final class NotBreached implements ValidationRule
{
    private const ENDPOINT = 'https://api.pwnedpasswords.com/range/';

    private const TIMEOUT_SECONDS = 3;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $hash = strtoupper(sha1($value));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['Add-Padding' => 'true'])
                ->get(self::ENDPOINT.$prefix);

            if (! $response->successful()) {
                throw new \RuntimeException('HTTP '.$response->status());
            }
        } catch (Throwable $e) {
            Log::warning('Cek password bocor dilewati: layanan tidak bisa dihubungi.', ['reason' => $e->getMessage()]);

            return;
        }

        foreach (preg_split('/\R/', $response->body()) ?: [] as $line) {
            [$candidate, $count] = array_pad(explode(':', trim($line), 2), 2, '0');
            if (hash_equals($suffix, strtoupper($candidate)) && (int) $count > 0) {
                $fail('Password ini pernah bocor di internet. Pilih password lain.');

                return;
            }
        }
    }
}
