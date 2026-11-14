<?php

namespace App\Support\Listing\Filters;

use App\Support\Listing\ListingDefaults;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Filter berdasarkan ID entitas lain (lokasi, departemen, pengguna). Opsi tidak dikirim di spec,
 * melainkan diambil UI dari endpoint lookup `options_source` (05 §3.2, T-011).
 *
 * Setiap ID divalidasi terhadap query yang SUDAH ber-scope (`validIds`). ID yang tidak ada dan ID
 * di luar scope diperlakukan sama, sehingga filter tidak bisa dipakai untuk menebak data orang lain.
 */
class RelationFilter extends Filter
{
    protected bool $multiple = false;

    protected ?string $source = null;

    /** @var (Closure(): Builder<*>)|null */
    protected ?Closure $validIds = null;

    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;

        return $this;
    }

    /** Nama sumber lookup, mis. `locations` → `admin.lookup.locations`. */
    public function source(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Query ber-scope yang berisi semua ID yang boleh dipilih pengguna ini.
     *
     * @param  Closure(): Builder<*>  $query
     */
    public function validIds(Closure $query): static
    {
        $this->validIds = $query;

        return $this;
    }

    public function type(): string
    {
        return $this->multiple ? 'multi_select' : 'select';
    }

    public function parse(mixed $raw): FilterResult
    {
        if (self::isBlank($raw)) {
            return FilterResult::empty();
        }

        if ($this->validIds === null) {
            throw new LogicException("RelationFilter [{$this->key}] wajib memanggil validIds() dengan query ber-scope.");
        }

        $values = is_array($raw) ? array_values($raw) : [$raw];

        if (! $this->multiple && count($values) > 1) {
            return FilterResult::invalid('Hanya satu pilihan yang diizinkan.');
        }

        if (count($values) > ListingDefaults::RELATION_MAX_IDS) {
            return FilterResult::invalid('Terlalu banyak pilihan (maksimal '.ListingDefaults::RELATION_MAX_IDS.').');
        }

        $ids = [];
        foreach ($values as $value) {
            if (is_int($value) && $value > 0) {
                $ids[] = $value;
            } elseif (is_string($value) && preg_match('/^[1-9]\d{0,18}$/', $value) === 1) {
                $ids[] = (int) $value;
            } else {
                return FilterResult::invalid('Pilihan tidak valid.');
            }
        }

        $ids = array_values(array_unique($ids));

        $query = ($this->validIds)();
        /** @var list<int> $allowed */
        $allowed = $query->whereKey($ids)->pluck($query->getModel()->getQualifiedKeyName())->map(fn ($id) => (int) $id)->all();
        $found = array_values(array_intersect($ids, $allowed));

        $value = $this->multiple ? $found : ($found[0] ?? null);

        return count($found) === count($ids)
            ? FilterResult::valid($value)
            : FilterResult::invalid('Sebagian pilihan tidak ditemukan dan diabaikan.', $value);
    }

    protected function applyToColumn(Builder $query, string $column, mixed $value): void
    {
        is_array($value) ? $query->whereIn($column, $value) : $query->where($column, $value);
    }

    public function toArray(): array
    {
        return [...parent::toArray(), 'options_source' => $this->source];
    }
}
