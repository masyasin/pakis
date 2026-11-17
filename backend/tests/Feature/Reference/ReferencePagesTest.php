<?php

use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Location;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Domain\Reference\ReferenceUsage;
use App\Models\User;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * FR-014 / BR-026: referensi dinamis (05 §4.3). Izin `references.*` deny-by-default sampai T-018.
 */

beforeEach(function () {
    $this->seed(ReferenceSeeder::class);
    $this->user = User::factory()->create();
});

function grantReferences(bool $manage = true): void
{
    Gate::define('references.view', fn () => true);
    Gate::define('references.manage', fn () => $manage);
}

function referenceItem(string $group, string $code): ReferenceItem
{
    return ReferenceItem::query()->where('group_code', $group)->where('code', $code)->sole();
}

function customItem(string $code = 'mess', string $group = 'location_type'): ReferenceItem
{
    return ReferenceItem::query()->create([
        'group_code' => $group, 'code' => $code, 'name' => 'Mes karyawan', 'sort_order' => 90, 'is_system' => false,
    ]);
}

// --- Izin -------------------------------------------------------------------------------------

test('tanpa izin referensi 403 (deny-by-default sampai T-018)', function () {
    $item = customItem();

    $this->actingAs($this->user)->get('/referensi')->assertForbidden();
    $this->actingAs($this->user)->post('/referensi', [])->assertForbidden();
    $this->actingAs($this->user)->put("/referensi/{$item->id}", [])->assertForbidden();
    $this->actingAs($this->user)->delete("/referensi/{$item->id}")->assertForbidden();
    $this->actingAs($this->user)->post('/referensi/urutan', [])->assertForbidden();
});

test('pemegang references.view tanpa references.manage hanya bisa melihat', function () {
    grantReferences(manage: false);
    $item = customItem();

    $this->actingAs($this->user)->get('/referensi')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    $this->actingAs($this->user)->post('/referensi', ['group' => 'location_type', 'code' => 'abc', 'name' => 'Abc'])->assertForbidden();
    $this->actingAs($this->user)->delete("/referensi/{$item->id}")->assertForbidden();
});

// --- Daftar -----------------------------------------------------------------------------------

test('daftar default grup pertama, urut sort_order, item sistem ditandai', function () {
    grantReferences();

    $this->actingAs($this->user)->get('/referensi')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('references/index')
            ->where('group', 'location_type')
            // 4 grup Fase 0 + unit & formulation (T-069) + customer_type (T-073) + receipt_reject_reason (T-080)
            // + purchase_return_reason (T-081) + opname_variance_reason (T-083) + quarantine_reason & disposal_reason (T-084).
            ->has('groups', 12)
            ->has('rows', 4)
            ->where('rows.0.code', 'office')
            ->where('rows.0.name', 'Kantor')
            ->where('rows.0.is_system', true)
            ->where('rows.3.code', 'other')
            ->where('listing.state.filters.group', 'location_type'));
});

test('filter grup wajib: kosong atau tidak dikenal tetap grup pertama', function (string $query) {
    grantReferences();

    $this->actingAs($this->user)->get('/referensi?'.$query)
        ->assertInertia(fn (Assert $page) => $page->where('group', 'location_type')->has('rows', 4));
})->with(['filter[group]=', 'filter[group]=rahasia']);

test('cari kode/nama, filter aktif, dan sort nama dalam satu grup', function () {
    grantReferences();
    referenceItem('employment_status', 'daily')->update(['is_active' => false]);

    $base = '/referensi?filter[group]=employment_status';

    $this->actingAs($this->user)->get($base.'&q=kontr')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'contract'));

    $this->actingAs($this->user)->get($base.'&filter[is_active]=0')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'daily'));

    $this->actingAs($this->user)->get($base.'&sort=name')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.name', 'Harian Lepas')->where('listing.state.sort', 'name'));
});

// --- Tambah -----------------------------------------------------------------------------------

test('BR-026 tambah item: kode huruf kecil/angka/garis bawah, item baru bukan sistem, urutan paling akhir, diaudit', function () {
    grantReferences();

    $this->actingAs($this->user)
        ->post('/referensi', ['group' => 'location_type', 'code' => 'mess_2', 'name' => 'Mes karyawan', 'description' => 'Tempat tinggal', 'is_active' => true])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $item = referenceItem('location_type', 'mess_2');
    expect($item->is_system)->toBeFalse()
        ->and($item->sort_order)->toBeGreaterThan(referenceItem('location_type', 'other')->sort_order)
        ->and($item->created_by)->toBe($this->user->id)
        ->and(AuditLog::query()->where('event', 'reference.created')->where('auditable_id', $item->id)->exists())->toBeTrue();
});

test('BR-026 kode tidak sah ditolak', function (string $code) {
    grantReferences();

    $this->actingAs($this->user)
        ->post('/referensi', ['group' => 'location_type', 'code' => $code, 'name' => 'Uji'])
        ->assertSessionHasErrors('code');
})->with(['Mess', 'mes karyawan', 'm', 'mes-karyawan', str_repeat('a', 51)]);

test('BR-026 kode unik per grup, kode sama di grup lain boleh', function () {
    grantReferences();

    $this->actingAs($this->user)
        ->post('/referensi', ['group' => 'location_type', 'code' => 'office', 'name' => 'Kantor 2'])
        ->assertSessionHasErrors('code');

    // `other` sudah ada di location_type, tetapi grup employment_status belum punya.
    $this->actingAs($this->user)
        ->post('/referensi', ['group' => 'employment_status', 'code' => 'other', 'name' => 'Lainnya'])
        ->assertSessionHasNoErrors();
});

test('grup di luar daftar tetap tidak bisa dibuat dari layar', function () {
    grantReferences();

    $this->actingAs($this->user)
        ->post('/referensi', ['group' => 'grup_baru', 'code' => 'abc', 'name' => 'Abc'])
        ->assertSessionHasErrors('group');
});

// --- Ubah -------------------------------------------------------------------------------------

test('BR-026 item sistem: kode terkunci, nama bisa diubah, bisa dinonaktifkan', function () {
    grantReferences();
    $office = referenceItem('location_type', 'office');

    $this->actingAs($this->user)
        ->put("/referensi/{$office->id}", ['code' => 'kantor', 'name' => 'Kantor', 'is_active' => true])
        ->assertSessionHasErrors('code');
    expect($office->refresh()->code)->toBe('office');

    $this->actingAs($this->user)
        ->put("/referensi/{$office->id}", ['code' => 'office', 'name' => 'Kantor Cabang', 'is_active' => false])
        ->assertSessionHasNoErrors();

    $office->refresh();
    expect($office->name)->toBe('Kantor Cabang')
        ->and($office->is_active)->toBeFalse();

    $log = AuditLog::query()->where('event', 'reference.updated')->sole();
    expect($log->old_values)->toBe(['name' => 'Kantor', 'is_active' => true])
        ->and($log->new_values)->toBe(['name' => 'Kantor Cabang', 'is_active' => false]);
});

test('item bukan sistem boleh ganti kode, tetap unik per grup', function () {
    grantReferences();
    $item = customItem();

    $this->actingAs($this->user)
        ->put("/referensi/{$item->id}", ['code' => 'warehouse', 'name' => 'Mes', 'is_active' => true])
        ->assertSessionHasErrors('code');

    $this->actingAs($this->user)
        ->put("/referensi/{$item->id}", ['code' => 'mes_baru', 'name' => 'Mes', 'is_active' => true])
        ->assertSessionHasNoErrors();
    expect($item->refresh()->code)->toBe('mes_baru');
});

// --- Hapus ------------------------------------------------------------------------------------

test('BR-026 item sistem tidak bisa dihapus', function () {
    grantReferences();
    $office = referenceItem('location_type', 'office');

    $this->actingAs($this->user)->delete("/referensi/{$office->id}")->assertSessionHasErrors('item');

    expect(ReferenceItem::query()->whereKey($office->id)->exists())->toBeTrue();
});

test('BR-026 item yang sudah dipakai data lain tidak bisa dihapus, hanya dinonaktifkan', function () {
    grantReferences();
    $item = customItem();

    // Tabel pemakai asli (locations) baru ada di T-021: pakai tabel dummy SEMENTARA, karena
    // CREATE TEMPORARY TABLE tidak memutus transaksi RefreshDatabase.
    Schema::create('uji_pemakai_referensi', function (Blueprint $table) {
        $table->temporary();
        $table->id();
        $table->unsignedBigInteger('location_type_id');
    });
    app()->instance(ReferenceUsage::class, new ReferenceUsage([
        ReferenceGroup::LocationType->value => [['uji_pemakai_referensi', 'location_type_id']],
    ]));
    DB::table('uji_pemakai_referensi')->insert(['location_type_id' => $item->id]);

    $this->actingAs($this->user)->delete("/referensi/{$item->id}")->assertSessionHasErrors('item');
    expect(ReferenceItem::query()->whereKey($item->id)->exists())->toBeTrue();

    DB::table('uji_pemakai_referensi')->delete();
    $this->actingAs($this->user)->delete("/referensi/{$item->id}")->assertSessionHasNoErrors();
    expect(ReferenceItem::query()->whereKey($item->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'reference.deleted')->where('auditable_id', $item->id)->exists())->toBeTrue();
});

test('BR-026 alasan koreksi yang dipakai koreksi presensi (T-040) terhitung "sudah dipakai"', function () {
    expect(Schema::hasTable('attendance_corrections'))->toBeTrue();
    $item = customItem('banjir', 'correction_reason');
    expect(app(ReferenceUsage::class)->isUsed($item))->toBeFalse();

    $employee = Employee::factory()->create();
    AttendanceCorrection::query()->create([
        'number' => 'KOR-202611-9999', 'employee_id' => $employee->id, 'work_date' => '2026-11-16', 'type' => 'check_in',
        'proposed_check_in' => '08:00', 'reason_id' => $item->id, 'note' => 'Jalan banjir', 'submitted_by' => User::factory()->create()->id,
    ]);

    expect(app(ReferenceUsage::class)->isUsed($item))->toBeTrue();
});

test('BR-026 status kerja yang dipakai pegawai (T-024) terhitung "sudah dipakai"', function () {
    $item = customItem('borongan', 'employment_status');
    expect(app(ReferenceUsage::class)->isUsed($item))->toBeFalse();

    Employee::factory()->create(['employment_status_id' => $item->id]);

    expect(app(ReferenceUsage::class)->isUsed($item))->toBeTrue();
});

test('BR-026 tipe lokasi yang dipakai lokasi (T-021) terhitung "sudah dipakai"', function () {
    $item = customItem();
    expect(app(ReferenceUsage::class)->isUsed($item))->toBeFalse();

    Location::query()->create(['code' => 'MES-01', 'name' => 'Mes Karyawan', 'location_type_id' => $item->id]);

    expect(app(ReferenceUsage::class)->isUsed($item))->toBeTrue();
});

// --- Urutan -----------------------------------------------------------------------------------

test('atur urutan: semua item grup → sort_order baru berurutan, diaudit', function () {
    grantReferences();
    $ids = ReferenceItem::query()->where('group_code', 'location_type')->orderByDesc('sort_order')->pluck('id')->all();

    $this->actingAs($this->user)
        ->post('/referensi/urutan', ['group' => 'location_type', 'ids' => $ids])
        ->assertSessionHasNoErrors();

    expect(ReferenceItem::query()->where('group_code', 'location_type')->orderBy('sort_order')->pluck('code')->all())
        ->toBe(['other', 'store', 'warehouse', 'office'])
        ->and(AuditLog::query()->where('event', 'reference.reordered')->exists())->toBeTrue();
});

test('atur urutan ditolak bila tidak lengkap atau memuat item grup lain', function (Closure $ids) {
    grantReferences();
    $before = ReferenceItem::query()->orderBy('id')->pluck('sort_order', 'id')->all();

    $this->actingAs($this->user)
        ->post('/referensi/urutan', ['group' => 'location_type', 'ids' => $ids()])
        ->assertSessionHasErrors('ids');

    expect(ReferenceItem::query()->orderBy('id')->pluck('sort_order', 'id')->all())->toBe($before);
})->with([
    'tidak lengkap' => fn () => ReferenceItem::query()->where('group_code', 'location_type')->limit(3)->pluck('id')->all(),
    'campur grup' => fn () => [
        ...ReferenceItem::query()->where('group_code', 'location_type')->pluck('id')->all(),
        referenceItem('employment_status', 'daily')->id,
    ],
    'duplikat' => function () {
        $ids = ReferenceItem::query()->where('group_code', 'location_type')->pluck('id')->all();
        $ids[1] = $ids[0];

        return $ids;
    },
]);
