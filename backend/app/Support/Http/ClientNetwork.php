<?php

namespace App\Support\Http;

/**
 * Kunci "asal jaringan" untuk pembatas percobaan (BR-016).
 *
 * - IPv4: per alamat.
 * - IPv4 yang ditulis sebagai IPv6 (`::ffff:a.b.c.d`, `::a.b.c.d`, NAT64 `64:ff9b::/96`): alamat
 *   IPv4 di dalamnya. Tanpa ini semua klien IPv4 di server dual-stack jatuh ke satu kelompok
 *   (`0000…::/64`) dan satu penyerang bisa menahan login semua orang.
 * - IPv6 asli: per blok /64 — satu pelanggan/perangkat biasanya memegang seluruh /64, jadi
 *   membatasi per alamat mudah diakali dengan berganti alamat.
 */
final class ClientNetwork
{
    public static function key(?string $ip): string
    {
        $ip = (string) $ip;

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $ip;
        }

        $packed = (string) inet_pton($ip);
        $prefix96 = substr($packed, 0, 12);

        $embedsIpv4 = $prefix96 === str_repeat("\0", 10)."\xff\xff"        // ::ffff:a.b.c.d (mapped)
            || $prefix96 === str_repeat("\0", 12)                           // ::a.b.c.d (compatible)
            || $prefix96 === "\x00\x64\xff\x9b".str_repeat("\0", 8);        // 64:ff9b::a.b.c.d (NAT64)

        if ($embedsIpv4) {
            return (string) inet_ntop(substr($packed, 12, 4));
        }

        return bin2hex(substr($packed, 0, 8)).'::/64';
    }
}
