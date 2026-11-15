<?php

namespace App\Domain\Attendance\Listings;

use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceSummary;
use App\Domain\Attendance\Support\AttendanceDayScope;
use App\Domain\Attendance\Support\ScheduleDates;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use App\Domain\Organization\Support\LocationScope;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\NumberRangeFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rekap bulanan presensi (`admin.attendance-summary.index`, katalog 07 §3.4 `attendance_summary`,
 * FR-039, BR-072): satu baris per pegawai = agregasi `attendance_days` bulan terpilih.
 *
 * - Hari kerja = hadir + tidak hadir; hadir = ada presensi masuk (hadir/telat/pulang cepat/tidak
 *   absen pulang); % kehadiran = hadir ÷ hari kerja; libur = libur resmi + tidak dijadwalkan
 *   (keputusan Mas 16 Nov 2026).
 * - Ber-scope AttendanceDayScope (scope lokasi = snapshot hari itu), jadi kepala lokasi hanya
 *   menghitung hari pegawai di lokasinya. Filter lokasi/departemen/jabatan juga pada snapshot.
 * - Bulan = filter wajib (default bulan ini) sehingga query selalu memakai index `work_date`.
 */
final class AttendanceSummaryListing implements ExportableListing
{
    private const MONTHS_BACK = 24;

    /**
     * Daftar status sebagai literal SQL (nilai ENUM `attendance_days.status`, BR-072). Literal di kode,
     * bukan dirangkai dari data, agar SQL mentah tetap `literal-string` (tanpa interpolasi).
     */
    private const WORKED_SQL = "'present','late','early_leave','no_checkout'";

    private const SCHEDULED_SQL = "'present','late','early_leave','no_checkout','absent'";

    private const OFF_SQL = "'holiday','off'";

    public function spec(): ListingSpec
    {
        return ListingSpec::make('attendance_summary')
            ->searchPlaceholder('Cari nama atau nomor pegawai')
            ->search(TextSearch::make()
                ->relation('employee', 'full_name', prefix: false)
                ->relation('employee', 'employee_number'))
            ->filters([
                SelectFilter::make('month', 'Bulan')
                    ->options(self::monthOptions())
                    ->default(fn () => self::currentMonth())
                    ->required()
                    ->using(fn (Builder $q, string $month) => self::forMonth($q, $month)),
                RelationFilter::make('location', 'Lokasi')
                    ->source('locations')
                    ->validIds(fn () => self::visibleLocations())
                    ->column('location_id'),
                RelationFilter::make('department', 'Departemen')
                    ->source('departments')
                    ->validIds(fn () => Department::query())
                    ->column('department_id'),
                RelationFilter::make('position', 'Jabatan')
                    ->source('positions')
                    ->validIds(fn () => Position::query())
                    ->column('position_id'),
                NumberRangeFilter::make('attendance_pct', '% kehadiran')
                    ->using(function (Builder $q, array $range): void {
                        if (isset($range['min'])) {
                            $q->havingRaw('attendance_pct >= ?', [$range['min']]);
                        }
                        if (isset($range['max'])) {
                            $q->havingRaw('attendance_pct <= ?', [$range['max']]);
                        }
                    }),
            ])
            ->sorts([
                Sort::make('employee_name', 'Nama pegawai')->column('employees.full_name'),
                Sort::make('attendance_pct', '% kehadiran')
                    ->using(fn (Builder $q, string $direction) => $q->orderBy('attendance_pct', $direction)),
                Sort::make('late_count', 'Jumlah telat')
                    ->using(fn (Builder $q, string $direction) => $q->orderBy('late_count', $direction)),
            ])
            ->defaultSort('employee_name')
            ->exportable();
    }

    /** @return Builder<AttendanceSummary> */
    public function exportQuery(User $user): Builder
    {
        $query = AttendanceSummary::query()
            ->join('employees', 'employees.id', '=', 'attendance_days.employee_id')
            ->whereNull('employees.deleted_at')
            ->select([
                'attendance_days.employee_id',
                'employees.full_name',
                'employees.employee_number',
            ])
            ->selectRaw('SUM(attendance_days.status IN ('.self::SCHEDULED_SQL.')) AS scheduled_days')
            ->selectRaw('SUM(attendance_days.status IN ('.self::WORKED_SQL.')) AS present_days')
            ->selectRaw('SUM(attendance_days.late_minutes > 0) AS late_count')
            ->selectRaw('COALESCE(SUM(attendance_days.late_minutes), 0) AS late_minutes_total')
            ->selectRaw('SUM(attendance_days.early_leave_minutes > 0) AS early_leave_count')
            ->selectRaw("SUM(attendance_days.status = 'no_checkout') AS no_checkout_days")
            ->selectRaw("SUM(attendance_days.status = 'absent') AS absent_days")
            ->selectRaw('SUM(attendance_days.status IN ('.self::OFF_SQL.')) AS off_days')
            ->selectRaw('ROUND(100 * SUM(attendance_days.status IN ('.self::WORKED_SQL.')) / NULLIF(SUM(attendance_days.status IN ('.self::SCHEDULED_SQL.')), 0), 1) AS attendance_pct')
            // Lokasi snapshot hari TERAKHIR di bulan itu, tanpa subquery per baris: "YYYY-MM-DD|id"
            // terbesar = tanggal terakhir; bagian setelah "|" = id lokasi.
            ->selectRaw("SUBSTRING_INDEX(MAX(CONCAT(attendance_days.work_date, '|', COALESCE(attendance_days.location_id, ''))), '|', -1) AS last_location_id")
            ->groupBy('attendance_days.employee_id', 'employees.full_name', 'employees.employee_number');

        // Telusuri per pegawai lewat index unik (employee_id, work_date). Tanpa petunjuk ini MariaDB
        // memilih index tanggal lalu mengelompokkan lewat tabel sementara: ±2 dtk vs ±0,2 dtk pada
        // 200.000 baris (uji T-039, `pakis_one_perf`). Bukan perubahan skema, hanya rencana query.
        $query->getQuery()->forceIndex('att_days_emp_date_unique');

        $days = app(AttendanceDayScope::class)->apply(AttendanceDay::query(), $user);
        // Scope dipasang pada baris hari (WHERE), sama dengan daftar harian.
        $query->getQuery()->mergeWheres($days->getQuery()->wheres, $days->getQuery()->getRawBindings()['where']);

        return $query;
    }

    /** @param  Builder<*>  $query */
    private static function forMonth(Builder $query, string $month): void
    {
        [$from, $to] = self::monthRange($month);
        $query->whereBetween('attendance_days.work_date', [$from, $to]);
    }

    /**
     * Nama lokasi (termasuk yang sudah dihapus) untuk kolom "Lokasi". Tabel lokasi kecil, jadi
     * pemanggil memuatnya SEKALI per halaman/ekspor alih-alih subquery per baris. Sengaja tanpa
     * cache statis: proses antrean/test berumur panjang tidak boleh memakai nama basi.
     *
     * @return array<int, string>
     */
    public static function locationNames(): array
    {
        return Location::withTrashed()->pluck('name', 'id')->map(fn (mixed $n) => (string) $n)->all();
    }

    /** @param  array<int, string>  $names  hasil locationNames() */
    public static function locationOf(AttendanceSummary $row, array $names): ?string
    {
        $id = $row->last_location_id === null || $row->last_location_id === '' ? null : (int) $row->last_location_id;

        return $id === null ? null : ($names[$id] ?? null);
    }

    /** @return array{0: string, 1: string} awal & akhir bulan (Y-m-d) */
    public static function monthRange(string $month): array
    {
        // Nilai bulan sudah divalidasi filter (salah satu opsi `YYYY-MM`).
        $start = CarbonImmutable::parse($month.'-01');

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
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
        for ($i = 0; $i <= self::MONTHS_BACK; $i++) {
            $month = $base->subMonthsNoOverflow($i);
            $options[] = ['value' => $month->format('Y-m'), 'label' => ScheduleDates::month($month)];
        }

        return $options;
    }

    /** @return Builder<Location> */
    private static function visibleLocations(): Builder
    {
        $user = request()->user();

        return $user instanceof User
            ? app(LocationScope::class)->apply(Location::query(), $user)
            : Location::query()->whereRaw('1 = 0');
    }

    /**
     * @return ExportColumns<AttendanceSummary>
     */
    public function exportColumns(): ExportColumns
    {
        $names = null;

        return ExportColumns::for(AttendanceSummary::class)
            ->add('No. pegawai', fn (AttendanceSummary $s) => $s->employee_number)
            ->add('Nama', fn (AttendanceSummary $s) => $s->full_name)
            ->add('Lokasi', function (AttendanceSummary $s) use (&$names) {
                $names ??= self::locationNames();

                return self::locationOf($s, $names);
            })
            ->add('Hari kerja', fn (AttendanceSummary $s) => (int) $s->scheduled_days)
            ->add('Hadir', fn (AttendanceSummary $s) => (int) $s->present_days)
            ->add('Telat', fn (AttendanceSummary $s) => (int) $s->late_count)
            ->add('Total menit telat', fn (AttendanceSummary $s) => (int) $s->late_minutes_total)
            ->add('Pulang cepat', fn (AttendanceSummary $s) => (int) $s->early_leave_count)
            ->add('Tidak absen pulang', fn (AttendanceSummary $s) => (int) $s->no_checkout_days)
            ->add('Tidak hadir', fn (AttendanceSummary $s) => (int) $s->absent_days)
            ->add('Libur', fn (AttendanceSummary $s) => (int) $s->off_days)
            ->add('% kehadiran', fn (AttendanceSummary $s) => $s->attendance_pct === null ? null : (float) $s->attendance_pct);
    }
}
