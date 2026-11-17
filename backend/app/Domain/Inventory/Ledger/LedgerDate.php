<?php

namespace App\Domain\Inventory\Ledger;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Tanggal kalender lokal `Y-m-d` (tanggal bisnis, ED). Objek tanggal diambil bagian tanggalnya apa
 * adanya: pemanggil yang memegang momen UTC wajib mengubahnya ke tanggal lokal dulu (DisplayTime).
 */
final class LedgerDate
{
    public static function normalize(DateTimeInterface|string $date): string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException("Tanggal tidak sah [{$date}], harus Y-m-d.");
        }

        return $date;
    }
}
