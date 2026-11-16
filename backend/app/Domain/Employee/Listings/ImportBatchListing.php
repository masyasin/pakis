<?php

namespace App\Domain\Employee\Listings;

use App\Models\User;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportStatus;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Riwayat impor (`admin.imports.index`, katalog 07 §3.4: `import_batches`). Visibilitas batch
 * (scope `all` → semua; lainnya → milik sendiri) diterapkan lewat `query()` sebelum ListingQuery.
 */
final class ImportBatchListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('import_batches')
            ->searchPlaceholder('Cari nama berkas')
            ->search(TextSearch::make()->contains('original_name'))
            ->filters([
                MultiSelectFilter::make('status', 'Status')
                    ->options(collect(ImportStatus::cases())->mapWithKeys(fn (ImportStatus $s) => [$s->value => $s->label()])->all()),
                RelationFilter::make('created_by', 'Oleh')->source('users')
                    ->validIds(fn () => User::query()->whereIn('id', ImportBatch::query()->whereNotNull('created_by')->select('created_by'))),
                DateRangeFilter::make('created_at', 'Waktu')->onTimestamp(),
            ])
            ->sorts([
                Sort::make('created_at', 'Waktu'),
                Sort::make('original_name', 'Nama berkas'),
                Sort::make('total_rows', 'Total'),
            ])
            ->defaultSort('-created_at');
    }

    /** @return Builder<ImportBatch> */
    public static function query(User $user): Builder
    {
        return ImportBatch::visibleTo(ImportBatch::query()->with('creator:id,name'), $user)
            ->where('type', ImportBatch::TYPE_EMPLOYEES);
    }
}
