<?php

namespace App\Domain\Accounting\Period;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Tanggal bisnis dokumen (`business_date`) dalam bentuk `Y-m-d`.
 *
 * Tanggal bisnis adalah tanggal kalender lokal (seperti `work_date`), bukan momen waktu. Objek
 * tanggal diambil bagian tanggalnya apa adanya, tanpa konversi zona: pemanggil yang memegang
 * momen UTC wajib mengubahnya ke tanggal lokal dulu (DisplayTime) sebelum memanggil.
 */
final class BusinessDate
{
    public static function normalize(DateTimeInterface|string $date): string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException("Tanggal bisnis tidak sah [{$date}], harus Y-m-d.");
        }

        return $date;
    }
}
