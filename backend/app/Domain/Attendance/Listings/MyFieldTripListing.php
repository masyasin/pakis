<?php

namespace App\Domain\Attendance\Listings;

use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Domain\Attendance\Support\ScheduleDates;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tugas luar kota milik sendiri di Pakis Pro (`api.v1.field-trips.index`, 07 §3.4
 * `my_field_trips`, T-036A bagian 2): tugas yang memuat pegawai pemilik token — baik dibuat
 * admin/atasan maupun diajukan sendiri. Scope diterapkan controller.
 */
final class MyFieldTripListing implements HasListing
{
    /** Bulan pilihan: 12 bulan ke belakang + 2 ke depan (pengajuan untuk perjalanan mendatang). */
    private const MONTHS_BACK = 12;

    private const MONTHS_AHEAD = 2;

    public function spec(): ListingSpec
    {
        return ListingSpec::make('my_field_trips')
            ->searchPlaceholder('Cari nomor atau tujuan')
            ->search(TextSearch::make()->prefix('number')->contains('destination'))
            ->filters([
                MultiSelectFilter::make('status', 'Status')->options(FieldTripStatus::options()),
                // Tugas yang BERIRISAN dengan bulan itu (sama dengan daftar web).
                SelectFilter::make('month', 'Bulan')
                    ->options(self::monthOptions())
                    ->using(function (Builder $q, mixed $month): void {
                        $start = CarbonImmutable::createFromFormat('!Y-m', (string) $month);
                        if ($start instanceof CarbonImmutable) {
                            $q->where('field_trips.start_date', '<=', $start->endOfMonth()->toDateString())
                                ->where('field_trips.end_date', '>=', $start->toDateString());
                        }
                    }),
            ])
            ->sorts([
                Sort::make('start_date', 'Tanggal mulai')->column('field_trips.start_date'),
            ])
            ->defaultSort('-start_date');
    }

    /** @return list<array{value: string, label: string}> bulan terbaru di atas */
    private static function monthOptions(): array
    {
        $base = DisplayTime::toLocal(now())->startOfMonth();
        $options = [];
        for ($i = -self::MONTHS_AHEAD; $i <= self::MONTHS_BACK; $i++) {
            $month = $base->subMonthsNoOverflow($i);
            $options[] = ['value' => $month->format('Y-m'), 'label' => ScheduleDates::month($month)];
        }

        return $options;
    }
}
