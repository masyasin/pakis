<?php

namespace App\Support\Http;

/**
 * Kunci "asal jaringan" untuk pembatas percobaan (BR-016).
 *
 * IPv4: per alamat. IPv6: per blok /64 — satu pelanggan/perangkat biasanya memegang seluruh /64
 * (miliaran alamat), jadi membatasi per alamat IPv6 mudah diakali dengan berganti alamat.
 */
final class ClientNetwork
{
    public static function key(?string $ip): string
    {
        $ip = (string) $ip;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return bin2hex(substr((string) inet_pton($ip), 0, 8)).'::/64';
        }

        return $ip;
    }
}
