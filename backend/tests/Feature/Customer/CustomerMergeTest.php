<?php

use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Models\Role;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customer\Approvables\CustomerActivation;
use App\Domain\Customer\Approvables\CustomerMerge;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Enums\VerificationMethod;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerContact;
use App\Domain\Customer\Models\CustomerVerification;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Api\ApiError;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\CustomerVerificationWorld;
use Tests\Support\CustomerWorld;

/*
 * T-074 bagian 2 — gabung kios duplikat (BR-166(5)) lewat approval `customer_merge` (Owner):
 * sumber `merged` + `merged_into_id`, kontak & verifikasi pindah, penugasan tetap, permintaan lain
 * sumber ditarik, daftar bawaan menyembunyikan `merged`. Data fiktif.
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Jakarta'));
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, SequenceSeeder::class, ApprovalRuleSeeder::class]);
    $this->useRealPermissions();
    config(['inertia.testing.ensure_pages_exist' => false]);
    Notification::fake();
    Storage::fake('local');

    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Ani Admin'])->assignRole('admin_penjualan');
    $this->office = User::factory()->withTwoFactor()->create(['name' => 'Oki Kantor'])->assignRole('admin_penjualan');
    $this->owner = User::factory()->withTwoFactor()->create(['name' => 'Pak Owner'])->assignRole('owner');
    $this->area = CustomerWorld::area('AR-KDS', ['name' => 'Kudus']);
    $this->source = CustomerWorld::customer($this->area, 'Toko Tani Makmur');
    $this->target = CustomerWorld::activeCustomer($this->area, 'TANI MAKMUR');
});

function requestMerge(User $user, Customer $source, int $targetId, string $reason = 'Kios ganda, didaftarkan dua sales')
{
    return test()->actingAs($user)->post("/pelanggan/{$source->id}/gabung", ['target_customer_id' => $targetId, 'reason' => $reason]);
}

function decideMerge(User $actor, ApprovalRequest $request, bool $approve = true): ApprovalRequest
{
    return app(ApprovalEngine::class)->decide($actor, $request->id, $request->fresh()->current_step, $approve, $approve ? null : 'Bukan kios yang sama', true);
}

test('BR-166(5) gabung disetujui Owner: sumber merged + merged_into_id, kontak & verifikasi pindah, penugasan tetap, permintaan lain sumber ditarik', function () {
    CustomerContact::query()->create(['customer_id' => $this->source->id, 'name' => 'Mbak Penjaga', 'role' => 'penjaga toko']);
    // Verifikasi aktivasi sumber yang masih menunggu (pengajuan OTP-less lewat telepon kantor).
    $this->actingAs($this->admin)->post("/pelanggan/{$this->source->id}/verifikasi", CustomerVerificationWorld::payload())->assertSessionHasNoErrors();
    $this->actingAs($this->office)->post("/pelanggan/{$this->source->id}/verifikasi/telepon-kantor", ['office_call_note' => 'Pemilik membenarkan toko ini, 17/11'])->assertSessionHasNoErrors();
    $activation = CustomerActivation::query()->sole();
    $assignments = $this->source->assignments()->count();

    requestMerge($this->admin, $this->source, $this->target->id)->assertSessionHasNoErrors();
    $merge = ApprovalRequest::query()->where('document_type', 'customer_merge')->with('steps')->sole();
    expect($merge->document_id)->toBe($this->source->id)
        ->and($merge->steps->pluck('permission_name')->all())->toBe(['customers.merge'])
        ->and($merge->context[CustomerMerge::CONTEXT_TARGET])->toBe($this->target->id);

    // Kunci mesin `_merge_into_id` tidak tampil ke penyetuju (PA-56).
    $this->actingAs($this->owner)->get("/persetujuan/{$merge->id}")->assertInertia(fn (Assert $p) => $p
        ->where('approval.context', fn ($context) => ! array_key_exists(CustomerMerge::CONTEXT_TARGET, (array) $context) && count($context) > 0));

    expect(fn () => decideMerge($this->admin, $merge))->toThrow(ApiError::class);
    decideMerge($this->owner, $merge);

    $source = $this->source->fresh();
    expect($source->status)->toBe(CustomerStatus::Merged)
        ->and($source->merged_into_id)->toBe($this->target->id)
        ->and($source->credit_limit_active)->toBeFalse()
        ->and(CustomerContact::query()->sole()->customer_id)->toBe($this->target->id)
        ->and(CustomerVerification::query()->sole()->customer_id)->toBe($this->target->id)
        ->and($activation->fresh()->status)->toBe(VerificationStatus::Cancelled)
        ->and(ApprovalRequest::query()->where('document_type', 'customer_activation')->sole()->status)->toBe(ApprovalStatus::Cancelled)
        ->and($source->assignments()->count())->toBe($assignments);

    $audit = AuditLog::query()->where('event', 'customer.merged')->sole();
    expect($audit->auditable_id)->toBe($source->id)
        ->and($audit->new_values['merged_into_id'])->toBe($this->target->id)
        ->and($audit->new_values['contacts_moved'])->toBe(1);
    expect(AuditLog::query()->where('event', 'customer.verification_withdrawn')->where('auditable_id', $source->id)->exists())->toBeTrue();
});

test('BR-166(5) 07 §3.4 daftar kios bawaan tidak menampilkan merged; filter status merged menampilkannya; merged dikecualikan dari cek HP', function () {
    $this->source->forceFill(['status' => CustomerStatus::Merged, 'merged_into_id' => $this->target->id])->save();

    $names = fn (string $qs = '') => collect($this->actingAs($this->admin)->get('/pelanggan'.$qs)->assertOk()->viewData('page')['props']['rows'])->pluck('store_name')->all();
    expect($names())->toBe(['TANI MAKMUR'])
        ->and($names('?filter[status][]=merged'))->toBe(['Toko Tani Makmur']);

    // HP kios merged boleh dipakai kios lain (BR-166(2), PA-52).
    $this->actingAs($this->admin)->post('/pelanggan', CustomerWorld::payload($this->area, [
        'store_name' => 'Kios Baru Sekali', 'owner_phone' => $this->source->owner_phone,
    ]))->assertSessionHasNoErrors();
});

test('BR-166(5) gabung ditolak: tujuan = sumber, tujuan sudah digabung, tujuan di luar scope ("tidak ditemukan"), sumber nonaktif', function () {
    requestMerge($this->admin, $this->source, $this->source->id)->assertSessionHasErrors('target_customer_id');

    $merged = CustomerWorld::customer($this->area, 'Kios Lama');
    $merged->forceFill(['status' => CustomerStatus::Merged, 'merged_into_id' => $this->target->id])->save();
    requestMerge($this->admin, $this->source, $merged->id)->assertSessionHasErrors('target_customer_id');
    requestMerge($this->admin, $this->source, 999999)->assertSessionHasErrors(['target_customer_id' => 'Kios tujuan tidak ditemukan.']);

    $employee = Employee::factory()->create();
    $role = Role::query()->create(['name' => 'uji_gabung_self', 'label' => 'Uji', 'guard_name' => 'web', 'data_scope' => Scope::Self]);
    $role->givePermissionTo(['customers.view', 'customers.manage', 'dashboard.view']);
    $self = User::factory()->create(['employee_id' => $employee->id])->assignRole($role);
    $this->source->forceFill(['assigned_sales_employee_id' => $employee->id])->save();
    requestMerge($self, $this->source, $this->target->id)->assertSessionHasErrors(['target_customer_id' => 'Kios tujuan tidak ditemukan.']);

    $this->source->forceFill(['status' => CustomerStatus::Inactive])->save();
    requestMerge($this->admin, $this->source, $this->target->id)->assertSessionHasErrors('form');
    expect(ApprovalRequest::query()->count())->toBe(0);
});

test('BR-166(5) tujuan berubah jadi merged sebelum diputuskan → persetujuan 409, sumber tetap', function () {
    requestMerge($this->admin, $this->source, $this->target->id)->assertSessionHasNoErrors();
    $other = CustomerWorld::activeCustomer($this->area, 'Kios Ketiga');
    $this->target->forceFill(['status' => CustomerStatus::Merged, 'merged_into_id' => $other->id])->save();

    expect(fn () => decideMerge($this->owner, ApprovalRequest::query()->sole()))->toThrow(ApiError::class, 'STATE_CONFLICT');
    expect($this->source->fresh()->status)->toBe(CustomerStatus::Prospect);
});

test('SEC-033 IDOR gabung: kios sumber di luar scope → 404', function () {
    $role = Role::query()->create(['name' => 'uji_gabung_idor', 'label' => 'Uji', 'guard_name' => 'web', 'data_scope' => Scope::Self]);
    $role->givePermissionTo(['customers.view', 'customers.manage', 'dashboard.view']);
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole($role);

    $this->assertOutOfScope404($user, 'POST', "/pelanggan/{$this->source->id}/gabung", ['target_customer_id' => $this->target->id, 'reason' => 'Uji coba']);
});

test('BR-166(5) metode verifikasi tidak berubah saat pindah: riwayat verifikasi tampil di kios tujuan', function () {
    $v = new CustomerActivation;
    $v->forceFill([
        'customer_id' => $this->source->id, 'method' => VerificationMethod::OfficeCall, 'phone_target' => $this->source->owner_phone,
        'office_call_note' => 'Catatan lama', 'requested_credit_limit' => '0', 'status' => VerificationStatus::Rejected, 'submitted_by' => $this->admin->id,
    ])->save();
    requestMerge($this->admin, $this->source, $this->target->id);
    decideMerge($this->owner, ApprovalRequest::query()->sole());

    $this->actingAs($this->admin)->get("/pelanggan/{$this->target->id}")->assertInertia(fn (Assert $p) => $p
        ->has('verification.history', 1)
        ->where('verification.history.0.method', 'office_call'));
    $this->actingAs($this->admin)->get("/pelanggan/{$this->source->id}")->assertInertia(fn (Assert $p) => $p
        ->where('changes.merge.merged_into.id', $this->target->id)
        ->where('can.update', false)
        ->where('changes.status.actions', []));
});
