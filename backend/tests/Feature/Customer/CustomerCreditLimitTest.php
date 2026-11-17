<?php

use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Models\Role;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApproverResolver;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Models\ApprovalRule;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Api\ApiError;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\CustomerWorld;
use Tests\Support\EmployeeWorld;

/*
 * T-074 bagian 2 — limit kredit kios (BR-125, SEC-110) lewat approval `customer_credit_limit`
 * bertingkat (keputusan Mas 16 Nov 2026, PA-51): < Rp 20 jt `credit_limits.set`; ≥ Rp 20 jt atasan
 * langsung SALES kios lalu `credit_limits.set_high` (tidak buntu di Owner). Data fiktif.
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Jakarta'));
    Notification::fake();
    $this->org = EmployeeWorld::make($this);
    $this->seed(ApprovalRuleSeeder::class);
    $this->useRealPermissions();
    config(['inertia.testing.ensure_pages_exist' => false]);
    setSetting('approval.reauth_amount', '999999999999');

    $this->boss = $this->org->employee();
    $this->sales = $this->org->employee(supervisor: $this->boss);
    $this->bossUser = $this->org->actor('supervisor', $this->boss);
    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Ani Admin'])->assignRole('admin_penjualan');
    $this->owner = User::factory()->withTwoFactor()->create(['name' => 'Pak Owner'])->assignRole('owner');
    $this->area = CustomerWorld::area('AR-KDS', ['name' => 'Kudus']);
    $this->customer = CustomerWorld::activeCustomer($this->area, 'Toko Sumber Rejeki', ['assigned_sales_employee_id' => $this->sales->id]);
});

function requestLimit(User $user, Customer $customer, string $limit, string $reason = 'Omzet naik 3 bulan terakhir')
{
    return test()->actingAs($user)->post("/pelanggan/{$customer->id}/limit", ['credit_limit' => $limit, 'reason' => $reason]);
}

function decideLimit(User $actor, ApprovalRequest $request, bool $approve = true): ApprovalRequest
{
    return app(ApprovalEngine::class)->decide($actor, $request->id, $request->fresh()->current_step, $approve, $approve ? null : 'Belum layak', true);
}

test('BR-125 limit tanpa verifikasi ditolak Policy + Action (CUSTOMER_NOT_VERIFIED) dan CHECK DB', function () {
    $prospect = CustomerWorld::customer($this->area, 'Kios Prospek');

    expect($this->admin->can('setCreditLimit', $prospect))->toBeFalse()
        ->and($this->admin->can('setCreditLimit', $this->customer))->toBeTrue();
    requestLimit($this->admin, $prospect, '5000000')->assertSessionHasErrors([
        'credit_limit' => 'Kios ini belum terverifikasi. Limit kredit baru bisa diatur setelah verifikasi disetujui.',
    ]);
    $this->actingAs($this->admin)->postJson("/pelanggan/{$prospect->id}/limit", ['credit_limit' => '5000000', 'reason' => 'Uji JSON'])
        ->assertStatus(422)->assertJsonPath('error.code', 'CUSTOMER_NOT_VERIFIED');
    expect(ApprovalRequest::query()->count())->toBe(0);

    $this->actingAs($this->admin)->get("/pelanggan/{$prospect->id}")->assertInertia(fn (Assert $p) => $p
        ->where('can.credit_limit', false)
        ->where('changes.credit_limit.blocked_reason', 'not_verified'));

    expect(fn () => DB::table('customers')->where('id', $prospect->id)->update(['credit_limit' => 1000, 'credit_limit_active' => true]))
        ->toThrow(QueryException::class);
});

test('PA-51 limit < Rp 20 jt: satu langkah credit_limits.set → disetujui, limit aktif, audit lama/baru', function () {
    requestLimit($this->admin, $this->customer, '19999999.99')->assertSessionHasNoErrors()
        ->assertRedirect("/pelanggan/{$this->customer->id}?tab=limit");

    $request = ApprovalRequest::query()->with('steps')->sole();
    expect($request->document_type)->toBe('customer_credit_limit')
        ->and($request->document_id)->toBe($this->customer->id)
        ->and($request->amount)->toBe('19999999.99')
        ->and($request->steps->pluck('permission_name')->all())->toBe(['credit_limits.set'])
        ->and($this->customer->fresh()->credit_limit)->toBe('0.00');

    // Satu permintaan limit menunggu per kios.
    requestLimit($this->admin, $this->customer, '1000000')->assertSessionHasErrors('form');

    decideLimit($this->owner, $request);
    $customer = $this->customer->fresh();
    expect($customer->credit_limit)->toBe('19999999.99')
        ->and($customer->credit_limit_active)->toBeTrue();
    $audit = AuditLog::query()->where('event', 'customer.credit_limit_changed')->sole();
    expect($audit->old_values['credit_limit'])->toBe('0.00')
        ->and($audit->new_values['credit_limit'])->toBe('19999999.99')
        ->and($audit->auditable_type)->toBe(Customer::class);
});

test('PA-51 limit ≥ Rp 20 jt: atasan langsung sales lalu credit_limits.set_high; Owner calon langkah 2 (tidak buntu)', function () {
    requestLimit($this->admin, $this->customer, '20000000')->assertSessionHasNoErrors();
    $request = ApprovalRequest::query()->with('steps')->sole();
    expect($request->steps->pluck('approver_type')->map->value->all())->toBe(['direct_supervisor', 'permission'])
        ->and($request->steps[1]->permission_name)->toBe('credit_limits.set_high')
        ->and($request->scope_employee_id)->toBe($this->sales->id)
        ->and($request->steps[0]->resolved_employee_id)->toBe($this->boss->id)
        ->and($request->steps[0]->escalated)->toBeFalse();

    // Owner bukan atasan sales → tidak bisa memutus langkah 1.
    expect(fn () => decideLimit($this->owner, $request))->toThrow(ApiError::class);
    decideLimit($this->bossUser, $request);
    $request->refresh()->load('steps');

    $candidates = app(ApproverResolver::class)->directCandidates($request, $request->currentStep());
    expect($request->current_step)->toBe(2)
        ->and($candidates->pluck('id')->all())->toBe([$this->owner->id])
        ->and($request->currentStep()->escalated)->toBeFalse();
    expect(fn () => decideLimit($this->bossUser, $request))->toThrow(ApiError::class);

    decideLimit($this->owner, $request);
    expect($request->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($this->customer->fresh()->credit_limit)->toBe('20000000.00');
});

test('BR-125 limit 0 = nonaktif; kios tahan kredit boleh, nonaktif ditolak; limit sama ditolak', function () {
    $this->customer->forceFill(['credit_limit' => '5000000', 'credit_limit_active' => true, 'status' => CustomerStatus::CreditHold])->save();
    requestLimit($this->admin, $this->customer, '5000000')->assertSessionHasErrors('credit_limit');

    requestLimit($this->admin, $this->customer, '0')->assertSessionHasNoErrors();
    decideLimit($this->owner, ApprovalRequest::query()->sole());
    expect($this->customer->fresh()->credit_limit_active)->toBeFalse()
        ->and($this->customer->fresh()->credit_limit)->toBe('0.00');

    $this->customer->forceFill(['status' => CustomerStatus::Inactive])->save();
    requestLimit($this->admin, $this->customer, '1000000')->assertSessionHasErrors('form');
});

test('PA-51 migrasi data 060400: aturan limit bawaan T-067 yang belum dipakai menjadi atasan sales + set_high; yang diubah dibiarkan', function () {
    $migration = require database_path('migrations/2026_11_17_060400_tier_customer_credit_limit_rule.php');
    $seedOld = function () {
        ApprovalRule::query()->where('document_type', 'customer_credit_limit')->each(fn (ApprovalRule $r) => $r->delete());
        foreach ([['Limit kredit di bawah Rp 20 juta', null, '20000000', ['credit_limits.set']], ['Limit kredit Rp 20 juta ke atas', '20000000', null, ['credit_limits.set', 'credit_limits.set_high']]] as [$name, $min, $max, $perms]) {
            $rule = ApprovalRule::query()->create(['document_type' => 'customer_credit_limit', 'name' => $name, 'min_amount' => $min, 'max_amount' => $max, 'priority' => 100, 'is_active' => true]);
            foreach ($perms as $i => $perm) {
                $rule->steps()->create(['step_order' => $i + 1, 'approver_type' => 'permission', 'permission_name' => $perm]);
            }
        }
    };
    $chain = fn () => ApprovalRule::query()->where('document_type', 'customer_credit_limit')->where('min_amount', '20000000')->sole()
        ->steps()->orderBy('step_order')->get()->map(fn ($s) => $s->permission_name ?? $s->approver_type->value)->all();

    $seedOld();
    $migration->up();
    expect($chain())->toBe(['direct_supervisor', 'credit_limits.set_high']);

    $seedOld();
    ApprovalRule::query()->where('document_type', 'customer_credit_limit')->whereNull('min_amount')->update(['name' => 'Diubah Owner']);
    $migration->up();
    expect($chain())->toBe(['credit_limits.set', 'credit_limits.set_high']);
});

test('SEC-033 IDOR limit: kios di luar scope → 404', function () {
    $role = Role::query()->create(['name' => 'uji_limit_self', 'label' => 'Uji', 'guard_name' => 'web', 'data_scope' => Scope::Self]);
    $role->givePermissionTo(['customers.view', 'customers.manage', 'dashboard.view']);
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole($role);

    $this->assertOutOfScope404($user, 'POST', "/pelanggan/{$this->customer->id}/limit", ['credit_limit' => '1000000', 'reason' => 'Uji coba']);
});
