<?php

namespace App\Support\Listing;

use App\Support\Listing\Filters\Filter;
use App\Support\Listing\Search\TextSearch;

/**
 * Deklarasi satu daftar: kolom cari, filter, sort, paging (BR-001–BR-004).
 * Kunci (`key`) harus terdaftar di katalog `07` §3.4 (diperiksa ListingContractTest, T-005).
 */
final class ListingSpec
{
    private string $searchPlaceholder = 'Cari';

    private TextSearch $search;

    /** @var array<string, Filter> */
    private array $filters = [];

    /** @var array<string, Sort> */
    private array $sorts = [];

    private ?string $defaultSort = null;

    /** @var list<int>|null */
    private ?array $perPageOptions = null;

    private ?int $defaultPerPage = null;

    private bool $exportable = false;

    private bool $api = false;

    private function __construct(public readonly string $key)
    {
        $this->search = TextSearch::make();
    }

    public static function make(string $key): self
    {
        return new self($key);
    }

    public function searchPlaceholder(string $text): self
    {
        $this->searchPlaceholder = $text;

        return $this;
    }

    public function search(TextSearch $search): self
    {
        $this->search = $search;

        return $this;
    }

    /**
     * @param  list<Filter>  $filters
     */
    public function filters(array $filters): self
    {
        foreach ($filters as $filter) {
            $this->filters[$filter->key] = $filter;
        }

        return $this;
    }

    /**
     * @param  list<Sort>  $sorts
     */
    public function sorts(array $sorts): self
    {
        foreach ($sorts as $sort) {
            $this->sorts[$sort->key] = $sort;
        }

        return $this;
    }

    /** `key` naik atau `-key` turun; key harus ada di sorts(). */
    public function defaultSort(string $sort): self
    {
        $this->defaultSort = $sort;

        return $this;
    }

    /**
     * Ganti opsi per halaman bawaan (web `[10,25,50,100]`, mobile `[20,50]`).
     *
     * @param  list<int>  $options
     */
    public function perPageOptions(array $options, ?int $default = null): self
    {
        $this->perPageOptions = $options;
        $this->defaultPerPage = $default;

        return $this;
    }

    public function exportable(bool $exportable = true): self
    {
        $this->exportable = $exportable;

        return $this;
    }

    /**
     * Salinan untuk route API (`api/*`): opsi mobile bila spec tidak menentukan opsi sendiri.
     */
    public function forApi(): self
    {
        $copy = clone $this;
        $copy->api = true;

        return $copy;
    }

    public function textSearch(): TextSearch
    {
        return $this->search;
    }

    /**
     * @return array<string, Filter>
     */
    public function filterList(): array
    {
        return $this->filters;
    }

    public function filter(string $key): ?Filter
    {
        return $this->filters[$key] ?? null;
    }

    public function sort(string $key): ?Sort
    {
        return $this->sorts[$key] ?? null;
    }

    /**
     * @return array<string, Sort>
     */
    public function sortList(): array
    {
        return $this->sorts;
    }

    public function defaultSortValue(): string
    {
        return $this->defaultSort ?? (string) array_key_first($this->sorts);
    }

    /**
     * Opsi per halaman setelah dibatasi `ui.max_per_page`.
     *
     * @return list<int>
     */
    public function perPageOptionsValue(): array
    {
        $options = $this->perPageOptions ?? ($this->api ? ListingDefaults::API_PER_PAGE_OPTIONS : ListingDefaults::WEB_PER_PAGE_OPTIONS);
        $max = ListingDefaults::maxPerPage();
        $allowed = array_values(array_filter($options, fn (int $o) => $o >= 1 && $o <= $max));

        return $allowed === [] ? [min($options[0] ?? $max, $max)] : $allowed;
    }

    public function defaultPerPageValue(): int
    {
        $options = $this->perPageOptionsValue();
        $default = $this->defaultPerPage
            ?? ($this->api ? ListingDefaults::API_DEFAULT_PER_PAGE : ListingDefaults::webDefaultPerPage());

        return in_array($default, $options, true) ? $default : $options[0];
    }

    public function isExportable(): bool
    {
        return $this->exportable;
    }

    /**
     * Bentuk `listing.spec` (05 §3.2).
     *
     * @return array{key: string, search_placeholder: string, per_page_options: list<int>, default_sort: string, sorts: list<array{key: string, label: string}>, filters: list<array<string, mixed>>, exportable: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'search_placeholder' => $this->searchPlaceholder,
            'per_page_options' => $this->perPageOptionsValue(),
            'default_sort' => $this->defaultSortValue(),
            'sorts' => array_values(array_map(fn (Sort $s) => $s->toArray(), $this->sorts)),
            'filters' => array_values(array_map(fn (Filter $f) => $f->toArray(), $this->filters)),
            'exportable' => $this->exportable,
        ];
    }
}
