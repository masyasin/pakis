<?php

namespace App\Domain\Attendance\Listings;

use App\Domain\Attendance\Support\ScheduleDates;
use App\Domain\Attendance\Support\ScheduleFilters;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;

/**
 * Spec kalender jadwal (`admin.schedules.calendar`, 07 §3 "halaman non-daftar yang tetap butuh
 * paging/cari/filter"): baris = pegawai AKTIF dalam scope, paging tetap 25, cari nama/nomor,
 * filter lokasi/departemen/jabatan, dan bulan. Bukan route `*.index`, jadi tidak masuk katalog
 * 07 §3.4; tetap memakai mesin listing agar parameter tervalidasi (SEC-032) dan URL konsisten.
 *
 * Bulan adalah filter WAJIB (`filter[month]=YYYY-MM`, default bulan ini) seperti `attendance_summary`
 * 07 §3.4; filter ini tidak menyaring pegawai, hanya menentukan kolom tanggal kalender.
 */
final class ScheduleCalendarListing implements HasListing
{
    /** 07 §3: kalender berpaging per 25 pegawai (bukan pilihan pengguna). */
    public const PER_PAGE = 25;

    /** Rentang pilihan bulan: setahun ke belakang (tinjauan) s.d. enam bulan ke depan (perencanaan). */
    private const MONTHS_BACK = 12;

    private const MONTHS_AHEAD = 6;

    public function spec(): ListingSpec
    {
        return ListingSpec::make('schedule_calendar')
            ->searchPlaceholder('Cari nama atau nomor pegawai')
            ->search(TextSearch::make()->prefix('employee_number')->contains('full_name'))
            ->filters([
                SelectFilter::make('month', 'Bulan')
                    ->options(self::monthOptions())
                    ->default(fn () => self::currentMonth())
                    ->required()
                    ->using(fn () => null),
                ...ScheduleFilters::organization(),
            ])
            ->sorts([Sort::make('employee_name', 'Nama pegawai')->column('full_name')])
            ->defaultSort('employee_name')
            ->perPageOptions([self::PER_PAGE], self::PER_PAGE);
    }

    public static function currentMonth(): string
    {
        return DisplayTime::toLocal(now())->format('Y-m');
    }

    /** @return list<array{value: string, label: string}> bulan terbaru di atas */
    private static function monthOptions(): array
    {
        $base = CarbonImmutable::createFromFormat('!Y-m', self::currentMonth());
        if (! $base instanceof CarbonImmutable) {
            return [];
        }

        $options = [];
        for ($i = self::MONTHS_AHEAD; $i >= -self::MONTHS_BACK; $i--) {
            $month = $base->addMonthsNoOverflow($i);
            $options[] = ['value' => $month->format('Y-m'), 'label' => ScheduleDates::month($month)];
        }

        return $options;
    }
}
