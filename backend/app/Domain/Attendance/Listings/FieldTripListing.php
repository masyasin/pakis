<?php

namespace App\Domain\Attendance\Listings;

use App\Domain\Attendance\Enums\FieldTripRule;
use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Domain\Attendance\Support\ScheduleFilters;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar tugas luar kota (`admin.field-trips.index`, katalog 07 §3.4 `field_trips`). Ber-scope:
 * tugas yang punya minimal satu pegawai dalam scope pelaku (FieldTrip::constrainToScope, diterapkan
 * controller). Tanpa ekspor (07).
 */
final class FieldTripListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('field_trips')
            ->searchPlaceholder('Cari nomor, tujuan, atau nama pegawai')
            ->search(TextSearch::make()
                ->prefix('number')
                ->contains('destination')
                ->relation('employees', 'full_name', prefix: false))
            ->filters([
                MultiSelectFilter::make('status', 'Status')
                    ->options(FieldTripStatus::options())
                    ->default([FieldTripStatus::Approved->value, FieldTripStatus::Pending->value]),
                // Tugas yang BERIRISAN dengan rentang (tugas 30 Nov–2 Des muncul di November & Desember).
                DateRangeFilter::make('date', 'Tanggal')
                    ->default(fn () => self::thisMonth())
                    ->using(function (Builder $q, array $range): void {
                        if (isset($range['to'])) {
                            $q->where('field_trips.start_date', '<=', $range['to']);
                        }
                        if (isset($range['from'])) {
                            $q->where('field_trips.end_date', '>=', $range['from']);
                        }
                    }),
                ScheduleFilters::organization('employees.')[0],
                SelectFilter::make('rule', 'Aturan lokasi')
                    ->options(array_map(fn (string $label, string $value) => ['value' => $value, 'label' => $label], FieldTripRule::options(), array_keys(FieldTripRule::options())))
                    ->column('attendance_rule'),
            ])
            ->sorts([
                Sort::make('start_date', 'Tanggal mulai'),
                Sort::make('number', 'Nomor'),
            ])
            ->defaultSort('-start_date');
    }

    /** @return array{from: string, to: string} */
    public static function thisMonth(): array
    {
        $now = DisplayTime::toLocal(now());

        return ['from' => $now->startOfMonth()->toDateString(), 'to' => $now->endOfMonth()->toDateString()];
    }
}
