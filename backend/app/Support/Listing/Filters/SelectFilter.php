<?php

namespace App\Support\Listing\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Satu pilihan dari daftar opsi tetap. Nilai harus persis salah satu opsi.
 */
class SelectFilter extends Filter
{
    /** @var list<array{value: string, label: string}> */
    protected array $options = [];

    /**
     * Opsi sebagai `['nilai' => 'Label']` atau `[['value' => ..., 'label' => ...], ...]`.
     *
     * @param  array<int|string, mixed>  $options
     */
    public function options(array $options): static
    {
        $this->options = array_is_list($options)
            ? array_map(fn (array $o) => ['value' => (string) $o['value'], 'label' => (string) $o['label']], $options)
            : array_map(fn ($value, $label) => ['value' => (string) $value, 'label' => (string) $label], array_keys($options), $options);

        return $this;
    }

    public function type(): string
    {
        return 'select';
    }

    public function parse(mixed $raw): FilterResult
    {
        if (self::isBlank($raw)) {
            return FilterResult::empty();
        }

        if (! is_scalar($raw) || ! in_array((string) $raw, $this->optionValues(), true)) {
            return FilterResult::invalid('Pilihan tidak dikenal.');
        }

        return FilterResult::valid((string) $raw);
    }

    protected function applyToColumn(Builder $query, string $column, mixed $value): void
    {
        $query->where($column, $value);
    }

    public function toArray(): array
    {
        return [...parent::toArray(), 'options' => $this->options];
    }

    /**
     * @return list<string>
     */
    protected function optionValues(): array
    {
        return array_column($this->options, 'value');
    }
}
