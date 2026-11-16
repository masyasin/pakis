<?php

namespace App\Domain\Employee\Listings;

use App\Support\Import\ImportRowStatus;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Baris satu batch impor (`admin.imports.rows.index`, katalog 07 §3.4: `import_rows`). Pencarian
 * di nilai terpetakan (`data` JSON). NIK tidak bisa dicari: yang tersimpan hanya versi termasking.
 * Bila batch punya galat, filter status bawaan = Galat (07 §3.4).
 */
final class ImportRowListing implements HasListing
{
    public function __construct(private readonly bool $errorsFirst = false) {}

    public function spec(): ListingSpec
    {
        $status = MultiSelectFilter::make('status', 'Status')
            ->options(collect(ImportRowStatus::cases())->mapWithKeys(fn (ImportRowStatus $s) => [$s->value => $s->label()])->all());

        return ListingSpec::make('import_rows')
            ->searchPlaceholder('Cari nama atau no. pegawai')
            ->search(TextSearch::make()->using(function (Builder $q, string $word): void {
                $like = '%'.TextSearch::escapeLike($word).'%';
                $q->orWhere('import_rows.data->full_name', 'like', $like)
                    ->orWhere('import_rows.data->employee_number', 'like', $like);
            }))
            ->filters([$this->errorsFirst ? $status->default([ImportRowStatus::Invalid->value]) : $status])
            ->sorts([Sort::make('row_number', 'Baris')])
            ->defaultSort('row_number');
    }
}
