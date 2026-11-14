<?php

namespace App\Domain\Numbering;

use App\Domain\Numbering\Enums\ResetPeriod;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Kunci scope counter (BR-025): periode (`2026`, `2026-11`, `2026-11-03`, atau `-`) + `@LOC` bila
 * pola memuat `{LOC}`. Pergantian periode = baris counter baru mulai dari 0, itulah "reset".
 */
final class PeriodKey
{
    public static function for(ResetPeriod $reset, CarbonInterface $date, bool $usesLocation, ?string $locationCode = null): string
    {
        $period = match ($reset) {
            ResetPeriod::Never => '-',
            ResetPeriod::Yearly => $date->format('Y'),
            ResetPeriod::Monthly => $date->format('Y-m'),
            ResetPeriod::Daily => $date->format('Y-m-d'),
        };

        if (! $usesLocation) {
            return $period;
        }

        if ($locationCode === null || $locationCode === '') {
            throw new InvalidArgumentException('Pola memuat {LOC}: kode lokasi wajib diberikan.');
        }

        return $period.'@'.strtoupper($locationCode);
    }
}
