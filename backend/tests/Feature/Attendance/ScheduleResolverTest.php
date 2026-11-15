<?php

use App\Domain\Attendance\EffectiveSchedule;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\ScheduleResolver;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use Illuminate\Support\Facades\DB;
use Tests\Support\ScheduleWorld;

// Minggu acuan: Senin 16 Nov 2026 s.d. Minggu 22 Nov 2026.
beforeEach(function () {
    $this->s = ScheduleWorld::make($this);
    $this->resolver = app(ScheduleResolver::class);
});

function scheduledEmployee(ScheduleWorld $s, ?string $location = null): Employee
{
    return $s->org->employee($location === 'toko' ? $s->org->toko : $s->org->gudang, attributes: ['join_date' => '2026-01-01']);
}

/** Ringkasan sel: "shift:PAGI@pattern", "holiday@holiday", "off@override", "unscheduled@none". */
function scheduleCell(EffectiveSchedule $e): string
{
    return ($e->kind === EffectiveSchedule::SHIFT ? 'shift:'.$e->shift?->code : $e->kind).'@'.$e->source;
}

test('BR-061 pola mingguan: hari ber-shift, hari kosong = off, sebelum pola berlaku = tidak dijadwalkan', function () {
    $e = scheduledEmployee($this->s);
    $this->s->weekdays($e, '2026-11-18');

    $week = $this->resolver->forRange([$e->id], '2026-11-16', '2026-11-22')[$e->id];

    expect(array_map(scheduleCell(...), $week))->toBe([
        '2026-11-16' => 'unscheduled@none',
        '2026-11-17' => 'unscheduled@none',
        '2026-11-18' => 'shift:PAGI@pattern',
        '2026-11-19' => 'shift:PAGI@pattern',
        '2026-11-20' => 'shift:PAGI@pattern',
        '2026-11-21' => 'shift:PAGI@pattern',
        '2026-11-22' => 'off@pattern',
    ]);
});

test('BR-061 pengecualian menang atas pola: ganti shift dan libur, catatan ikut', function () {
    $e = scheduledEmployee($this->s);
    $this->s->weekdays($e, '2026-01-01');
    $this->s->override($e, '2026-11-17', $this->s->siang, 'Tukar dengan rekan');
    $this->s->override($e, '2026-11-18', null, 'Izin acara keluarga');
    $this->s->override($e, '2026-11-22', $this->s->pagi, 'Stok opname');

    $week = $this->resolver->forRange([$e->id], '2026-11-16', '2026-11-22')[$e->id];

    expect(scheduleCell($week['2026-11-16']))->toBe('shift:PAGI@pattern')
        ->and(scheduleCell($week['2026-11-17']))->toBe('shift:SIANG@override')
        ->and($week['2026-11-17']->note)->toBe('Tukar dengan rekan')
        ->and($week['2026-11-17']->hasOverride)->toBeTrue()
        ->and(scheduleCell($week['2026-11-18']))->toBe('off@override')
        ->and($week['2026-11-18']->note)->toBe('Izin acara keluarga')
        // Minggu (libur di pola) dijadwalkan masuk lewat pengecualian.
        ->and(scheduleCell($week['2026-11-22']))->toBe('shift:PAGI@override')
        ->and($week['2026-11-16']->hasOverride)->toBeFalse();
});

test('BR-062 hari libur menang atas pola & pengecualian libur; pengecualian ber-shift = masuk di hari libur', function () {
    $a = scheduledEmployee($this->s);
    $b = scheduledEmployee($this->s);
    $c = scheduledEmployee($this->s);
    foreach ([$a, $b, $c] as $e) {
        $this->s->weekdays($e, '2026-01-01');
    }
    $this->s->holiday('2026-11-19', 'Libur Uji', end: '2026-11-20');
    $this->s->override($b, '2026-11-19', null, 'Cuti');
    $this->s->override($c, '2026-11-19', $this->s->siang, 'Piket libur');

    $all = $this->resolver->forRange([$a->id, $b->id, $c->id], '2026-11-19', '2026-11-20');

    expect(scheduleCell($all[$a->id]['2026-11-19']))->toBe('holiday@holiday')
        ->and($all[$a->id]['2026-11-19']->holidayName)->toBe('Libur Uji')
        ->and(scheduleCell($all[$a->id]['2026-11-20']))->toBe('holiday@holiday')
        // Pengecualian "libur" di hari libur tetap berstatus libur resmi (BR-062), catatan dipertahankan.
        ->and(scheduleCell($all[$b->id]['2026-11-19']))->toBe('holiday@holiday')
        ->and($all[$b->id]['2026-11-19']->note)->toBe('Cuti')
        ->and($all[$b->id]['2026-11-19']->hasOverride)->toBeTrue()
        ->and(scheduleCell($all[$c->id]['2026-11-19']))->toBe('shift:SIANG@override')
        ->and($all[$c->id]['2026-11-19']->holidayName)->toBe('Libur Uji')
        ->and(scheduleCell($all[$c->id]['2026-11-20']))->toBe('holiday@holiday');
});

test('BR-062 libur khusus lokasi hanya untuk pegawai berpenugasan utama di lokasi itu', function () {
    $gudang = scheduledEmployee($this->s);
    $toko = scheduledEmployee($this->s, 'toko');
    $this->s->weekdays($gudang, '2026-01-01');
    $this->s->weekdays($toko, '2026-01-01');
    $this->s->holiday('2026-11-17', 'Ulang Tahun Toko', [$this->s->org->toko]);

    $all = $this->resolver->forRange([$gudang->id, $toko->id], '2026-11-17', '2026-11-17');

    expect(scheduleCell($all[$gudang->id]['2026-11-17']))->toBe('shift:PAGI@pattern')
        ->and(scheduleCell($all[$toko->id]['2026-11-17']))->toBe('holiday@holiday');
});

test('BR-062 libur mengikuti lokasi penugasan utama PADA TANGGAL itu (mutasi di tengah rentang)', function () {
    $e = scheduledEmployee($this->s);
    $this->s->weekdays($e, '2026-01-01');
    // Mutasi gudang → toko mulai Kamis 19 Nov.
    Assignment::query()->where('employee_id', $e->id)->update(['end_date' => '2026-11-18']);
    Assignment::query()->create(['employee_id' => $e->id, 'position_id' => $this->s->org->staff->id, 'location_id' => $this->s->org->toko->id, 'is_primary' => true, 'start_date' => '2026-11-19']);
    $this->s->holiday('2026-11-16', 'Libur Toko', [$this->s->org->toko], '2026-11-20');

    $week = $this->resolver->forRange([$e->id], '2026-11-16', '2026-11-20')[$e->id];

    expect(array_map(scheduleCell(...), $week))->toBe([
        '2026-11-16' => 'shift:PAGI@pattern',
        '2026-11-17' => 'shift:PAGI@pattern',
        '2026-11-18' => 'shift:PAGI@pattern',
        '2026-11-19' => 'holiday@holiday',
        '2026-11-20' => 'holiday@holiday',
    ]);
});

test('BR-062 pegawai tanpa penugasan utama hanya terkena libur semua lokasi', function () {
    $e = scheduledEmployee($this->s);
    $this->s->weekdays($e, '2026-01-01');
    Assignment::query()->where('employee_id', $e->id)->delete();
    $this->s->holiday('2026-11-16', 'Libur Gudang', [$this->s->org->gudang]);
    $this->s->holiday('2026-11-17', 'Libur Nasional Uji');

    $week = $this->resolver->forRange([$e->id], '2026-11-16', '2026-11-17')[$e->id];

    expect(scheduleCell($week['2026-11-16']))->toBe('shift:PAGI@pattern')
        ->and(scheduleCell($week['2026-11-17']))->toBe('holiday@holiday');
});

test('BR-061 pola baru di tengah bulan tidak mengubah jadwal sebelum tanggal berlakunya', function () {
    $e = scheduledEmployee($this->s);
    $this->s->weekdays($e, '2026-01-01');
    $this->s->weekdays($e, '2026-11-19', $this->s->siang);

    $week = $this->resolver->forRange([$e->id], '2026-11-17', '2026-11-20')[$e->id];

    expect(array_map(scheduleCell(...), $week))->toBe([
        '2026-11-17' => 'shift:PAGI@pattern',
        '2026-11-18' => 'shift:PAGI@pattern',
        '2026-11-19' => 'shift:SIANG@pattern',
        '2026-11-20' => 'shift:SIANG@pattern',
    ]);
});

test('BR-061 forDate sama dengan forRange; pegawai tak dikenal = tidak dijadwalkan', function () {
    $e = scheduledEmployee($this->s);
    $this->s->weekdays($e, '2026-01-01');

    expect(scheduleCell($this->resolver->forDate($e->id, '2026-11-16')))->toBe('shift:PAGI@pattern')
        ->and(scheduleCell($this->resolver->forDate($e->id, '2026-11-22')))->toBe('off@pattern')
        ->and(scheduleCell($this->resolver->forDate(999_999, '2026-11-16')))->toBe('unscheduled@none')
        ->and($this->resolver->forRange([], '2026-11-16', '2026-11-22'))->toBe([]);
});

test('BR-061 resolver batch: jumlah query tetap berapa pun jumlah pegawai (tanpa N+1)', function () {
    $count = function (int $n): int {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $e = scheduledEmployee($this->s, $i % 2 === 0 ? 'toko' : null);
            $this->s->weekdays($e, '2026-01-01', $i % 2 === 0 ? $this->s->siang : $this->s->pagi);
            $this->s->override($e, '2026-11-18', $this->s->pagi);
            $ids[] = $e->id;
        }
        $this->s->holiday('2026-11-'.(16 + $n), 'Libur '.$n, [$this->s->org->toko]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->resolver->forRange($ids, '2026-11-01', '2026-11-30');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(2))->toBe($count(8));
});

test('BR-062 Holiday::appliesToLocation (dalam memori) sama dengan scope appliesTo (query)', function () {
    $this->s->holiday('2026-11-16', 'Semua', end: '2026-11-17');
    $this->s->holiday('2026-11-17', 'Toko', [$this->s->org->toko]);
    $holidays = Holiday::query()->with('locations:id')->get();

    foreach (['2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18'] as $date) {
        foreach ([null, $this->s->org->gudang->id, $this->s->org->toko->id] as $location) {
            $byQuery = Holiday::query()->appliesTo($date, $location)->orderBy('name')->pluck('name')->all();
            $inMemory = $holidays->filter(fn (Holiday $h) => $h->covers($date) && $h->appliesToLocation($location))->sortBy('name')->pluck('name')->values()->all();

            expect($inMemory)->toBe($byQuery);
        }
    }
});
