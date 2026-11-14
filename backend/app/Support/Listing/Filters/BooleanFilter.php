<?php

namespace App\Support\Listing\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Ya/tidak. Menerima `1`, `0`, `true`, `false` (dan boolean asli untuk nilai default).
 */
class BooleanFilter extends Filter
{
    public function type(): string
    {
        return 'boolean';
    }

    public function parse(mixed $raw): FilterResult
    {
        if (self::isBlank($raw)) {
            return FilterResult::empty();
        }

        return match (true) {
            $raw === true, $raw === '1', $raw === 1, $raw === 'true' => FilterResult::valid(true),
            $raw === false, $raw === '0', $raw === 0, $raw === 'false' => FilterResult::valid(false),
            default => FilterResult::invalid('Nilai harus ya atau tidak.'),
        };
    }

    protected function applyToColumn(Builder $query, string $column, mixed $value): void
    {
        $query->where($column, $value);
    }
}
