<?php

namespace App\Domain\Attendance\Listings;

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Support\LocationScope;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar hari libur (`admin.holidays.index`, katalog 07 §3.4: `holidays`). Tidak ber-scope data:
 * `holidays.view` ✅ untuk semua role (06 §4). Filter Lokasi tetap dibatasi lokasi dalam scope
 * pengguna (sama dengan lookup `locations`).
 */
final class HolidayListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('holidays')
            ->searchPlaceholder('Cari nama atau keterangan')
            ->search(TextSearch::make()->contains('name')->contains('description'))
            ->filters([
                // Default tahun ini (zona kantor); chip bisa dihapus untuk melihat semua tahun.
                // Libur yang BERIRISAN dengan tahun itu (libur 30 Des–2 Jan muncul di dua tahun).
                // Perbandingan langsung (bukan YEAR()) agar index `holidays_range_idx` terpakai.
                SelectFilter::make('year', 'Tahun')
                    ->options(self::yearOptions())
                    ->default(fn () => (string) self::currentYear())
                    ->using(fn (Builder $q, string $year) => $q
                        ->where($q->qualifyColumn('start_date'), '<=', "{$year}-12-31")
                        ->where($q->qualifyColumn('end_date'), '>=', "{$year}-01-01")),
                BooleanFilter::make('is_national', 'Nasional'),
                // "Berlaku di lokasi X" (BR-062): libur semua lokasi + libur khusus lokasi X.
                RelationFilter::make('location', 'Lokasi')
                    ->source('locations')
                    ->validIds(fn () => self::scopedLocations())
                    ->using(fn (Builder $q, int $locationId) => Holiday::constrainToLocation($q, $locationId)),
            ])
            ->sorts([
                Sort::make('date', 'Tanggal')->column('start_date'),
                Sort::make('name', 'Nama'),
            ])
            ->defaultSort('date')
            ->exportable();
    }

    public function exportQuery(User $user): Builder
    {
        return Holiday::query()->with('locations:id,name');
    }

    /**
     * @return ExportColumns<Holiday>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(Holiday::class)
            ->add('Tanggal mulai', fn (Holiday $h) => $h->start_date->toDateString())
            ->add('Tanggal selesai', fn (Holiday $h) => $h->end_date->toDateString())
            ->add('Jumlah hari', fn (Holiday $h) => $h->dayCount())
            ->add('Nama', fn (Holiday $h) => $h->name)
            ->add('Keterangan', fn (Holiday $h) => $h->description)
            ->add('Jenis', fn (Holiday $h) => $h->is_national ? 'Nasional' : 'Perusahaan')
            ->add('Berlaku', fn (Holiday $h) => self::appliesLabel($h));
    }

    /** "Semua lokasi" atau nama lokasi terurut, dipisah koma (ekspor). */
    public static function appliesLabel(Holiday $holiday): string
    {
        return $holiday->applies_to_all
            ? 'Semua lokasi'
            : $holiday->locations->pluck('name')->sort()->implode(', ');
    }

    public static function currentYear(): int
    {
        return CarbonImmutable::now(DisplayTime::timezone())->year;
    }

    /**
     * Tahun yang ada datanya, ditambah tahun lalu s.d. tahun depan (agar HRD bisa menyiapkan
     * kalender tahun berikutnya sebelum ada datanya).
     *
     * @return list<array{value: string, label: string}>
     */
    private static function yearOptions(): array
    {
        $current = self::currentYear();
        $min = Holiday::query()->min('start_date');
        $max = Holiday::query()->max('end_date');

        $from = min($current - 1, is_string($min) ? (int) substr($min, 0, 4) : $current);
        $to = max($current + 1, is_string($max) ? (int) substr($max, 0, 4) : $current);

        $options = [];
        for ($year = $to; $year >= $from; $year--) {
            $options[] = ['value' => (string) $year, 'label' => (string) $year];
        }

        return $options;
    }

    /** @return Builder<Location> */
    private static function scopedLocations(): Builder
    {
        $user = request()->user();
        $query = Location::query();

        // Tanpa pengguna (tidak terjadi di route ber-auth) = fail-closed.
        return $user instanceof User ? app(LocationScope::class)->apply($query, $user) : $query->whereRaw('1 = 0');
    }
}
