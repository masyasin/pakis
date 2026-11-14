<?php

namespace App\Domain\Audit;

/**
 * Menyamarkan field sensitif sebelum masuk audit/log keamanan (SEC-040, SEC-010).
 *
 * Bekerja berdasarkan NAMA kunci (case-insensitive, rekursif), karena audit menyimpan nilai lama &
 * baru apa adanya dari model. Kunci baru yang sensitif harus ditambahkan di sini, bukan disaring
 * di setiap Action.
 */
final class Redactor
{
    public const MASK = '***';

    /** Nama kunci persis. */
    private const EXACT = ['nik', 'nik_enc', 'nik_hash', 'secret', 'remember_token', 'current_password'];

    /** Pola kunci (regex, tanpa delimiter). */
    private const PATTERNS = ['^password', '^two_factor_', 'token', '^otp', 'secret$', 'api_key'];

    /**
     * @param  array<array-key, mixed>|null  $values
     * @return array<array-key, mixed>|null
     */
    public static function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $result[$key] = $value === null ? null : self::MASK;
            } elseif (is_array($value)) {
                $result[$key] = self::redact($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, self::EXACT, true)) {
            return true;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match("/{$pattern}/", $key) === 1) {
                return true;
            }
        }

        return false;
    }
}
