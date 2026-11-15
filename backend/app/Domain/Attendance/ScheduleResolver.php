<?php

namespace App\Domain\Attendance;

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\ScheduleOverride;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftPattern;
use App\Domain\Employee\Models\Assignment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Jadwal efektif (pegawai, tanggal) sesuai BR-061/BR-062. Titik pakai T-036 (absen) dan T-037
 * (status harian). Urutan keputusan per tanggal:
 *
 * 1. pengecualian ber-shift            → shift   (masuk di hari libur = hari kerja biasa, BR-062)
 * 2. hari libur di lokasi pada tanggal → holiday (termasuk bila ada pengecualian "libur")
 * 3. pengecualian libur                → off
 * 4. pola berlaku dengan shift hari itu → shift
 * 5. pola berlaku, hari itu kosong     → off
 * 6. tanpa pola berlaku                → unscheduled
 *
 * Lokasi untuk libur = lokasi penugasan UTAMA yang berlaku PADA tanggal itu (bukan hari ini), agar
 * hitung ulang tanggal lampau setelah mutasi tetap benar. Semua data diambil per batch dengan
 * jumlah query tetap (tanpa N+1), lalu dievaluasi di memori.
 *
 * Resolver tidak menilai status kepegawaian (aktif/keluar/tanggal masuk); itu urusan pemanggil.
 */
final class ScheduleResolver
{
    /**
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, EffectiveSchedule>> pegawai → ('Y-m-d' → jadwal), urut tanggal
     */
    public function forRange(array $employeeIds, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $from = self::day($from);
        $to = self::day($to);
        if ($to < $from) {
            throw new InvalidArgumentException("Rentang jadwal terbalik: {$from} > {$to}.");
        }

        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            return [];
        }

        $patterns = $this->patterns($employeeIds, $to);
        $overrides = $this->overrides($employeeIds, $from, $to);
        $assignments = $this->primaryAssignments($employeeIds, $from, $to);
        $holidays = $this->holidays($from, $to);
        $shifts = $this->shifts($patterns, $overrides);

        $dates = self::dates($from, $to);
        $result = [];

        foreach ($employeeIds as $employeeId) {
            $result[$employeeId] = [];
            foreach ($dates as $date => $carbon) {
                $result[$employeeId][$date] = $this->decide(
                    $carbon,
                    $overrides[$employeeId][$date] ?? null,
                    self::latestOn($patterns[$employeeId] ?? [], $date),
                    self::holidayFor($holidays, $date, self::locationOn($assignments[$employeeId] ?? [], $date)),
                    $shifts,
                );
            }
        }

        return $result;
    }

    public function forDate(int $employeeId, CarbonInterface|string $date): EffectiveSchedule
    {
        $day = self::day($date);

        return $this->forRange([$employeeId], $day, $day)[$employeeId][$day];
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     */
    private function decide(CarbonImmutable $date, ?ScheduleOverride $override, ?ShiftPattern $pattern, ?Holiday $holiday, Collection $shifts): EffectiveSchedule
    {
        $note = $override?->note;
        $hasOverride = $override !== null;
        $overrideId = $override?->id;
        $holidayName = $holiday?->name;

        if ($override !== null && $override->shift_id !== null) {
            return new EffectiveSchedule(EffectiveSchedule::SHIFT, EffectiveSchedule::FROM_OVERRIDE, $shifts->get($override->shift_id), $holidayName, $note, true, $overrideId);
        }

        if ($holiday !== null) {
            return new EffectiveSchedule(EffectiveSchedule::HOLIDAY, EffectiveSchedule::FROM_HOLIDAY, null, $holidayName, $note, $hasOverride, $overrideId);
        }

        if ($override !== null) {
            return new EffectiveSchedule(EffectiveSchedule::OFF, EffectiveSchedule::FROM_OVERRIDE, null, null, $note, true, $overrideId);
        }

        if ($pattern === null) {
            return new EffectiveSchedule(EffectiveSchedule::UNSCHEDULED, EffectiveSchedule::FROM_NONE);
        }

        $shiftId = $pattern->shiftIdOn($date);

        return $shiftId === null
            ? new EffectiveSchedule(EffectiveSchedule::OFF, EffectiveSchedule::FROM_PATTERN)
            : new EffectiveSchedule(EffectiveSchedule::SHIFT, EffectiveSchedule::FROM_PATTERN, $shifts->get($shiftId));
    }

    /**
     * Semua pola yang mulai berlaku ≤ akhir rentang (pola terakhir sebelum `from` juga dibutuhkan).
     *
     * @param  list<int>  $employeeIds
     * @return array<int, list<ShiftPattern>> urut effective_from naik
     */
    private function patterns(array $employeeIds, string $to): array
    {
        $grouped = [];
        ShiftPattern::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('effective_from', '<=', $to)
            ->orderBy('effective_from')
            ->get()
            ->each(function (ShiftPattern $p) use (&$grouped) {
                $grouped[$p->employee_id][] = $p;
            });

        return $grouped;
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, ScheduleOverride>>
     */
    private function overrides(array $employeeIds, string $from, string $to): array
    {
        $grouped = [];
        ScheduleOverride::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->each(function (ScheduleOverride $o) use (&$grouped) {
                $grouped[$o->employee_id][$o->date->toDateString()] = $o;
            });

        return $grouped;
    }

    /**
     * Penugasan utama yang beririsan dengan rentang (termasuk yang sudah berakhir: mutasi di tengah).
     *
     * @param  list<int>  $employeeIds
     * @return array<int, list<Assignment>> urut start_date naik
     */
    private function primaryAssignments(array $employeeIds, string $from, string $to): array
    {
        $grouped = [];
        Assignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('is_primary', true)
            ->where('start_date', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get(['id', 'employee_id', 'location_id', 'start_date', 'end_date'])
            ->each(function (Assignment $a) use (&$grouped) {
                $grouped[$a->employee_id][] = $a;
            });

        return $grouped;
    }

    /** @return Collection<int, Holiday> */
    private function holidays(string $from, string $to): Collection
    {
        // Perbandingan langsung agar index `holidays_range_idx` terpakai (lihat Holiday::scopeAppliesTo).
        return Holiday::query()
            ->where('start_date', '<=', $to)
            ->where('end_date', '>=', $from)
            ->with('locations:id')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Termasuk shift terhapus (soft delete): jadwal lampau tetap menampilkan shift aslinya.
     *
     * @param  array<int, list<ShiftPattern>>  $patterns
     * @param  array<int, array<string, ScheduleOverride>>  $overrides
     * @return Collection<int, Shift>
     */
    private function shifts(array $patterns, array $overrides): Collection
    {
        $ids = [];
        foreach ($patterns as $list) {
            foreach ($list as $pattern) {
                foreach ($pattern->days() as $id) {
                    if ($id !== null) {
                        $ids[$id] = true;
                    }
                }
            }
        }
        foreach ($overrides as $byDate) {
            foreach ($byDate as $override) {
                if ($override->shift_id !== null) {
                    $ids[$override->shift_id] = true;
                }
            }
        }

        if ($ids === []) {
            return new Collection;
        }

        return Shift::withTrashed()->whereIn('id', array_keys($ids))->get()->keyBy('id');
    }

    /** @param  list<ShiftPattern>  $patterns  urut effective_from naik */
    private static function latestOn(array $patterns, string $date): ?ShiftPattern
    {
        $found = null;
        foreach ($patterns as $pattern) {
            if ($pattern->effective_from->toDateString() > $date) {
                break;
            }
            $found = $pattern;
        }

        return $found;
    }

    /** @param  list<Assignment>  $assignments  urut start_date naik */
    private static function locationOn(array $assignments, string $date): ?int
    {
        $location = null;
        foreach ($assignments as $a) {
            if ($a->start_date->toDateString() > $date) {
                break;
            }
            if ($a->end_date === null || $a->end_date->toDateString() >= $date) {
                $location = $a->location_id;
            }
        }

        return $location;
    }

    /** @param  Collection<int, Holiday>  $holidays */
    private static function holidayFor(Collection $holidays, string $date, ?int $locationId): ?Holiday
    {
        return $holidays->first(fn (Holiday $h) => $h->covers($date) && $h->appliesToLocation($locationId));
    }

    /** @return array<string, CarbonImmutable> */
    private static function dates(string $from, string $to): array
    {
        $dates = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $dates[$d->toDateString()] = $d;
        }

        return $dates;
    }

    /** Tanggal lokal `Y-m-d`. Carbon dipakai tanggal kalendernya apa adanya (tanpa konversi zona). */
    private static function day(CarbonInterface|string $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->toDateString();
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsed instanceof CarbonImmutable || $parsed->toDateString() !== $date) {
            throw new InvalidArgumentException("Tanggal jadwal tidak sah: {$date}.");
        }

        return $date;
    }
}
