<?php

namespace App\Domain\Employee\Support;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * NIK pegawai (SEC-010, BR-050). Disimpan dua bentuk dan TIDAK PERNAH plaintext di DB, log,
 * audit, ekspor, atau respons (kecuali `reveal-nik` yang tercatat):
 * - `nik_enc`  : `Crypt::encryptString` (APP_KEY) — bisa dibuka untuk yang berhak.
 * - `nik_hash` : HMAC-SHA256 dengan NIK_HASH_KEY — untuk cek unik & kunci baris impor tanpa
 *                membuka enkripsi. Kunci terpisah dari APP_KEY dan dari backup DB (SEC-011),
 *                sehingga dump DB saja tidak cukup untuk menebak NIK lewat brute force 16 digit.
 *
 * Fail-closed: kunci kosong / salah format → exception, bukan hash dengan kunci kosong (hash
 * tanpa kunci rahasia bisa ditebak dari daftar NIK yang mungkin).
 */
final class NikCipher
{
    public const PATTERN = '/^\d{16}$/';

    /** @param  string|null  $hexKey  kunci hex untuk test unit; null = `config('pakis.nik_hash_key')` */
    public function __construct(private readonly ?string $hexKey = null) {}

    public function encrypt(string $nik): string
    {
        return Crypt::encryptString($nik);
    }

    public function decrypt(string $encrypted): string
    {
        return Crypt::decryptString($encrypted);
    }

    public function hash(string $nik): string
    {
        return hash_hmac('sha256', $nik, $this->key());
    }

    /** `3319********0001` (07 §2 MaskedValue): cukup untuk mencocokkan dokumen tanpa membuka NIK. */
    public static function mask(string $nik): string
    {
        return substr($nik, 0, 4).str_repeat('*', 8).substr($nik, -4);
    }

    private function key(): string
    {
        $hex = $this->hexKey ?? config('pakis.nik_hash_key');

        if (! is_string($hex) || preg_match('/^[0-9a-f]{64}$/i', $hex) !== 1) {
            throw new RuntimeException('NIK_HASH_KEY belum diisi atau bukan 64 karakter hex (SEC-010).');
        }

        return (string) hex2bin($hex);
    }
}
