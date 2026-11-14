<?php

namespace App\Support\Listing;

use App\Support\Api\RequestId;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Hasil ListingQuery: baris halaman ini + state & spec untuk UI, dalam bentuk 05 §3.2 (Inertia)
 * atau §3.3 (API).
 */
final class ListingResult
{
    /** @var (Closure(mixed): mixed)|null */
    private ?Closure $transformer = null;

    /**
     * @param  LengthAwarePaginator<int, *>  $paginator
     * @param  Builder<*>  $unpaged
     * @param  array{q: string, sort: string, filters: array<string, mixed>, invalid_filters: array<string, string>}  $state
     */
    public function __construct(
        private readonly ListingSpec $spec,
        private readonly LengthAwarePaginator $paginator,
        private readonly Builder $unpaged,
        private readonly array $state,
    ) {}

    /**
     * Ubah tiap model menjadi baris yang aman dikirim ke klien (hanya kolom yang perlu tampil).
     *
     * @param  Closure(mixed): mixed  $transformer
     */
    public function through(Closure $transformer): self
    {
        $copy = clone $this;
        $copy->transformer = $transformer;

        return $copy;
    }

    public function spec(): ListingSpec
    {
        return $this->spec;
    }

    /**
     * @return LengthAwarePaginator<int, *>
     */
    public function paginator(): LengthAwarePaginator
    {
        return $this->paginator;
    }

    /**
     * Query tersaring & tersortir tanpa paging. Dipakai ekspor (T-006) agar hasilnya sama dengan
     * tampilan (BR-005). Selalu mengembalikan salinan baru.
     *
     * @return Builder<*>
     */
    public function query(): Builder
    {
        return clone $this->unpaged;
    }

    /**
     * @return array{q: string, sort: string, filters: array<string, mixed>, invalid_filters: array<string, string>}
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * @return list<mixed>
     */
    public function rows(): array
    {
        $items = $this->paginator->items();

        return array_values($this->transformer === null ? $items : array_map($this->transformer, $items));
    }

    /**
     * @return array{page: int, per_page: int, total: int, last_page: int, from: int|null, to: int|null}
     */
    public function pagination(): array
    {
        return [
            'page' => $this->paginator->currentPage(),
            'per_page' => $this->paginator->perPage(),
            'total' => $this->paginator->total(),
            'last_page' => $this->paginator->lastPage(),
            'from' => $this->paginator->firstItem(),
            'to' => $this->paginator->lastItem(),
        ];
    }

    /**
     * Prop Inertia (05 §3.2).
     *
     * @return array{rows: list<mixed>, listing: array<string, mixed>}
     */
    public function toInertia(): array
    {
        return [
            'rows' => $this->rows(),
            'listing' => [
                'pagination' => $this->pagination(),
                'state' => $this->stateForJson(),
                'spec' => $this->spec->toArray(),
            ],
        ];
    }

    /**
     * Respons API daftar (05 §3.3).
     *
     * @return array{data: list<mixed>, meta: array<string, mixed>}
     */
    public function toApi(): array
    {
        $pagination = $this->pagination();

        return [
            'data' => $this->rows(),
            'meta' => [
                'request_id' => RequestId::current(),
                'pagination' => [
                    'page' => $pagination['page'],
                    'per_page' => $pagination['per_page'],
                    'total' => $pagination['total'],
                    'last_page' => $pagination['last_page'],
                ],
                'listing' => [
                    'state' => $this->stateForJson(),
                    'spec' => $this->spec->toArray(),
                ],
            ],
        ];
    }

    /**
     * Map kosong dikirim sebagai objek `{}` (bukan `[]`) agar klien JS/Dart konsisten.
     *
     * @return array<string, mixed>
     */
    private function stateForJson(): array
    {
        return [
            'q' => $this->state['q'],
            'sort' => $this->state['sort'],
            'filters' => (object) $this->state['filters'],
            'invalid_filters' => (object) $this->state['invalid_filters'],
        ];
    }
}
