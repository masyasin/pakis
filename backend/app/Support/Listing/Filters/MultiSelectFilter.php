<?php

namespace App\Support\Listing\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Beberapa pilihan (`filter[status][]=late&filter[status][]=absent`). Nilai tidak dikenal dibuang
 * dan dilaporkan; nilai yang sah tetap diterapkan agar satu nilai rusak tidak mengosongkan filter.
 */
class MultiSelectFilter extends SelectFilter
{
    public function type(): string
    {
        return 'multi_select';
    }

    public function parse(mixed $raw): FilterResult
    {
        if (self::isBlank($raw)) {
            return FilterResult::empty();
        }

        $values = is_array($raw) ? $raw : [$raw];
        $allowed = $this->optionValues();

        $valid = [];
        $rejected = false;
        foreach ($values as $value) {
            if (is_scalar($value) && in_array((string) $value, $allowed, true)) {
                $valid[] = (string) $value;
            } else {
                $rejected = true;
            }
        }

        $valid = array_values(array_unique($valid));

        return $rejected
            ? FilterResult::invalid('Sebagian pilihan tidak dikenal dan diabaikan.', $valid)
            : FilterResult::valid($valid);
    }

    protected function applyToColumn(Builder $query, string $column, mixed $value): void
    {
        $query->whereIn($column, $value);
    }
}
