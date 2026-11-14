<?php

namespace App\Support\Listing\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dasar semua filter listing (BR-003). Nilai dari request selalu melewati `parse()` (validasi
 * per tipe) sebelum menyentuh query, dan kolom DB hanya berasal dari spec.
 */
abstract class Filter
{
    protected ?string $column = null;

    /** @var (Closure(Builder<*>, mixed): mixed)|null */
    protected ?Closure $using = null;

    /** @var mixed|Closure(): mixed */
    protected mixed $default = null;

    protected bool $required = false;

    final public function __construct(
        public readonly string $key,
        public readonly string $label,
    ) {}

    public static function make(string $key, string $label): static
    {
        return new static($key, $label);
    }

    /** `select` | `multi_select` | `boolean` | `date_range` | `number_range` (05 §3.2). */
    abstract public function type(): string;

    /** Validasi nilai mentah dari query string. */
    abstract public function parse(mixed $raw): FilterResult;

    /**
     * Penerapan bawaan per tipe pada kolom spec.
     *
     * @param  Builder<*>  $query
     */
    abstract protected function applyToColumn(Builder $query, string $column, mixed $value): void;

    public function column(string $column): static
    {
        $this->column = $column;

        return $this;
    }

    /**
     * Penerapan kustom (relasi, kondisi turunan). Menerima nilai yang SUDAH divalidasi.
     *
     * @param  Closure(Builder<*>, mixed): mixed  $callback
     */
    public function using(Closure $callback): static
    {
        $this->using = $callback;

        return $this;
    }

    /**
     * Nilai saat key filter tidak ada di URL (tampil sebagai chip yang bisa dihapus).
     *
     * @param  mixed|Closure(): mixed  $value
     */
    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    /**
     * Filter wajib (mis. grup referensi, 07 §3.4 "S, wajib"): nilai kosong atau tidak sah jatuh
     * kembali ke default, tidak pernah berarti "semua". Wajib dipasangkan dengan default().
     */
    public function required(): static
    {
        $this->required = true;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function defaultValue(): mixed
    {
        return $this->default instanceof Closure ? ($this->default)() : $this->default;
    }

    /**
     * @param  Builder<*>  $query
     */
    public function apply(Builder $query, mixed $value): void
    {
        if ($this->using !== null) {
            ($this->using)($query, $value);

            return;
        }

        $column = $this->column ?? $this->key;
        $this->applyToColumn($query, str_contains($column, '.') ? $column : $query->qualifyColumn($column), $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['key' => $this->key, 'type' => $this->type(), 'label' => $this->label];

        $default = $this->defaultValue();
        if ($default !== null) {
            $data['default'] = $default;
        }

        if ($this->required) {
            $data['required'] = true;
        }

        return $data;
    }

    protected static function isBlank(mixed $raw): bool
    {
        return $raw === null || $raw === '' || $raw === [];
    }
}
