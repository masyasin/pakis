<?php

use App\Domain\Access\DataScope;
use App\Domain\Access\Support\DatabaseOrgStructure;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\EmployeeWorld;
use Tests\Support\FakeOrgStructure;

/*
 * DatabaseOrgStructure dengan tabel nyata (06 §5, BR-051, SEC-033): kasus tepi yang belum
 * tercakup test daftar pegawai/penugasan (T-024/T-025) — penugasan berakhir/belum mulai, batas
 * tanggal lokal, lokasi terhapus, batas 5 tingkat tim, atasan melingkar — plus kesetaraan
 * perilaku dengan FakeOrgStructure yang dipakai test lain.
 */

beforeEach(function () {
    $this->world = EmployeeWorld::make($this);
    $this->useRealPermissions();
    $this->org = new DatabaseOrgStructure;
});

/** Penugasan langsung (melewati aturan aplikasi) untuk menyiapkan keadaan tepi. */
function orgAssignment(Employee $employee, int $locationId, array $attributes = []): Assignment
{
    return Assignment::query()->create([
        'employee_id' => $employee->id,
        'position_id' => test()->world->staff->id,
        'location_id' => $locationId,
        'is_primary' => true,
        'start_date' => '2026-01-05',
        ...$attributes,
    ]);
}

/** @param list<int> $ids */
function sortedIds(array $ids): array
{
    sort($ids);

    return $ids;
}

test('06 §5 penugasan yang sudah berakhir atau belum mulai tidak memberi/menerima scope lokasi', function () {
    $this->travelTo(Carbon::parse('2026-11-15 03:00:00', 'UTC'));
    $gudang = $this->world->gudang->id;

    $active = Employee::factory()->create();
    orgAssignment($active, $gudang);
    $ended = Employee::factory()->create();
    orgAssignment($ended, $gudang, ['end_date' => '2026-11-14']);
    $future = Employee::factory()->create();
    orgAssignment($future, $gudang, ['start_date' => '2026-11-16']);
    $endsToday = Employee::factory()->create();
    orgAssignment($endsToday, $gudang, ['end_date' => '2026-11-15']);

    expect(sortedIds($this->org->employeesAtLocations([$gudang])))->toBe(sortedIds([$active->id, $endsToday->id]))
        ->and($this->org->primaryLocationOf($ended->id))->toBeNull()
        ->and($this->org->primaryLocationOf($future->id))->toBeNull()
        ->and($this->org->primaryLocationOf($endsToday->id))->toBe($gudang)
        ->and($this->org->assignedLocationsOf($ended->id))->toBe([]);
});

test('06 §5 "hari ini" memakai tanggal lokal kantor, bukan tanggal UTC', function () {
    setSetting('general.timezone', 'Asia/Jakarta');
    // 15 Nov 17.30 UTC = 16 Nov 00.30 WIB.
    $this->travelTo(Carbon::parse('2026-11-15 17:30:00', 'UTC'));
    $gudang = $this->world->gudang->id;

    $startsLocalToday = Employee::factory()->create();
    orgAssignment($startsLocalToday, $gudang, ['start_date' => '2026-11-16']);
    $endedLocalYesterday = Employee::factory()->create();
    orgAssignment($endedLocalYesterday, $gudang, ['end_date' => '2026-11-15']);

    expect($this->org->employeesAtLocations([$gudang]))->toBe([$startsLocalToday->id]);
});

test('BR-051 penugasan tambahan aktif ikut lokasi pegawai; yang berakhir tidak', function () {
    $this->travelTo(Carbon::parse('2026-11-15 03:00:00', 'UTC'));
    $employee = Employee::factory()->create();
    orgAssignment($employee, $this->world->gudang->id);
    orgAssignment($employee, $this->world->toko->id, ['is_primary' => false]);
    $third = $this->world->toko->replicate()->fill(['code' => 'TK-02', 'name' => 'Toko Lama']);
    $third->save();
    orgAssignment($employee, $third->id, ['is_primary' => false, 'end_date' => '2026-11-01']);

    expect($this->org->primaryLocationOf($employee->id))->toBe($this->world->gudang->id)
        ->and(sortedIds($this->org->assignedLocationsOf($employee->id)))
        ->toBe(sortedIds([$this->world->gudang->id, $this->world->toko->id]));
});

test('06 §5 lokasi yang dikepalai tetapi sudah dihapus tidak memberi scope', function () {
    $head = Employee::factory()->create();
    $this->world->gudang->update(['head_employee_id' => $head->id]);
    $this->world->toko->update(['head_employee_id' => $head->id]);
    $this->world->toko->delete();

    expect($this->org->locationsHeadedBy($head->id))->toBe([$this->world->gudang->id]);
});

test('06 §5 scope tim berhenti di 5 tingkat dengan data nyata', function () {
    $chain = [$this->world->employee()];
    foreach (range(1, 6) as $_) {
        $chain[] = $this->world->employee(supervisor: end($chain));
    }
    $actor = $this->world->actor('supervisor', $chain[0]);

    $visible = app(DataScope::class)->visibleEmployeeIds($actor);

    // Diri + 5 tingkat bawahan; tingkat ke-6 tidak terlihat.
    expect(sortedIds($visible))->toBe(sortedIds(array_map(fn (Employee $e) => $e->id, array_slice($chain, 0, 6))))
        ->and($visible)->not->toContain($chain[6]->id);
});

test('06 §5 atasan melingkar (data rusak di DB) tidak membuat scope tim berputar tanpa akhir', function () {
    $a = $this->world->employee();
    $b = $this->world->employee(supervisor: $a);
    // Aplikasi menolak siklus (BR-051); di sini dipaksa langsung ke DB untuk menguji pengamannya.
    DB::table('assignments')->where('employee_id', $a->id)->update(['supervisor_employee_id' => $b->id]);
    $actor = $this->world->actor('supervisor', $a);

    expect(sortedIds(app(DataScope::class)->visibleEmployeeIds($actor)))->toBe(sortedIds([$a->id, $b->id]));
});

test('T-019 FakeOrgStructure berperilaku sama dengan DatabaseOrgStructure untuk data yang sama', function () {
    $this->travelTo(Carbon::parse('2026-11-15 03:00:00', 'UTC'));
    $gudang = $this->world->gudang->id;
    $toko = $this->world->toko->id;

    // Data nyata: kepala (memimpin toko, rangkap di toko), staf gudang (bawahan kepala), staf toko,
    // dan pegawai yang penugasannya sudah berakhir (tidak dimodelkan di fake = tidak ada).
    $kepala = Employee::factory()->create();
    orgAssignment($kepala, $gudang);
    orgAssignment($kepala, $toko, ['is_primary' => false]);
    $this->world->toko->update(['head_employee_id' => $kepala->id]);
    $stafGudang = Employee::factory()->create();
    orgAssignment($stafGudang, $gudang, ['supervisor_employee_id' => $kepala->id]);
    $stafToko = Employee::factory()->create();
    orgAssignment($stafToko, $toko);
    $mantan = Employee::factory()->create();
    orgAssignment($mantan, $toko, ['end_date' => '2026-10-31', 'supervisor_employee_id' => $kepala->id]);

    $fake = new FakeOrgStructure;
    $fake->employee($kepala->id)->at($gudang)->alsoAt($toko)->heads($toko);
    $fake->employee($stafGudang->id)->at($gudang)->reportsTo($kepala->id);
    $fake->employee($stafToko->id)->at($toko);

    $real = $this->org;
    foreach ([$kepala, $stafGudang, $stafToko, $mantan] as $e) {
        expect($fake->primaryLocationOf($e->id))->toBe($real->primaryLocationOf($e->id), "primaryLocationOf #{$e->id}")
            ->and(sortedIds($fake->assignedLocationsOf($e->id)))->toBe(sortedIds($real->assignedLocationsOf($e->id)))
            ->and(sortedIds($fake->locationsHeadedBy($e->id)))->toBe(sortedIds($real->locationsHeadedBy($e->id)));
    }
    foreach ([[$gudang], [$toko], [$gudang, $toko]] as $locations) {
        expect(sortedIds($fake->employeesAtLocations($locations)))->toBe(sortedIds($real->employeesAtLocations($locations)));
    }
    expect(sortedIds($fake->directReportsOf([$kepala->id])))->toBe(sortedIds($real->directReportsOf([$kepala->id])));
});
