<?php

use App\Domain\Organization\Models\Position;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ScheduleWorld;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 03:00:00'); // Senin 16 Nov 2026, 10:00 WIB
    $this->s = ScheduleWorld::make($this);
    $this->hrd = $this->s->org->actor('hrd');
});

afterEach(fn () => Carbon::setTestNow());

/** @return list<string> nama pegawai di daftar pola jadwal */
function scheduleNames(User $user, string $query = ''): array
{
    app()->forgetScopedInstances();
    $names = [];
    test()->actingAs($user)->get('/jadwal'.$query)->assertOk()
        ->assertInertia(function (Assert $page) use (&$names) {
            $names = array_map(fn (array $r) => $r['employee']['name'], $page->toArray()['props']['rows']);
        });

    return $names;
}

test('07 §3.4 schedules: pegawai aktif + pola yang berlaku hari ini, kolom katalog', function () {
    $a = $this->s->org->employee(attributes: ['full_name' => 'Ani Gudang']);
    $this->s->weekdays($a, '2026-01-01');
    $this->s->weekdays($a, '2026-12-01', $this->s->siang); // pola terjadwal belum berlaku
    $this->s->org->employee(attributes: ['full_name' => 'Zaki Nonaktif', 'status' => 'inactive', 'exit_date' => '2026-06-01']);

    $this->actingAs($this->hrd)->get('/jadwal')
        ->assertInertia(fn (Assert $page) => $page->component('schedules/index')
            ->has('rows', 1)
            ->where('rows.0.employee.name', 'Ani Gudang')
            ->where('rows.0.location', 'Gudang Uji')
            ->where('rows.0.position', 'Staf Gudang')
            ->where('rows.0.effective_from', '2026-01-01')
            ->where('rows.0.days.mon.code', 'PAGI')
            ->where('rows.0.days.sun', null)
            ->where('can.manage', true));
});

test('07 §3.4 schedules: cari nama/nomor, filter lokasi/departemen/jabatan/shift/tanpa jadwal, sort nama & berlaku sejak', function () {
    $ani = $this->s->org->employee(attributes: ['full_name' => 'Ani Gudang']);
    $budi = $this->s->org->employee($this->s->org->toko, attributes: ['full_name' => 'Budi Toko']);
    $driver = Position::query()->create(['code' => 'DRV', 'name' => 'Driver', 'department_id' => $this->s->org->department->id]);
    $cici = $this->s->org->employee(attributes: ['full_name' => 'Cici Driver']);
    $cici->assignments()->update(['position_id' => $driver->id]);
    $this->s->weekdays($ani, '2026-03-01');
    $this->s->weekdays($budi, '2026-06-01', $this->s->siang);

    expect(scheduleNames($this->hrd, '?q=budi'))->toBe(['Budi Toko'])
        ->and(scheduleNames($this->hrd, '?q='.$ani->employee_number))->toBe(['Ani Gudang'])
        ->and(scheduleNames($this->hrd, "?filter[location]={$this->s->org->toko->id}"))->toBe(['Budi Toko'])
        ->and(scheduleNames($this->hrd, "?filter[department]={$this->s->org->department->id}"))->toHaveCount(3)
        ->and(scheduleNames($this->hrd, "?filter[position]={$driver->id}"))->toBe(['Cici Driver'])
        ->and(scheduleNames($this->hrd, "?filter[shift]={$this->s->siang->id}"))->toBe(['Budi Toko'])
        ->and(scheduleNames($this->hrd, '?filter[unscheduled]=1'))->toBe(['Cici Driver'])
        ->and(scheduleNames($this->hrd, '?filter[unscheduled]=0'))->toBe(['Ani Gudang', 'Budi Toko'])
        ->and(scheduleNames($this->hrd, '?sort=-employee_name'))->toBe(['Cici Driver', 'Budi Toko', 'Ani Gudang'])
        ->and(scheduleNames($this->hrd, '?sort=-effective_from&filter[unscheduled]=0'))->toBe(['Budi Toko', 'Ani Gudang']);
});

test('06 §5 schedules ber-scope: Kepala Lokasi hanya lokasinya, supervisor timnya', function () {
    $kepala = $this->s->org->employee($this->s->org->gudang, attributes: ['full_name' => 'Kepala Gudang']);
    $this->s->org->employee($this->s->org->gudang, $kepala, ['full_name' => 'Staf Gudang']);
    $this->s->org->employee($this->s->org->toko, attributes: ['full_name' => 'Staf Toko']);
    $spv = $this->s->org->employee($this->s->org->toko, attributes: ['full_name' => 'Supervisor Toko']);
    $this->s->org->employee($this->s->org->toko, $spv, ['full_name' => 'Sales Toko']);

    expect(scheduleNames($this->s->org->actor('kepala_lokasi', $kepala)))->toBe(['Kepala Gudang', 'Staf Gudang'])
        ->and(scheduleNames($this->s->org->actor('supervisor', $spv)))->toBe(['Sales Toko', 'Supervisor Toko']);
});
