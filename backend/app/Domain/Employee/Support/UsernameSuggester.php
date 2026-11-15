<?php

namespace App\Domain\Employee\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Saran username dari nama pegawai (T-024, FR-016): dua kata pertama, huruf kecil, dipisah titik
 * (`budi.santoso`), lalu `budi.santoso2`, `budi.santoso3`, … bila sudah dipakai. Hasil selalu
 * lolos `users_username_chk` (`^[a-z0-9._-]{3,50}$`, 04 §2.2). Username akun yang sudah dihapus
 * tetap dihitung terpakai karena index unik ikut mencakupnya.
 */
final class UsernameSuggester
{
    private const MAX = 50;

    private const MAX_ATTEMPTS = 200;

    public static function base(string $fullName): string
    {
        $words = preg_split('/\s+/', strtolower(Str::ascii($fullName)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter(array_map(
            fn (string $w) => (string) preg_replace('/[^a-z0-9]/', '', $w),
            $words,
        ), fn (string $w) => $w !== ''));

        $base = implode('.', array_slice($words, 0, 2));

        if (strlen($base) < 3) {
            $base = $base === '' ? 'pegawai' : $base.'.pegawai';
        }

        // Sisakan ruang untuk akhiran angka.
        return rtrim(substr($base, 0, self::MAX - 4), '.');
    }

    public static function suggest(string $fullName): string
    {
        $base = self::base($fullName);

        for ($n = 1; $n <= self::MAX_ATTEMPTS; $n++) {
            $candidate = $n === 1 ? $base : $base.$n;
            if (! User::withTrashed()->where('username', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $base.'.'.strtolower(Str::random(6));
    }
}
