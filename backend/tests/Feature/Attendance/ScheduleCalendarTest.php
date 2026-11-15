<?php

use App\Domain\Attendance\ScheduleResolver;
use App\Domain\Employee\Models\Employee;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ScheduleWorld;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 03:00:00'); // Senin 16 Nov 2026, 10:00 WIB
    $this->s = ScheduleWorld::make($this);
    $this->hrd = $this->s->org->actor('hrd');
});

afterEach(fn () => Carbon::setTestNow());

test('07 §3 kalender jadwal: paging tetap 25 pegawai (per_page dari URL diabaikan), default bulan ini', function () {
    for ($i = 0; $i < 26; $i++) {
        $this->s->org->employee(attributes: ['full_name' => sprintf('Pegawai %02d', $i)]);
    }

    $this->actingAs($this->hrd)->get('/jadwal/kalender?per_page=100')
        ->assertInertia(fn (Assert $page) => $page->component('schedules/calendar')
            ->has('rows', 25)
            ->where('listing.pagination.total', 26)
            ->where('listing.pagination.per_page', 25)
            ->where('listing.state.filters.month', '2026-11')
            ->has('rows.0.cells', 30)
            ->where('can.manage', true));

    $this->actingAs($this->hrd)->get('/jadwal/kalender?page=2')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.employee.name', 'Pegawai 25'));
});

test('BR-061 BR-062 isi sel kalender = ScheduleResolver (pola, pengecualian, libur)', function () {
    $e = $this->s->org->employee(attributes: ['full_name' => 'Ani Gudang', 'join_date' => '2026-01-01']);
    $this->s->weekdays($e, '2026-01-01');
    $this->s->override($e, '2026-12-05', $this->s->siang, 'Tukar');
    $this->s->holiday('2026-12-25', 'Natal Uji');

    $expected = array_map(fn ($s) => $s->toArray(), app(ScheduleResolver::class)->forRange([$e->id], '2026-12-01', '2026-12-31')[$e->id]);

    $this->actingAs($this->hrd)->get('/jadwal/kalender?filter[month]=2026-12')
        ->assertInertia(fn (Assert $page) => $page
            ->where('listing.state.filters.month', '2026-12')
            ->where('rows.0.cells', $expected)
            ->where('rows.0.cells.2026-12-01.shift.code', 'PAGI')
            ->where('rows.0.cells.2026-12-05.shift.code', 'SIANG')
            ->where('rows.0.cells.2026-12-05.override', true)
            ->where('rows.0.cells.2026-12-06.kind', 'off')
            ->where('rows.0.cells.2026-12-25.kind', 'holiday')
            ->where('rows.0.cells.2026-12-25.holiday', 'Natal Uji'));
});

test('SEC-032 bulan tidak sah di kalender jatuh ke bulan ini dan dilaporkan', function () {
    $this->s->org->employee();

    $this->actingAs($this->hrd)->get('/jadwal/kalender?filter[month]=2099-01')
        ->assertInertia(fn (Assert $page) => $page
            ->where('listing.state.filters.month', '2026-11')
            ->has('listing.state.invalid_filters.month'));
});

test('07 §3 kalender: cari nama, filter lokasi/jabatan; 06 §5 Kepala Lokasi hanya lokasinya', function () {
    $kepala = $this->s->org->employee($this->s->org->gudang, attributes: ['full_name' => 'Kepala Gudang']);
    $this->s->org->employee($this->s->org->gudang, $kepala, ['full_name' => 'Staf Gudang']);
    $this->s->org->employee($this->s->org->toko, attributes: ['full_name' => 'Staf Toko']);

    $names = function ($user, string $query = '') {
        app()->forgetScopedInstances();
        $rows = test()->actingAs($user)->get('/jadwal/kalender'.$query)->assertOk()->viewData('page')['props']['rows'];

        return array_map(fn (array $r) => $r['employee']['name'], $rows);
    };

    expect($names($this->hrd, '?q=toko'))->toBe(['Staf Toko'])
        ->and($names($this->hrd, "?filter[location]={$this->s->org->gudang->id}"))->toBe(['Kepala Gudang', 'Staf Gudang'])
        ->and($names($this->hrd, "?filter[position]={$this->s->org->staff->id}"))->toHaveCount(3)
        ->and($names($this->s->org->actor('kepala_lokasi', $kepala)))->toBe(['Kepala Gudang', 'Staf Gudang']);

    // Pegawai nonaktif tidak tampil di kalender.
    Employee::query()->where('full_name', 'Staf Toko')->update(['status' => 'inactive', 'exit_date' => '2026-11-01']);
    expect($names($this->hrd))->toBe(['Kepala Gudang', 'Staf Gudang']);
});
