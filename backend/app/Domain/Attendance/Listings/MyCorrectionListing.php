<?php

namespace App\Domain\Attendance\Listings;

use App\Domain\Attendance\Enums\CorrectionStatus;
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
 * Koreksi milik sendiri di Pakis Pro (`api.v1.attendance-corrections.index`, 07 §3.4
 * `my_corrections`). Scope = pegawai pemilik token (diterapkan controller).
 */
final class MyCorrectionListing implements HasListing
{
    /** Bulan pilihan filter: koreksi hanya untuk tanggal ≤ `correction_max_days` (maks 60) ke belakang. */
    private const MONTHS_BACK = 12;

    public function spec(): ListingSpec
    {
        return ListingSpec::make('my_corrections')
            ->searchPlaceholder('Cari nomor koreksi')
            ->search(TextSearch::make()->prefix('number'))
            ->filters([
                MultiSelectFilter::make('status', 'Status')->options(CorrectionStatus::options()),
                SelectFilter::make('month', 'Bulan')
                    ->options(self::monthOptions())
                    ->using(function (Builder $q, mixed $month): void {
                        $start = CarbonImmutable::createFromFormat('!Y-m', (string) $month);
                        if ($start instanceof CarbonImmutable) {
                            $q->whereBetween('attendance_corrections.work_date', [$start->toDateString(), $start->endOfMonth()->toDateString()]);
                        }
                    }),
            ])
            ->sorts([
                Sort::make('created_at', 'Diajukan')->column('attendance_corrections.created_at'),
                Sort::make('work_date', 'Tanggal kerja')->column('attendance_corrections.work_date'),
            ])
            ->defaultSort('-created_at');
    }

    /** @return list<array{value: string, label: string}> bulan terbaru di atas */
    private static function monthOptions(): array
    {
        $base = DisplayTime::toLocal(now())->startOfMonth();
        $options = [];
        for ($i = 0; $i <= self::MONTHS_BACK; $i++) {
            $month = $base->subMonthsNoOverflow($i);
            $options[] = ['value' => $month->format('Y-m'), 'label' => ScheduleDates::month($month)];
        }

        return $options;
    }
}
