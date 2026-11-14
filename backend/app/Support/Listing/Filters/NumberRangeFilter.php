<?php

namespace App\Support\Listing\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Rentang angka `filter[key][min]=..&filter[key][max]=..`. Salah satu sisi boleh kosong.
 */
class NumberRangeFilter extends Filter
{
    public function type(): string
    {
        return 'number_range';
    }

    public function parse(mixed $raw): FilterResult
    {
        if (self::isBlank($raw)) {
            return FilterResult::empty();
        }

        if (! is_array($raw)) {
            return FilterResult::invalid('Format rentang angka tidak valid.');
        }

        $min = $raw['min'] ?? null;
        $max = $raw['max'] ?? null;

        if (self::isBlank($min) && self::isBlank($max)) {
            return FilterResult::empty();
        }

        $minNumber = self::isBlank($min) ? null : self::number($min);
        $maxNumber = self::isBlank($max) ? null : self::number($max);

        if ((! self::isBlank($min) && $minNumber === null) || (! self::isBlank($max) && $maxNumber === null)) {
            return FilterResult::invalid('Nilai harus berupa angka.');
        }

        if ($minNumber !== null && $maxNumber !== null && $minNumber > $maxNumber) {
            return FilterResult::invalid('Nilai minimum harus lebih kecil dari maksimum.');
        }

        return FilterResult::valid(array_filter(['min' => $minNumber, 'max' => $maxNumber], fn ($v) => $v !== null));
    }

    protected function applyToColumn(Builder $query, string $column, mixed $value): void
    {
        if (isset($value['min'])) {
            $query->where($column, '>=', $value['min']);
        }
        if (isset($value['max'])) {
            $query->where($column, '<=', $value['max']);
        }
    }

    private static function number(mixed $value): int|float|null
    {
        // Hanya desimal biasa: tolak spasi, notasi eksponen, hex, INF/NAN.
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? $value : null;
        }

        if (! is_string($value) || preg_match('/^-?\d{1,15}(\.\d{1,6})?$/', $value) !== 1) {
            return null;
        }

        return str_contains($value, '.') ? (float) $value : (int) $value;
    }
}
