<?php

namespace App\Domain\Access\Support;

use App\Domain\Access\Rules\PasswordPolicy;

/**
 * Password sementara dari admin (BR-014): acak kriptografis, tanpa karakter yang mudah tertukar
 * saat dibacakan/diketik ulang (`0 O 1 l I`). Panjang 12 menurut BR-014, dinaikkan bila setting
 * panjang minimum lebih besar agar password sementara selalu lolos kebijakan saat login.
 */
final class TemporaryPassword
{
    public const LENGTH = 12;

    public const ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function make(): string
    {
        $length = max(self::LENGTH, PasswordPolicy::minLength());
        $max = strlen(self::ALPHABET) - 1;
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }

        return $password;
    }
}
