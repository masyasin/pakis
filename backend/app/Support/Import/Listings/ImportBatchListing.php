<?php

namespace App\Support\Import\Listings;

use App\Models\User;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportRegistry;
use App\Support\Import\ImportStatus;
use App\Support\Import\ImportType;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Riwayat impor semua tipe (`admin.imports.index`, katalog 07 §3.4: `import_batches`). Dipindah dari
 * domain pegawai ke kerangka (T-085) karena kini memuat tipe master. `query()` menerapkan
 * visibilitas batch (scope `all` → semua; lainnya → milik sendiri) dan hanya tipe yang boleh
 * dipakai pengguna (ImportRegistry::allows).
 */
final class ImportBatchListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('import_batches')
            ->searchPlaceholder('Cari nama berkas')
            ->search(TextSearch::make()->contains('original_name'))
            ->filters([
                MultiSelectFilter::make('type', 'Jenis data')
                    ->options(collect(ImportRegistry::all())->mapWithKeys(fn (ImportType $t) => [$t->key() => $t->label()])->all()),
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
        $types = array_map(fn (ImportType $t) => $t->key(), ImportRegistry::allowedFor($user));

        return ImportBatch::visibleTo(ImportBatch::query()->with('creator:id,name'), $user)
            ->whereIn('type', $types === [] ? ['-'] : $types);
    }
}
