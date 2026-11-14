<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Konversi waktu simpanan (UTC) ke zona tampilan operasional.
 *
 * Semua DATETIME/TIMESTAMP disimpan UTC (04 §1). Konversi hanya terjadi di tepi tampilan
 * (Resource, Inertia props, ekspor) agar perbandingan dan query tetap di satu zona.
 * Kolom DATE seperti `work_date` sudah tanggal lokal dan tidak dilewatkan ke sini.
 */
final class DisplayTime
{
    public static function timezone(): string
    {
        // TODO(T-008): ganti dengan setting('general.timezone').
        return (string) config('pakis.display_timezone', 'Asia/Jakarta');
    }

    /**
     * Kembalikan salinan immutable di zona tampilan; objek asal tidak diubah.
     */
    public static function toLocal(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::timezone());
    }
}
