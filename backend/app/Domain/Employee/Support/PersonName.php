<?php

namespace App\Domain\Employee\Support;

/** Nama pegawai (BR-050): di-trim dan spasi ganda dirapikan, huruf tidak diubah. */
final class PersonName
{
    public static function clean(?string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $name));
    }
}
