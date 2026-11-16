<?php

namespace App\Domain\Attendance\Listings;

use App\Domain\Attendance\Enums\AttendanceStatus;
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
 * Riwayat presensi milik sendiri di Pakis Pro (`api.v1.attendance.index`, 07 §3.4
 * `my_attendance_days`, 05 §5.5, T-043). Scope = pegawai pemilik token (diterapkan controller).
 * Bulan wajib (default bulan berjalan) agar query selalu memakai index unik (employee_id, work_date).
 */
final class MyAttendanceDayListing implements HasListing
{
    /** Bulan pilihan filter riwayat di HP (riwayat lebih lama dibuka lewat web / HRD). */
    private const MONTHS_BACK = 12;

    public function spec(): ListingSpec
    {
        return ListingSpec::make('my_attendance_days')
            ->searchPlaceholder('Cari nama shift')
            ->search(TextSearch::make()->relation('shift', 'name', prefix: false))
            ->filters([
                SelectFilter::make('month', 'Bulan')
                    ->options(self::monthOptions())
                    ->default(fn () => DisplayTime::toLocal(now())->format('Y-m'))
                    ->required()
                    ->using(function (Builder $q, mixed $month): void {
                        [$from, $to] = self::monthRange((string) $month);
                        $q->whereBetween('attendance_days.work_date', [$from, $to]);
                    }),
                MultiSelectFilter::make('status', 'Status')
                    ->column('attendance_days.status')
                    ->options(collect(AttendanceStatus::cases())->mapWithKeys(fn (AttendanceStatus $s) => [$s->value => $s->label()])->all()),
            ])
            ->sorts([
                Sort::make('work_date', 'Tanggal')->column('attendance_days.work_date'),
            ])
            ->defaultSort('-work_date');
    }

    /** @return array{0: string, 1: string} tanggal awal & akhir bulan `YYYY-MM` */
    public static function monthRange(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);
        if (! $start instanceof CarbonImmutable) {
            $start = DisplayTime::toLocal(now())->startOfMonth();
        }

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
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
