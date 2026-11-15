<?php

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftPattern;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Employee\Models\Employee;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ScheduleWorld;

beforeEach(function () {
    $this->s = ScheduleWorld::make($this);
    $this->hrd = $this->s->org->actor('hrd');
});

/** @return array<string, mixed> */
function patternPayload(ScheduleWorld $s, array $employees, string $from, array $override = []): array
{
    return array_replace_recursive([
        'employee_ids' => array_map(fn (Employee $e) => $e->id, $employees),
        'effective_from' => $from,
        'days' => ['mon' => $s->pagi->id, 'tue' => $s->pagi->id, 'wed' => $s->pagi->id, 'thu' => $s->pagi->id, 'fri' => $s->pagi->id, 'sat' => $s->siang->id, 'sun' => ''],
    ], $override);
}

test('FR-032 BR-061 tetapkan pola massal: satu baris per pegawai, Minggu kosong = libur, audit schedule.pattern_set', function () {
    $a = $this->s->org->employee();
    $b = $this->s->org->employee($this->s->org->toko);

    $this->actingAs($this->hrd)->from('/jadwal')->post('/jadwal/pola', patternPayload($this->s, [$a, $b], '2026-12-01'))
        ->assertRedirect('/jadwal')
        ->assertInertiaFlash('toast.message', 'Pola jadwal ditetapkan untuk 2 pegawai, berlaku mulai 1 Desember 2026.');

    $pattern = ShiftPattern::query()->where('employee_id', $a->id)->sole();
    expect($pattern->effective_from->toDateString())->toBe('2026-12-01')
        ->and($pattern->days())->toBe(['mon' => $this->s->pagi->id, 'tue' => $this->s->pagi->id, 'wed' => $this->s->pagi->id, 'thu' => $this->s->pagi->id, 'fri' => $this->s->pagi->id, 'sat' => $this->s->siang->id, 'sun' => null])
        ->and($pattern->created_by)->toBe($this->hrd->id)
        ->and(ShiftPattern::query()->count())->toBe(2);

    $audit = AuditLog::query()->where('event', 'schedule.pattern_set')->where('auditable_id', $pattern->id)->sole();
    expect($audit->new_values)->toMatchArray(['employee_id' => $a->id, 'effective_from' => '2026-12-01', 'sat_shift_id' => $this->s->siang->id, 'sun_shift_id' => null]);
});

test('BR-061 pola dengan effective_from baru TIDAK menghapus pola lama (riwayat); tanggal sama memperbarui', function () {
    $e = $this->s->org->employee();
    $old = $this->s->weekdays($e, '2026-01-01');

    $this->actingAs($this->hrd)->post('/jadwal/pola', patternPayload($this->s, [$e], '2026-12-01'))->assertRedirect();
    expect(ShiftPattern::query()->where('employee_id', $e->id)->orderBy('effective_from')->pluck('effective_from')->map->toDateString()->all())
        ->toBe(['2026-01-01', '2026-12-01'])
        ->and($old->fresh()?->days())->toBe($old->days());

    // Tanggal sama → baris yang sama diperbarui (bukan baris ketiga), audit menyimpan perubahannya saja.
    $this->actingAs($this->hrd)->post('/jadwal/pola', patternPayload($this->s, [$e], '2026-12-01', ['days' => ['sat' => '']]))->assertRedirect();
    $latest = ShiftPattern::query()->where('employee_id', $e->id)->where('effective_from', '2026-12-01')->sole();
    expect(ShiftPattern::query()->where('employee_id', $e->id)->count())->toBe(2)
        ->and($latest->sat_shift_id)->toBeNull();

    $audit = AuditLog::query()->where('event', 'schedule.pattern_set')->where('auditable_id', $latest->id)->latest('id')->first();
    expect($audit?->old_values)->toBe(['sat_shift_id' => $this->s->siang->id])
        ->and($audit?->new_values)->toBe(['sat_shift_id' => null]);

    // Kirim ulang isi yang sama: tidak ada audit baru.
    $before = AuditLog::query()->where('event', 'schedule.pattern_set')->count();
    $this->actingAs($this->hrd)->post('/jadwal/pola', patternPayload($this->s, [$e], '2026-12-01', ['days' => ['sat' => '']]))
        ->assertInertiaFlash('toast.message', 'Pola jadwal tidak berubah.');
    expect(AuditLog::query()->where('event', 'schedule.pattern_set')->count())->toBe($before);
});

test('FR-032 validasi pola: shift nonaktif, tanggal salah, tanpa pegawai, pegawai nonaktif → galat, tidak ada yang tersimpan', function () {
    $e = $this->s->org->employee();
    $inactive = ScheduleWorld::shift(['code' => 'MATI', 'is_active' => false]);
    $gone = $this->s->org->employee(attributes: ['status' => 'inactive', 'exit_date' => '2026-06-01']);

    $this->actingAs($this->hrd)->post('/jadwal/pola', patternPayload($this->s, [$e], '2026-12-01', ['days' => ['mon' => $inactive->id]]))
        ->assertSessionHasErrors(['days' => 'Shift tidak ditemukan atau sudah nonaktif.']);
    $this->actingAs($this->hrd)->post('/jadwal/pola', patternPayload($this->s, [$e], '2026-13-01'))->assertSessionHasErrors('effective_from');
    $this->actingAs($this->hrd)->post('/jadwal/pola', patternPayload($this->s, [], '2026-12-01'))->assertSessionHasErrors(['employee_ids' => 'Pilih minimal satu pegawai.']);
    $this->actingAs($this->hrd)->post('/jadwal/pola', patternPayload($this->s, [$e, $gone], '2026-12-01'))->assertSessionHasErrors('employee_ids');

    expect(ShiftPattern::query()->count())->toBe(0);
});

test('SEC-033 pola massal ber-scope: Kepala Lokasi hanya pegawai lokasinya; satu ID di luar scope membatalkan semua tanpa menyebut ID', function () {
    $kepala = $this->s->org->employee($this->s->org->gudang, attributes: ['full_name' => 'Kepala Gudang']);
    $staf = $this->s->org->employee($this->s->org->gudang, $kepala);
    $luar = $this->s->org->employee($this->s->org->toko);
    // Shift terlihat Kepala Lokasi = shift yang dipakai pola pegawai lokasinya (06 §5, ShiftScope).
    $this->s->weekdays($kepala, '2026-01-01');
    $this->s->pattern($staf, '2026-01-01', ['mon' => $this->s->siang]);
    $actor = $this->s->org->actor('kepala_lokasi', $kepala);

    $response = $this->actingAs($actor)->post('/jadwal/pola', patternPayload($this->s, [$staf, $luar], '2026-12-01'));
    $response->assertSessionHasErrors(['employee_ids' => 'Ada pegawai yang tidak ditemukan, sudah nonaktif, atau di luar wewenang Anda.']);
    expect(session('errors')->get('employee_ids')[0])->not->toContain((string) $luar->id)
        ->and(ShiftPattern::query()->where('effective_from', '2026-12-01')->count())->toBe(0);

    $this->actingAs($actor)->post('/jadwal/pola', patternPayload($this->s, [$staf], '2026-12-01'))->assertSessionHasNoErrors();
    expect(ShiftPattern::query()->where('employee_id', $staf->id)->where('effective_from', '2026-12-01')->exists())->toBeTrue();
});

test('SEC-033 shift di luar scope Kepala Lokasi ditolak walau aktif', function () {
    $kepala = $this->s->org->employee($this->s->org->gudang);
    $this->s->weekdays($kepala, '2026-01-01'); // hanya PAGI yang dipakai di gudang
    $actor = $this->s->org->actor('kepala_lokasi', $kepala);

    $this->actingAs($actor)->post('/jadwal/pola', patternPayload($this->s, [$kepala], '2026-12-01'))
        ->assertSessionHasErrors(['days' => 'Shift tidak ditemukan atau sudah nonaktif.']);
});

test('06 §4 supervisor hanya melihat jadwal (schedules.view 🔸), tidak bisa menetapkan pola', function () {
    $spv = $this->s->org->employee();
    $this->s->org->employee(supervisor: $spv);
    $actor = $this->s->org->actor('supervisor', $spv);

    $this->actingAs($actor)->get('/jadwal')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.manage', false));
    $this->actingAs($actor)->post('/jadwal/pola', patternPayload($this->s, [$spv], '2026-12-01'))->assertForbidden();
});

test('FR-032 "semua hasil filter": filteredIds hanya dihitung saat diminta dan mengikuti filter', function () {
    $gudang = $this->s->org->employee($this->s->org->gudang);
    $toko = $this->s->org->employee($this->s->org->toko);

    $this->actingAs($this->hrd)->get("/jadwal?filter[location]={$this->s->org->toko->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->missing('filteredIds')
            ->where('selectionLimit', 500)
            ->reloadOnly('filteredIds', fn (Assert $reload) => $reload->where('filteredIds', [$toko->id])));

    $this->actingAs($this->hrd)->get('/jadwal')
        ->assertInertia(fn (Assert $page) => $page->reloadOnly('filteredIds', fn (Assert $reload) => $reload->where('filteredIds', fn ($ids) => collect($ids)->sort()->values()->all() === collect([$gudang->id, $toko->id])->sort()->values()->all())));
});

test('07 §4 detail pegawai tab Jadwal: pola berlaku, terjadwal, riwayat, pengecualian mendatang, tombol ubah sesuai izin', function () {
    Carbon::setTestNow('2026-11-16 03:00:00');
    $e = $this->s->org->employee();
    $this->s->weekdays($e, '2026-01-01');
    $this->s->weekdays($e, '2026-06-01', $this->s->siang);
    $this->s->weekdays($e, '2026-12-01');
    $this->s->override($e, '2026-11-10', null, 'Sudah lewat');
    $this->s->override($e, '2026-11-20', $this->s->siang, 'Piket');

    $this->actingAs($this->hrd)->get("/pegawai/{$e->id}?tab=jadwal")
        ->assertInertia(fn (Assert $page) => $page
            ->where('schedule.patterns.0.effective_from', '2026-12-01')
            ->where('schedule.patterns.0.status', 'scheduled')
            ->where('schedule.patterns.1.status', 'current')
            ->where('schedule.patterns.1.days.mon.code', 'SIANG')
            ->where('schedule.patterns.2.status', 'past')
            ->has('schedule.overrides', 1)
            ->where('schedule.overrides.0.note', 'Piket')
            ->where('schedule.canManage', true));

    // Tab lain tidak memuat data jadwal; supervisor (schedules.manage ❌) tidak mendapat tombol ubah.
    $this->actingAs($this->hrd)->get("/pegawai/{$e->id}")->assertInertia(fn (Assert $page) => $page->where('schedule', null));
    $spv = $this->s->org->employee();
    $e->assignments()->update(['supervisor_employee_id' => $spv->id]);
    app()->forgetScopedInstances();
    $this->actingAs($this->s->org->actor('supervisor', $spv))->get("/pegawai/{$e->id}?tab=jadwal")
        ->assertInertia(fn (Assert $page) => $page->where('schedule.canManage', false));
    Carbon::setTestNow();
});

test('04 §4.2 DemoSeeder: pola Senin–Sabtu, Minggu libur, shift sesuai jabatan, idempoten', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $employees = count(DemoSeeder::EMPLOYEES);
    $patternOf = fn (string $name) => ShiftPattern::query()->where('employee_id', Employee::query()->where('full_name', $name)->value('id'))->sole();
    $code = fn (?int $id) => Shift::query()->whereKey($id)->value('code');

    $slamet = $patternOf('Slamet Riyadi'); // Staf Gudang
    expect(ShiftPattern::query()->count())->toBe($employees)
        ->and($code($slamet->mon_shift_id))->toBe('GDG')
        ->and($code($slamet->sat_shift_id))->toBe('GDG')
        ->and($slamet->sun_shift_id)->toBeNull()
        ->and($code($patternOf('Bayu Saputra')->wed_shift_id))->toBe('SLS')
        ->and($code($patternOf('Siti Aminah')->fri_shift_id))->toBe('PAGI');
});
