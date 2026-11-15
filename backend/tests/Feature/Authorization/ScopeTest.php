<?php

use App\Domain\Access\DataScope;
use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Support\ScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Tests\Support\FakeOrgStructure;

/*
 * FR-018, BR-032 (06 §5): scope data efektif = scope terluas dari role pengguna.
 *
 * Struktur uji (lokasi 10 = Gudang, 20 = Toko):
 *   1 (kepala gudang, lokasi 10, juga mengepalai lokasi 20)
 *   └─ 2 (supervisor, lokasi 10)
 *      └─ 3 (sales, lokasi 10)
 *         └─ 4 (sales, lokasi 20)
 *   5 (pegawai lain, lokasi 20, tanpa atasan)
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->org = FakeOrgStructure::bind();
    $this->org->employee(1)->at(10)->heads(20);
    $this->org->employee(2)->at(10)->reportsTo(1);
    $this->org->employee(3)->at(10)->reportsTo(2);
    $this->org->employee(4)->at(20)->reportsTo(3);
    $this->org->employee(5)->at(20);
});

function scopedUser(?int $employeeId, string ...$roles): User
{
    return User::factory()->create(['employee_id' => $employeeId === null ? null : employeeRow($employeeId)])->assignRole($roles);
}

function visible(User $user): ?array
{
    $ids = app(DataScope::class)->visibleEmployeeIds($user);

    return $ids === null ? null : collect($ids)->sort()->values()->all();
}

test('BR-032 scope team: diri + bawahan langsung & tidak langsung (3 tingkat)', function () {
    expect(visible(scopedUser(2, 'supervisor')))->toBe([2, 3, 4])
        ->and(visible(scopedUser(3, 'supervisor')))->toBe([3, 4])
        ->and(visible(scopedUser(4, 'supervisor')))->toBe([4]);
});

test('BR-032 scope team: maksimal 5 tingkat ke bawah', function () {
    // Rantai 100 → 101 → … → 107 (7 tingkat di bawah 100).
    foreach (range(101, 107) as $id) {
        $this->org->employee($id)->at(10)->reportsTo($id - 1);
    }

    expect(visible(scopedUser(100, 'supervisor')))->toBe([100, 101, 102, 103, 104, 105]);
});

test('BR-032 scope team: struktur atasan melingkar tidak membuat loop', function () {
    $this->org->employee(1)->reportsTo(4); // 1 → 2 → 3 → 4 → 1

    expect(visible(scopedUser(1, 'supervisor')))->toBe([1, 2, 3, 4]);
});

test('BR-032 scope location: pegawai di lokasi tugas + lokasi yang dikepalai', function () {
    expect(visible(scopedUser(1, 'kepala_lokasi')))->toBe([1, 2, 3, 4, 5])  // lokasi 10 + dikepalai 20
        ->and(visible(scopedUser(2, 'kepala_lokasi')))->toBe([1, 2, 3]);    // hanya lokasi 10
});

test('BR-032 scope self & all; super_admin selalu semua', function () {
    expect(visible(scopedUser(4, 'pegawai')))->toBe([4])
        ->and(visible(scopedUser(5, 'hrd')))->toBeNull()
        ->and(visible(scopedUser(null, 'super_admin')))->toBeNull();
});

test('BR-032 multi-role: scope terluas yang berlaku (location > team)', function () {
    $user = scopedUser(2, 'supervisor', 'kepala_lokasi');

    expect(ScopeResolver::for($user))->toBe(Scope::Location)
        ->and(visible($user))->toBe([1, 2, 3]);
});

test('06 §5 pengguna tanpa pegawai dengan scope selain all tidak melihat data apa pun', function () {
    expect(visible(scopedUser(null, 'kepala_lokasi')))->toBe([])
        ->and(visible(scopedUser(null, 'supervisor')))->toBe([]);
});

test('pengguna tanpa role sama sekali: scope self', function () {
    expect(ScopeResolver::for(User::factory()->create()))->toBe(Scope::Self);
});

test('DataScope::apply membatasi query; kosong → tidak ada baris; all → tanpa batas', function () {
    $a = User::factory()->create(['employee_id' => employeeRow(3)]);
    $b = User::factory()->create(['employee_id' => employeeRow(5)]);

    $ids = fn (User $viewer) => app(DataScope::class)
        ->apply(User::query()->whereIn('id', [$a->id, $b->id]), $viewer, 'employee_id')
        ->pluck('id')->sort()->values()->all();

    expect($ids(scopedUser(2, 'supervisor')))->toBe([$a->id])
        ->and($ids(scopedUser(null, 'supervisor')))->toBe([])
        ->and($ids(scopedUser(1, 'hrd')))->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

test('DataScope::apply menolak nama kolom yang bukan identifier (bukan input mentah)', function () {
    expect(fn () => app(DataScope::class)->apply(User::query(), scopedUser(2, 'supervisor'), 'employee_id; DROP TABLE users'))
        ->toThrow(InvalidArgumentException::class);
});

test('DataScope::allows untuk Policy: di luar scope → false', function () {
    $supervisor = scopedUser(2, 'supervisor');

    expect(app(DataScope::class)->allows($supervisor, 4))->toBeTrue()
        ->and(app(DataScope::class)->allows($supervisor, 5))->toBeFalse()
        ->and(app(DataScope::class)->allows($supervisor, null))->toBeFalse()
        ->and(app(DataScope::class)->allows(scopedUser(null, 'hrd'), 5))->toBeTrue();
});

test('ScopeResolver dipakai juga untuk batas delegasi role (SaveRole)', function () {
    $role = Role::findByName('kepala_lokasi', 'web');

    expect(ScopeResolver::for(scopedUser(1, 'kepala_lokasi')))->toBe($role->data_scope);
});
