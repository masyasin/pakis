<?php

namespace App\Support\Listing\Search;

use App\Support\Listing\ListingDefaults;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pencarian teks `q` (BR-002).
 *
 * - Setiap kata harus cocok (AND), cukup di salah satu kolom (OR).
 * - `prefix` (`kata%`) untuk kolom ber-index; `contains` (`%kata%`) untuk tabel kecil / kolom bebas.
 * - Case & aksen tidak berpengaruh karena collation `utf8mb4_0900_ai_ci`; jangan pakai LOWER()
 *   karena mematikan index.
 * - Wildcard `%`, `_`, `\` di input di-escape agar dicari sebagai teks literal.
 */
final class TextSearch
{
    /** @var list<Closure(Builder<*>, string): void> */
    private array $targets = [];

    public static function make(): self
    {
        return new self;
    }

    public function prefix(string $column): self
    {
        return $this->like($column, fn (string $escaped) => $escaped.'%');
    }

    public function contains(string $column): self
    {
        return $this->like($column, fn (string $escaped) => '%'.$escaped.'%');
    }

    /**
     * Kolom di relasi Eloquent (whereHas). Untuk tabel besar, pertimbangkan `using()` dengan join.
     */
    public function relation(string $relation, string $column, bool $prefix = true): self
    {
        $this->targets[] = function (Builder $query, string $word) use ($relation, $column, $prefix): void {
            $pattern = $prefix ? self::escapeLike($word).'%' : '%'.self::escapeLike($word).'%';
            $query->orWhereHas($relation, fn (Builder $q) => $q->where($q->qualifyColumn($column), 'like', $pattern));
        };

        return $this;
    }

    /**
     * Pencarian kustom per kata. Callback menerima kata mentah dan WAJIB memakai binding
     * (`->orWhere(...)`) serta `TextSearch::escapeLike()` untuk LIKE.
     *
     * @param  Closure(Builder<*>, string): void  $callback
     */
    public function using(Closure $callback): self
    {
        $this->targets[] = $callback;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->targets === [];
    }

    /**
     * Rapikan input `q`: buang karakter kontrol, trim, satukan spasi, potong 100 karakter.
     */
    public static function normalize(mixed $raw): string
    {
        if (! is_string($raw)) {
            return '';
        }

        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $raw);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        // rtrim setelah dipotong: potongan bisa berakhir di spasi.
        $clean = rtrim(mb_substr($clean, 0, ListingDefaults::SEARCH_MAX_LENGTH));

        return mb_strlen($clean) < ListingDefaults::SEARCH_MIN_LENGTH ? '' : $clean;
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param  Builder<*>  $query
     */
    public function apply(Builder $query, string $normalized): void
    {
        if ($normalized === '' || $this->isEmpty()) {
            return;
        }

        foreach (explode(' ', $normalized) as $word) {
            $query->where(function (Builder $group) use ($word): void {
                foreach ($this->targets as $target) {
                    $target($group, $word);
                }
            });
        }
    }

    /**
     * @param  Closure(string): string  $pattern
     */
    private function like(string $column, Closure $pattern): self
    {
        $this->targets[] = function (Builder $query, string $word) use ($column, $pattern): void {
            $qualified = str_contains($column, '.') ? $column : $query->qualifyColumn($column);
            $query->orWhere($qualified, 'like', $pattern(self::escapeLike($word)));
        };

        return $this;
    }
}
