<?php

namespace App\Domain\Attendance\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Label tanggal sipil jadwal untuk pesan & pilihan ("1 Desember 2026", "November 2026"). Nama bulan
 * ditulis sendiri agar tidak bergantung pada locale Carbon/ICU server dan selalu Bahasa Indonesia
 * (07 §6). Tanggal jadwal sudah tanggal lokal: tidak ada konversi zona.
 */
final class ScheduleDates
{
    private const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public static function long(CarbonInterface|string $date): string
    {
        $d = $date instanceof CarbonInterface ? $date : CarbonImmutable::parse($date);

        return $d->day.' '.self::MONTHS[$d->month].' '.$d->year;
    }

    public static function month(CarbonInterface $month): string
    {
        return self::MONTHS[$month->month].' '.$month->year;
    }
}
