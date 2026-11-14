<?php

namespace App\Support\Listing;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Satu kolom yang boleh dipakai untuk sort (BR-004). Kolom selalu dari spec, tidak pernah dari request.
 */
final class Sort
{
    private ?string $column = null;

    /** @var (Closure(Builder<*>, 'asc'|'desc'): mixed)|null */
    private ?Closure $using = null;

    private function __construct(
        public readonly string $key,
        public readonly string $label,
    ) {}

    public static function make(string $key, string $label): self
    {
        return new self($key, $label);
    }

    /** Kolom DB bila berbeda dari key (mis. `employees.full_name`). */
    public function column(string $column): self
    {
        $this->column = $column;

        return $this;
    }

    /**
     * Sort kustom: join/subquery yang ditentukan developer (mis. nama pegawai di tabel absensi).
     *
     * @param  Closure(Builder<*>, 'asc'|'desc'): mixed  $callback
     */
    public function using(Closure $callback): self
    {
        $this->using = $callback;

        return $this;
    }

    /**
     * @param  Builder<*>  $query
     * @param  'asc'|'desc'  $direction
     */
    public function apply(Builder $query, string $direction): void
    {
        if ($this->using !== null) {
            ($this->using)($query, $direction);

            return;
        }

        $column = $this->column ?? $this->key;
        $query->orderBy(str_contains($column, '.') ? $column : $query->qualifyColumn($column), $direction);
    }

    /**
     * @return array{key: string, label: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label];
    }
}
