<?php

namespace App\Support\Api;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Sumber tunggal request ID (ULID) untuk header, body galat, meta respons, dan log.
 *
 * Disimpan di Context Laravel, sehingga otomatis ikut di setiap entri log dan
 * terbawa ke job antrean yang di-dispatch selama request ini.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    public const CONTEXT_KEY = 'request_id';

    public static function current(): string
    {
        $id = Context::get(self::CONTEXT_KEY);

        if (is_string($id) && $id !== '') {
            return $id;
        }

        // Di luar request (CLI, job tanpa konteks) tetap beri ID agar log bisa dikorelasikan.
        return self::assign((string) Str::ulid());
    }

    public static function assign(string $id): string
    {
        Context::add(self::CONTEXT_KEY, $id);

        return $id;
    }

    /**
     * Hanya ULID valid yang diterima dari klien: mencegah injeksi baris log dan ID sembarang.
     */
    public static function fromClient(?string $candidate): ?string
    {
        if ($candidate === null || strlen($candidate) !== 26 || ! Str::isUlid($candidate)) {
            return null;
        }

        return strtoupper($candidate);
    }
}
