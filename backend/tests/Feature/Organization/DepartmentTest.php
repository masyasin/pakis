<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Support\DepartmentUsage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOrgStructure;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->useRealPermissions();
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
});

function dept(string $code, ?Department $parent = null, bool $active = true): Department
{
    return Department::query()->create(['code' => $code, 'name' => 'Dept '.$code, 'parent_id' => $parent?->id, 'is_active' => $active]);
}

test('FR-023 tambah departemen: kode dirapikan, tersimpan, dan tercatat di audit', function () {
    $ops = dept('OPS');

    $this->actingAs($this->admin)
        ->post('/departemen', ['code' => ' gudang ', 'name' => 'Gudang', 'parent_id' => $ops->id, 'is_active' => true])
        ->assertRedirect('/departemen')
        ->assertSessionHasNoErrors();

    $gudang = Department::query()->where('code', 'GUDANG')->sole();
    expect($gudang->parent_id)->toBe($ops->id)
        ->and(AuditLog::query()->where('event', 'department.created')->where('auditable_id', $gudang->id)->exists())->toBeTrue();
});

test('FR-023 induk tidak boleh membentuk siklus (AA → BB → CC, lalu AA di bawah CC ditolak)', function () {
    $a = dept('AA');
    $b = dept('BB', $a);
    $c = dept('CC', $b);

    $this->actingAs($this->admin)
        ->put("/departemen/{$a->id}", ['code' => 'AA', 'name' => 'Dept AA', 'parent_id' => $c->id])
        ->assertSessionHasErrors(['parent_id' => 'Induk ini berada di bawah departemen yang sedang diubah, sehingga membentuk lingkaran. Pilih induk lain.']);

    expect($a->fresh()->parent_id)->toBeNull();
});

test('FR-023 departemen tidak bisa menjadi induk dirinya sendiri', function () {
    $a = dept('AA');

    $this->actingAs($this->admin)
        ->put("/departemen/{$a->id}", ['code' => 'AA', 'name' => 'Dept AA', 'parent_id' => $a->id])
        ->assertSessionHasErrors('parent_id');
});

test('FR-023 memindah ke induk lain yang sah tetap boleh dan diaudit', function () {
    $a = dept('AA');
    $b = dept('BB', $a);
    $x = dept('XX');

    $this->actingAs($this->admin)
        ->put("/departemen/{$b->id}", ['code' => 'BB', 'name' => 'Dept BB', 'parent_id' => $x->id])
        ->assertSessionHasNoErrors();

    $audit = AuditLog::query()->where('event', 'department.updated')->sole();
    expect($b->fresh()->parent_id)->toBe($x->id)
        ->and($audit->old_values['parent_id'])->toBe($a->id)
        ->and($audit->new_values['parent_id'])->toBe($x->id);
});

test('FR-023 induk nonaktif tidak bisa dipilih, tetapi induk terpasang boleh dipertahankan', function () {
    $old = dept('LAMA');
    $child = dept('ANAK', $old);
    $old->update(['is_active' => false]);

    $this->actingAs($this->admin)
        ->post('/departemen', ['code' => 'BARU', 'name' => 'Baru', 'parent_id' => $old->id])
        ->assertSessionHasErrors('parent_id');

    $this->actingAs($this->admin)
        ->put("/departemen/{$child->id}", ['code' => 'ANAK', 'name' => 'Anak (ganti nama)', 'parent_id' => $old->id])
        ->assertSessionHasNoErrors();
});

test('FR-023 kode unik termasuk departemen terhapus', function () {
    dept('OPS')->delete();

    $this->actingAs($this->admin)->post('/departemen', ['code' => 'OPS', 'name' => 'Operasional'])->assertSessionHasErrors('code');
});

test('FR-023 departemen tanpa pemakai bisa dihapus (soft delete + audit)', function () {
    $a = dept('AA');

    $this->actingAs($this->admin)->delete("/departemen/{$a->id}")->assertRedirect('/departemen');

    expect(Department::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'department.deleted')->exists())->toBeTrue();
});

test('FR-023 departemen yang punya sub-departemen tidak bisa dihapus', function () {
    $a = dept('AA');
    dept('BB', $a);

    $this->actingAs($this->admin)->from('/departemen')->delete("/departemen/{$a->id}")
        ->assertSessionHasErrors('department');

    expect($a->fresh())->not->toBeNull();
});

test('FR-023 departemen yang dipakai jabatan tidak bisa dihapus', function () {
    $a = dept('AA');
    // DDL biasa memutus transaksi RefreshDatabase; TEMPORARY TABLE tidak (pola T-010).
    DB::statement('CREATE TEMPORARY TABLE tmp_positions (id INT PRIMARY KEY, department_id BIGINT UNSIGNED)');
    DB::table('tmp_positions')->insert(['id' => 1, 'department_id' => $a->id]);
    app()->instance(DepartmentUsage::class, new DepartmentUsage([['tmp_positions', 'department_id']]));

    $this->actingAs($this->admin)->from('/departemen')->delete("/departemen/{$a->id}")
        ->assertSessionHasErrors('department');
});

test('FR-023 daftar: cari, filter induk & aktif, sort', function () {
    $ops = dept('OPS');
    dept('GUDANG', $ops);
    dept('TOKO', $ops, active: false);
    dept('KEU');

    $this->actingAs($this->admin)->get('/departemen')
        ->assertInertia(fn (Assert $page) => $page->component('departments/index')
            ->has('rows', 4)
            ->where('can.manage', true));

    $this->actingAs($this->admin)->get('/departemen?q=gud')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'GUDANG')->where('rows.0.parent', 'Dept OPS'));
    $this->actingAs($this->admin)->get("/departemen?filter[parent]={$ops->id}")
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2));
    $this->actingAs($this->admin)->get("/departemen?filter[parent]={$ops->id}&filter[active]=1")
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'GUDANG'));
    $this->actingAs($this->admin)->get('/departemen?sort=-code')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.code', 'TOKO'));
});

test('FR-023 ekspor departemen mengunduh berkas', function () {
    dept('OPS');

    $response = $this->actingAs($this->admin)->get('/departemen/ekspor');

    $response->assertOk();
    expect((string) $response->headers->get('content-disposition'))->toContain('.xlsx');
});

test('FR-023 lookup hanya departemen aktif dan bisa mengecualikan diri sendiri', function () {
    $a = dept('AKTIF');
    dept('MATI', active: false);

    $this->actingAs($this->admin)->getJson('/lookup/departments')->assertJsonCount(1)->assertJsonFragment(['label' => 'Dept AKTIF']);
    $this->actingAs($this->admin)->getJson("/lookup/departments?exclude={$a->id}")->assertJsonCount(0);
});

test('06 §4 kepala lokasi & supervisor tidak punya akses departemen', function (string $role) {
    FakeOrgStructure::bind();
    // MFA aktif: supervisor wajib MFA sejak T-067 (PA-12); yang diuji di sini izin departemen.
    $user = User::factory()->withTwoFactor()->create(['employee_id' => employeeRow(1)])->assignRole($role);

    $this->actingAs($user)->get('/departemen')->assertForbidden();
    $this->actingAs($user)->post('/departemen', ['code' => 'XX', 'name' => 'XX'])->assertForbidden();
})->with(['kepala_lokasi', 'supervisor']);
