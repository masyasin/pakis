<?php

use App\Domain\Attendance\Models\ScheduleOverride;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\ScheduleWorld;

uses(AssertsIdor::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 03:00:00'); // Senin 16 Nov 2026, 10:00 WIB
    $this->s = ScheduleWorld::make($this);
    $this->hrd = $this->s->org->actor('hrd');
});

afterEach(fn () => Carbon::setTestNow());

/** @return list<string> "tanggal nama" di daftar pengecualian */
function overrideRows(User $user, string $query = ''): array
{
    app()->forgetScopedInstances();
    $rows = [];
    test()->actingAs($user)->get('/jadwal/pengecualian'.$query)->assertOk()
        ->assertInertia(function (Assert $page) use (&$rows) {
            $rows = array_map(fn (array $r) => $r['date'].' '.$r['employee']['name'], $page->toArray()['props']['rows']);
        });

    return $rows;
}

test('FR-032 BR-061 pengecualian massal per tanggal: upsert (pegawai, tanggal), libur = shift kosong, audit schedule.override_set', function () {
    $a = $this->s->org->employee();
    $b = $this->s->org->employee();

    $this->actingAs($this->hrd)->from('/jadwal/kalender')->post('/jadwal/pengecualian', [
        'employee_ids' => [$a->id, $b->id], 'date' => '2026-11-20', 'shift_id' => $this->s->siang->id, 'note' => '  Stok opname  ',
    ])->assertRedirect('/jadwal/kalender')
        ->assertInertiaFlash('toast.message', 'Pengecualian jadwal 20 November 2026 disimpan untuk 2 pegawai.');

    expect(ScheduleOverride::query()->count())->toBe(2)
        ->and(ScheduleOverride::query()->where('employee_id', $a->id)->sole()->note)->toBe('Stok opname');

    // Tanggal sama → baris yang sama ditimpa (libur), bukan baris baru.
    $this->actingAs($this->hrd)->post('/jadwal/pengecualian', ['employee_ids' => [$a->id], 'date' => '2026-11-20', 'shift_id' => '', 'note' => 'Izin'])->assertRedirect();
    $override = ScheduleOverride::query()->where('employee_id', $a->id)->sole();
    expect($override->shift_id)->toBeNull()
        ->and($override->isDayOff())->toBeTrue()
        ->and(ScheduleOverride::query()->count())->toBe(2);

    $audits = AuditLog::query()->where('event', 'schedule.override_set')->where('auditable_id', $override->id)->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits[0]->new_values)->toMatchArray(['employee_id' => $a->id, 'date' => '2026-11-20', 'shift_id' => $this->s->siang->id])
        ->and($audits[1]->old_values)->toBe(['shift_id' => $this->s->siang->id, 'note' => 'Stok opname'])
        ->and($audits[1]->new_values)->toBe(['shift_id' => null, 'note' => 'Izin']);
});

test('FR-032 validasi pengecualian: tanggal wajib, catatan ≤ 255, shift nonaktif ditolak', function () {
    $e = $this->s->org->employee();
    $inactive = ScheduleWorld::shift(['code' => 'MATI', 'is_active' => false]);

    $this->actingAs($this->hrd)->post('/jadwal/pengecualian', ['employee_ids' => [$e->id], 'date' => ''])->assertSessionHasErrors(['date' => 'Isi tanggal pengecualian.']);
    $this->actingAs($this->hrd)->post('/jadwal/pengecualian', ['employee_ids' => [$e->id], 'date' => '2026-11-20', 'note' => str_repeat('a', 256)])->assertSessionHasErrors('note');
    $this->actingAs($this->hrd)->post('/jadwal/pengecualian', ['employee_ids' => [$e->id], 'date' => '2026-11-20', 'shift_id' => $inactive->id])
        ->assertSessionHasErrors(['shift_id' => 'Shift tidak ditemukan atau sudah nonaktif.']);

    expect(ScheduleOverride::query()->count())->toBe(0);
});

test('SEC-033 pengecualian massal ber-scope: pegawai di luar lokasi Kepala Lokasi membatalkan semua', function () {
    $kepala = $this->s->org->employee($this->s->org->gudang);
    $luar = $this->s->org->employee($this->s->org->toko);
    $actor = $this->s->org->actor('kepala_lokasi', $kepala);

    $this->actingAs($actor)->post('/jadwal/pengecualian', ['employee_ids' => [$kepala->id, $luar->id], 'date' => '2026-11-20', 'shift_id' => ''])
        ->assertSessionHasErrors(['employee_ids' => 'Ada pegawai yang tidak ditemukan, sudah nonaktif, atau di luar wewenang Anda.']);
    expect(ScheduleOverride::query()->count())->toBe(0);

    // Libur (tanpa shift) untuk pegawai lokasinya sendiri boleh (schedules.manage 🔸).
    $this->actingAs($actor)->post('/jadwal/pengecualian', ['employee_ids' => [$kepala->id], 'date' => '2026-11-20', 'shift_id' => ''])->assertSessionHasNoErrors();
    expect(ScheduleOverride::query()->count())->toBe(1);
});

test('05 §4.9 hapus pengecualian: audit schedule.override_deleted, tanggal kembali ke pola', function () {
    $e = $this->s->org->employee();
    $override = $this->s->override($e, '2026-11-20', null, 'Cuti');

    $this->actingAs($this->hrd)->from('/jadwal/pengecualian')->delete("/jadwal/pengecualian/{$override->id}")
        ->assertRedirect('/jadwal/pengecualian')
        ->assertInertiaFlash('toast.message', 'Pengecualian jadwal 20 November 2026 dihapus; tanggal itu kembali mengikuti pola.');

    expect(ScheduleOverride::query()->count())->toBe(0);
    $audit = AuditLog::query()->where('event', 'schedule.override_deleted')->sole();
    expect($audit->old_values)->toMatchArray(['employee_id' => $e->id, 'date' => '2026-11-20', 'shift_id' => null, 'note' => 'Cuti'])
        ->and($audit->new_values)->toBeNull();
});

test('SEC-033 IDOR admin.schedule-overrides.destroy: pengecualian pegawai di luar scope → 404', function () {
    $kepala = $this->s->org->employee($this->s->org->gudang);
    $override = $this->s->override($this->s->org->employee($this->s->org->toko), '2026-11-20', null);

    $this->assertOutOfScope404($this->s->org->actor('kepala_lokasi', $kepala), 'DELETE', "/jadwal/pengecualian/{$override->id}");
    expect(ScheduleOverride::query()->whereKey($override->id)->exists())->toBeTrue();
});

test('07 §3.4 schedule_overrides: default bulan ini, cari nama/catatan, filter lokasi/shift/libur, sort tanggal & nama', function () {
    $ani = $this->s->org->employee(attributes: ['full_name' => 'Ani Gudang']);
    $budi = $this->s->org->employee($this->s->org->toko, attributes: ['full_name' => 'Budi Toko']);
    $this->s->override($ani, '2026-11-05', $this->s->siang, 'Tukar shift');
    $this->s->override($budi, '2026-11-20', null, 'Acara keluarga');
    $this->s->override($ani, '2026-10-30', null, 'Bulan lalu');

    expect(overrideRows($this->hrd))->toBe(['2026-11-20 Budi Toko', '2026-11-05 Ani Gudang'])
        ->and(overrideRows($this->hrd, '?filter[date]='))->toHaveCount(3)
        ->and(overrideRows($this->hrd, '?q=budi'))->toBe(['2026-11-20 Budi Toko'])
        ->and(overrideRows($this->hrd, '?q=tukar'))->toBe(['2026-11-05 Ani Gudang'])
        ->and(overrideRows($this->hrd, "?filter[location]={$this->s->org->toko->id}"))->toBe(['2026-11-20 Budi Toko'])
        ->and(overrideRows($this->hrd, "?filter[shift]={$this->s->siang->id}"))->toBe(['2026-11-05 Ani Gudang'])
        ->and(overrideRows($this->hrd, '?filter[day_off]=1'))->toBe(['2026-11-20 Budi Toko'])
        ->and(overrideRows($this->hrd, '?sort=date'))->toBe(['2026-11-05 Ani Gudang', '2026-11-20 Budi Toko'])
        ->and(overrideRows($this->hrd, '?sort=-employee_name'))->toBe(['2026-11-20 Budi Toko', '2026-11-05 Ani Gudang']);

    $this->actingAs($this->hrd)->get('/jadwal/pengecualian')
        ->assertInertia(fn (Assert $page) => $page->component('schedule-overrides/index')
            ->where('rows.0.shift', null)
            ->where('rows.1.shift.code', 'SIANG')
            ->where('rows.1.shift.active', true)
            ->where('rows.1.note', 'Tukar shift')
            ->where('can.manage', true));
});

test('06 §5 schedule_overrides ber-scope lokasi Kepala Lokasi', function () {
    $kepala = $this->s->org->employee($this->s->org->gudang, attributes: ['full_name' => 'Kepala Gudang']);
    $this->s->override($kepala, '2026-11-05', null);
    $this->s->override($this->s->org->employee($this->s->org->toko, attributes: ['full_name' => 'Staf Toko']), '2026-11-06', null);

    expect(overrideRows($this->s->org->actor('kepala_lokasi', $kepala)))->toBe(['2026-11-05 Kepala Gudang']);
});

test('FR-026 ekspor pengecualian jadwal mengunduh berkas', function () {
    $this->s->override($this->s->org->employee(), '2026-11-05', $this->s->pagi, 'Piket');

    $response = $this->actingAs($this->hrd)->get('/jadwal/pengecualian/ekspor');
    $response->assertOk();
    expect((string) $response->headers->get('content-disposition'))->toContain('.xlsx');
});
