<?php

namespace App\Support\Listing;

use App\Support\Listing\Search\TextSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LogicException;

/**
 * Menerapkan spec listing pada query (BR-001–BR-004, SEC-032).
 *
 * Satu-satunya pintu dari parameter listing (`q`, `filter`, `sort`, `page`, `per_page`) ke SQL.
 * Parameter di luar spec diabaikan; nilai dalam spec divalidasi per tipe sebelum dipakai.
 * Scope data (DataScope, T-019) diterapkan pemanggil pada `$query` SEBELUM masuk ke sini.
 */
final class ListingQuery
{
    /**
     * `Builder<*>`: mesin hanya menambah where/orderBy, jadi menerima query model apa pun
     * (`Builder<Employee>`, `Builder<Location>`, ...) tanpa melanggar invariansi generik PHPStan.
     *
     * @param  Builder<*>  $query
     */
    public static function apply(Builder $query, HasListing|ListingSpec $listing, Request $request): ListingResult
    {
        $spec = $listing instanceof HasListing ? $listing->spec() : $listing;
        if ($request->is('api/*')) {
            $spec = $spec->forApi();
        }

        // Pencarian
        $q = TextSearch::normalize($request->query('q'));
        $spec->textSearch()->apply($query, $q);

        // Filter
        [$filters, $invalid] = self::applyFilters($query, $spec, $request->query('filter'));

        // Sort + tie-breaker supaya paging stabil saat nilai sort sama (BR-004)
        $sort = self::resolveSort($spec, $request->query('sort'));
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $sortDefinition = $spec->sort(ltrim($sort, '-'))
            ?? throw new LogicException("Default sort [{$sort}] tidak ada di spec listing [{$spec->key}].");
        $sortDefinition->apply($query, $direction);
        $query->orderBy($query->getModel()->getQualifiedKeyName(), 'desc');

        // Query tanpa paging, untuk ekspor dengan filter & sort yang sama (BR-005)
        $unpaged = clone $query;

        $perPage = self::resolvePerPage($spec, $request->query('per_page'));
        $page = self::resolvePage($request->query('page'));
        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return new ListingResult($spec, $paginator, $unpaged, [
            'q' => $q,
            'sort' => $sort,
            'filters' => $filters,
            'invalid_filters' => $invalid,
        ]);
    }

    /**
     * @param  Builder<*>  $query
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private static function applyFilters(Builder $query, ListingSpec $spec, mixed $raw): array
    {
        // `filter` bukan array (mis. `filter=name=Agus`) → anggap tidak ada filter dari request.
        $raw = is_array($raw) ? $raw : [];
        $active = [];
        $invalid = [];

        foreach ($spec->filterList() as $key => $filter) {
            // Key ada di URL (walau kosong) = pilihan pengguna, termasuk mematikan default.
            $result = array_key_exists($key, $raw)
                ? $filter->parse($raw[$key])
                : $filter->parse($filter->defaultValue());

            if ($result->isInvalid()) {
                $invalid[$key] = (string) $result->error;
            }

            // Filter wajib tidak boleh kosong: pakai default (galat tetap dilaporkan di atas).
            if ($filter->isRequired() && ! $result->hasValue()) {
                $result = $filter->parse($filter->defaultValue());
            }

            if ($result->hasValue()) {
                $filter->apply($query, $result->value);
                $active[$key] = $result->value;
            }
        }

        return [$active, $invalid];
    }

    private static function resolveSort(ListingSpec $spec, mixed $raw): string
    {
        if (is_string($raw) && preg_match('/^-?[a-z0-9_]{1,64}$/', $raw) === 1 && $spec->sort(ltrim($raw, '-')) !== null) {
            return $raw;
        }

        return $spec->defaultSortValue();
    }

    private static function resolvePerPage(ListingSpec $spec, mixed $raw): int
    {
        $raw = self::digits($raw);

        return $raw !== null && in_array($raw, $spec->perPageOptionsValue(), true)
            ? $raw
            : $spec->defaultPerPageValue();
    }

    private static function resolvePage(mixed $raw): int
    {
        $page = self::digits($raw);

        return $page !== null && $page >= 1 && $page <= 9_999_999 ? $page : 1;
    }

    /**
     * Bilangan bulat positif dari query string ("25") atau int (request internal/test); selain itu null.
     */
    private static function digits(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        return is_string($raw) && preg_match('/^[1-9]\d{0,8}$/', $raw) === 1 ? (int) $raw : null;
    }
}
