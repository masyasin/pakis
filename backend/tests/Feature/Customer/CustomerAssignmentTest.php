<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customer\Actions\ReassignCustomers;
use App\Domain\Customer\Events\CustomerReassigned;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\AssertsIdor;
use Tests\Support\CustomerWorld;
use Tests\Support\FakeOrgStructure;

/*
 * T-073 — penugasan kios (BR-167, FR-A17, 15 §11.1). "Hari ini" = Senin 16 Nov 2026 10:00 WIB.
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Jakarta'));
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, SequenceSeeder::class]);
    $this->useRealPermissions();
    FakeOrgStructure::bind()->employee(7)->employee(8);

    $this->apj = User::factory()->withTwoFactor()->create()->assignRole('admin_penjualan');
    $this->kudus = CustomerWorld::area('AR-KDS');
    $this->pati = CustomerWorld::area('AR-PTI');
    $this->salesA = employeeRow(7);
    $this->salesB = employeeRow(8);
});

/** @return array<string, mixed> */
function reassignPayload(array $ids, array $override = []): array
{
    return [
        'customer_ids' => $ids,
        'sales_area_id' => test()->pati->id,
        'sales_employee_id' => test()->salesB,
        'effective_date' => '2026-11-16',
        'reason' => 'Rotasi wilayah',
        ...$override,
    ];
}

/** @return list<array{0: int, 1: int|null, 2: string, 3: string|null}> [area, sales, mulai, selesai] urut mulai */
function assignmentRows(Customer $customer): array
{
    return CustomerAssignment::query()->where('customer_id', $customer->id)->orderBy('start_date')->orderBy('id')->get()
        ->map(fn (CustomerAssignment $a) => [$a->sales_area_id, $a->sales_employee_id, $a->start_date->toDateString(), $a->end_date?->toDateString()])->all();
}

test('BR-167 unik DB: dua penugasan terbuka untuk satu kios ditolak index ca_one_active_unique', function () {
    $customer = CustomerWorld::customer($this->kudus, 'Toko Satu');

    expect(fn () => DB::table('customer_assignments')->insert([
        'customer_id' => $customer->id, 'sales_area_id' => $this->pati->id, 'start_date' => '2026-11-16',
    ]))->toThrow(QueryException::class);

    // Penugasan yang sudah ditutup bebas jumlahnya.
    DB::table('customer_assignments')->insert([
        'customer_id' => $customer->id, 'sales_area_id' => $this->pati->id, 'start_date' => '2026-01-01', 'end_date' => '2026-06-30',
    ]);
    expect(CustomerAssignment::query()->where('customer_id', $customer->id)->count())->toBe(2);
});

test('BR-167 pengalihan hari ini: tutup lama (H−1), buka baru (H), cache kios ikut, audit & event', function () {
    Event::fake([CustomerReassigned::class]);
    $a = CustomerWorld::customer($this->kudus, 'Toko A', ['assigned_sales_employee_id' => $this->salesA]);
    $b = CustomerWorld::customer($this->kudus, 'Toko B');
    CustomerAssignment::query()->update(['start_date' => '2026-10-01']);

    $this->actingAs($this->apj)->from('/pelanggan')->post('/pelanggan/alihkan', reassignPayload([$a->id, $b->id]))
        ->assertSessionHasNoErrors()->assertRedirect('/pelanggan');

    expect(assignmentRows($a))->toBe([
        [$this->kudus->id, $this->salesA, '2026-10-01', '2026-11-15'],
        [$this->pati->id, $this->salesB, '2026-11-16', null],
    ]);
    expect($a->fresh()->sales_area_id)->toBe($this->pati->id)
        ->and($a->fresh()->assigned_sales_employee_id)->toBe($this->salesB)
        ->and($b->fresh()->sales_area_id)->toBe($this->pati->id);

    $audit = AuditLog::query()->where('event', 'customer.reassigned')->where('auditable_id', $a->id)->sole();
    expect($audit->new_values)->toMatchArray(['sales_area_id' => $this->pati->id, 'sales_employee_id' => $this->salesB, 'effective_date' => '2026-11-16', 'reason' => 'Rotasi wilayah']);
    Event::assertDispatched(CustomerReassigned::class, fn (CustomerReassigned $e) => $e->customerIds === [$a->id, $b->id] && $e->effectiveDate === '2026-11-16');
});

test('BR-167 pengalihan atomik: satu kios gagal → tidak ada penugasan yang berubah', function () {
    $a = CustomerWorld::customer($this->kudus, 'Toko A');
    $b = CustomerWorld::customer($this->kudus, 'Toko B');
    CustomerAssignment::query()->update(['start_date' => '2026-10-01']);
    $before = [assignmentRows($a), assignmentRows($b)];

    // Kios B dibuat gagal di tengah transaksi (setelah kios A sudah dialihkan di transaksi yang sama).
    // Lewat event model, bukan trigger DB: DDL memicu commit implisit di MariaDB.
    CustomerAssignment::creating(function (CustomerAssignment $row) use ($b) {
        if ($row->customer_id === $b->id) {
            throw new RuntimeException('uji gagal');
        }
    });

    expect(fn () => app(ReassignCustomers::class)->handle([$a->id, $b->id], $this->pati->id, null, '2026-11-16', null, null))
        ->toThrow(RuntimeException::class, 'uji gagal');

    expect([assignmentRows($a), assignmentRows($b)])->toBe($before)
        ->and($a->fresh()->sales_area_id)->toBe($this->kudus->id);
});

test('BR-167 pengalihan bertanggal: tertunda sampai H, job customers:apply-assignments menerapkan tepat di hari H (idempoten)', function () {
    $customer = CustomerWorld::customer($this->kudus, 'Toko Nanti', ['assigned_sales_employee_id' => $this->salesA]);
    CustomerAssignment::query()->update(['start_date' => '2026-10-01']);

    $this->actingAs($this->apj)->post('/pelanggan/alihkan', reassignPayload([$customer->id], ['effective_date' => '2026-11-20']))
        ->assertSessionHasNoErrors();

    expect(assignmentRows($customer))->toBe([
        [$this->kudus->id, $this->salesA, '2026-10-01', '2026-11-19'],
        [$this->pati->id, $this->salesB, '2026-11-20', null],
    ])->and($customer->fresh()->sales_area_id)->toBe($this->kudus->id);

    // H−1 pukul 23.59 WIB: belum berlaku.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 23:59', 'Asia/Jakarta'));
    $this->artisan('customers:apply-assignments')->assertSuccessful();
    expect($customer->fresh()->sales_area_id)->toBe($this->kudus->id);

    // Hari H 00.05 WIB (= 19 Nov 17.05 UTC): berlaku.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 00:05', 'Asia/Jakarta'));
    $this->artisan('customers:apply-assignments')->expectsOutputToContain('diterapkan: 1')->assertSuccessful();
    expect($customer->fresh()->sales_area_id)->toBe($this->pati->id)
        ->and($customer->fresh()->assigned_sales_employee_id)->toBe($this->salesB);

    $this->artisan('customers:apply-assignments')->expectsOutputToContain('diterapkan: 0')->assertSuccessful();
});

test('BR-167 PA-47 pengalihan kedua sebelum H mengganti yang tertunda; kembali ke penugasan sekarang membatalkannya', function () {
    $customer = CustomerWorld::customer($this->kudus, 'Toko Ganti', ['assigned_sales_employee_id' => $this->salesA]);
    CustomerAssignment::query()->update(['start_date' => '2026-10-01']);
    $reassign = fn (array $o) => $this->actingAs($this->apj)->post('/pelanggan/alihkan', reassignPayload([$customer->id], $o))->assertSessionHasNoErrors();

    $reassign(['effective_date' => '2026-11-20']);
    $reassign(['effective_date' => '2026-11-25', 'sales_employee_id' => $this->salesA]);

    expect(assignmentRows($customer))->toBe([
        [$this->kudus->id, $this->salesA, '2026-10-01', '2026-11-24'],
        [$this->pati->id, $this->salesA, '2026-11-25', null],
    ]);

    // Kembali ke area & sales yang berlaku sekarang = tertunda dibatalkan, penugasan lama terbuka lagi.
    $reassign(['sales_area_id' => $this->kudus->id, 'sales_employee_id' => $this->salesA, 'effective_date' => '2026-11-25']);
    expect(assignmentRows($customer))->toBe([[$this->kudus->id, $this->salesA, '2026-10-01', null]])
        ->and(AuditLog::query()->where('event', 'customer.reassigned')->count())->toBe(3);
});

test('BR-167 dialihkan dua kali di hari yang sama → diperbarui di tempat, tanpa penugasan nol hari', function () {
    $customer = CustomerWorld::customer($this->kudus, 'Toko Hari Ini');

    app(ReassignCustomers::class)->handle([$customer->id], $this->pati->id, $this->salesA, '2026-11-16', null, null);
    app(ReassignCustomers::class)->handle([$customer->id], $this->pati->id, $this->salesB, '2026-11-16', null, null);

    expect(assignmentRows($customer))->toBe([[$this->pati->id, $this->salesB, '2026-11-16', null]])
        ->and($customer->fresh()->assigned_sales_employee_id)->toBe($this->salesB);
});

test('BR-167 validasi pengalihan: maks 500 kios, tanggal lampau ditolak, kios digabung ditolak', function () {
    $customer = CustomerWorld::customer($this->kudus, 'Toko V');
    $merged = CustomerWorld::customer($this->kudus, 'Toko Gabung');
    DB::table('customers')->where('id', $merged->id)->update(['status' => 'merged', 'merged_into_id' => $customer->id]);

    $this->actingAs($this->apj)->post('/pelanggan/alihkan', reassignPayload(range(1, 501)))->assertSessionHasErrors('customer_ids');
    $this->actingAs($this->apj)->post('/pelanggan/alihkan', reassignPayload([$customer->id], ['effective_date' => '2026-11-15']))->assertSessionHasErrors('effective_date');
    $this->actingAs($this->apj)->post('/pelanggan/alihkan', reassignPayload([$customer->id, $merged->id]))->assertSessionHasErrors('customer_ids');

    expect(assignmentRows($customer))->toHaveCount(1);
});

test('SEC-033 pengalihan berisi kios di luar scope → 404; supervisor hanya ke area & sales dalam scope-nya', function () {
    FakeOrgStructure::bind()->employee(1)->employee(2)->reportsTo(1)->employee(7)->employee(8);
    $mine = CustomerWorld::area('AR-SPV', ['supervisor_employee_id' => employeeRow(1)]);
    $sub = CustomerWorld::area('AR-SUB', ['parent_id' => $mine->id]);
    $own = CustomerWorld::customer($mine, 'Kios Saya');
    $foreign = CustomerWorld::customer($this->kudus, 'Kios Orang');
    employeeRow(2);
    $supervisor = User::factory()->withTwoFactor()->create(['employee_id' => 1])->assignRole('supervisor');

    $this->assertOutOfScope404($supervisor, 'POST', '/pelanggan/alihkan', reassignPayload([$own->id, $foreign->id], ['sales_area_id' => $sub->id, 'sales_employee_id' => 2]));
    $this->actingAs($supervisor)->post('/pelanggan/alihkan', reassignPayload([$own->id], ['sales_area_id' => $this->pati->id, 'sales_employee_id' => 2]))
        ->assertSessionHasErrors('sales_area_id');
    $this->actingAs($supervisor)->post('/pelanggan/alihkan', reassignPayload([$own->id], ['sales_area_id' => $sub->id, 'sales_employee_id' => $this->salesB]))
        ->assertSessionHasErrors('sales_employee_id');
    $this->actingAs($supervisor)->post('/pelanggan/alihkan', reassignPayload([$own->id], ['sales_area_id' => $sub->id, 'sales_employee_id' => 2]))
        ->assertSessionHasNoErrors();

    expect($own->fresh()->sales_area_id)->toBe($sub->id)->and($foreign->fresh()->sales_area_id)->toBe($this->kudus->id);
});

test('15 §6.4 pengalihan butuh customers.manage: Administrator → 403', function () {
    $customer = CustomerWorld::customer($this->kudus, 'Toko X');
    $admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');

    $this->actingAs($admin)->post('/pelanggan/alihkan', reassignPayload([$customer->id]))->assertForbidden();
});
