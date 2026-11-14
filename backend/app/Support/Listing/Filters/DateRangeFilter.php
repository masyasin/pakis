<?php

namespace App\Support\Listing\Filters;

use App\Support\Listing\ListingDefaults;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rentang tanggal `filter[key][from]=YYYY-MM-DD&filter[key][to]=YYYY-MM-DD` (BR-003).
 *
 * Tanggal dari pengguna adalah tanggal LOKAL (zona tampilan). Untuk kolom DATE (mis. `work_date`)
 * dibandingkan langsung. Untuk kolom TIMESTAMP yang disimpan UTC, panggil `onTimestamp()`: batas
 * hari lokal dikonversi ke UTC, jadi absen 23.30 WIB tetap masuk hari itu.
 */
class DateRangeFilter extends Filter
{
    protected bool $timestamp = false;

    public function onTimestamp(): static
    {
        $this->timestamp = true;

        return $this;
    }

    public function type(): string
    {
        return 'date_range';
    }

    public function parse(mixed $raw): FilterResult
    {
        if (self::isBlank($raw)) {
            return FilterResult::empty();
        }

        if (! is_array($raw)) {
            return FilterResult::invalid('Format rentang tanggal tidak valid.');
        }

        $from = $raw['from'] ?? null;
        $to = $raw['to'] ?? null;

        if (self::isBlank($from) && self::isBlank($to)) {
            return FilterResult::empty();
        }

        $fromDate = self::isBlank($from) ? null : self::date($from);
        $toDate = self::isBlank($to) ? null : self::date($to);

        if ((! self::isBlank($from) && $fromDate === null) || (! self::isBlank($to) && $toDate === null)) {
            return FilterResult::invalid('Tanggal harus berformat TTTT-BB-HH.');
        }

        if ($fromDate !== null && $toDate !== null) {
            if ($fromDate->greaterThan($toDate)) {
                return FilterResult::invalid('Tanggal awal harus sebelum tanggal akhir.');
            }

            if ($fromDate->diffInDays($toDate) > ListingDefaults::DATE_RANGE_MAX_DAYS) {
                return FilterResult::invalid('Rentang tanggal maksimal '.ListingDefaults::DATE_RANGE_MAX_DAYS.' hari.');
            }
        }

        return FilterResult::valid(array_filter([
            'from' => $fromDate?->toDateString(),
            'to' => $toDate?->toDateString(),
        ], fn ($v) => $v !== null));
    }

    protected function applyToColumn(Builder $query, string $column, mixed $value): void
    {
        if (! $this->timestamp) {
            if (isset($value['from'])) {
                $query->where($column, '>=', $value['from']);
            }
            if (isset($value['to'])) {
                $query->where($column, '<=', $value['to']);
            }

            return;
        }

        $zone = DisplayTime::timezone();
        if (isset($value['from'])) {
            $query->where($column, '>=', CarbonImmutable::parse($value['from'], $zone)->startOfDay()->utc());
        }
        if (isset($value['to'])) {
            // Eksklusif awal hari berikutnya: mencakup seluruh hari "to", termasuk detik terakhirnya.
            $query->where($column, '<', CarbonImmutable::parse($value['to'], $zone)->addDay()->startOfDay()->utc());
        }
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        // createFromFormat menggulirkan tanggal mustahil (2026-02-30 → 2 Maret); tolak.
        return $date !== null && $date->format('Y-m-d') === $value ? $date : null;
    }
}
