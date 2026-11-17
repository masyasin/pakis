<?php

use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Models\Role;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customer\Approvables\CustomerPhoneChange;
use App\Domain\Customer\Enums\VerificationMethod;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerOtpCode;
use App\Domain\Customer\Notifications\CustomerPhoneChangedNotification;
use App\Domain\Employee\Models\Employee;
use App\Domain\Notification\Enums\NotificationKind;
use App\Models\User;
use App\Support\Api\ApiError;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\CustomerVerificationWorld;
use Tests\Support\CustomerWorld;

/*
 * T-074 bagian 2 — ganti HP kios (BR-126, BR-166(4), SEC-105, TA-04): bukti HP LAMA (OTP WhatsApp /
 * telepon kantor) → approval `customer_phone_change` (Owner) → HP baru + notifikasi termasking ke
 * pemegang `customers.change_phone_approve` (bukan Super Admin) + audit nilai lama/baru. Data fiktif.
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Jakarta'));
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, SequenceSeeder::class, ApprovalRuleSeeder::class]);
    $this->useRealPermissions();
    config(['inertia.testing.ensure_pages_exist' => false]);
    Notification::fake();

    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Ani Admin'])->assignRole('admin_penjualan');
    $this->owner = User::factory()->withTwoFactor()->create(['name' => 'Pak Owner'])->assignRole('owner');
    $this->vendor = User::factory()->withTwoFactor()->create(['name' => 'Vendor'])->assignRole('super_admin');
    $this->area = CustomerWorld::area('AR-KDS', ['name' => 'Kudus']);
    $this->customer = CustomerWorld::activeCustomer($this->area, 'Toko Sumber Rejeki', ['owner_phone' => '6281234567001']);
});

function changePhone(User $user, Customer $customer, array $payload)
{
    return test()->actingAs($user)->post("/pelanggan/{$customer->id}/ganti-hp", [
        'new_phone' => '0812 3456 7999',
        'method' => 'office_call',
        'office_call_note' => 'Pemilik di HP lama membenarkan ganti nomor, 17/11 10.05',
        'reason' => 'HP lama hilang',
        ...$payload,
    ]);
}

function decidePhone(User $actor, ApprovalRequest $request, bool $approve = true): ApprovalRequest
{
    return app(ApprovalEngine::class)->decide($actor, $request->id, $request->fresh()->current_step, $approve, $approve ? null : 'Nomor tidak bisa dipastikan', true);
}

test('BR-166(4) SEC-105 ganti HP lewat telepon kantor → approval Owner → HP baru, audit lama/baru, notifikasi termasking ke Owner (bukan Super Admin)', function () {
    changePhone($this->admin, $this->customer, [])->assertSessionHasNoErrors()
        ->assertRedirect("/pelanggan/{$this->customer->id}?tab=verifikasi");

    $change = CustomerPhoneChange::query()->sole();
    $request = ApprovalRequest::query()->with('steps')->sole();
    expect($change->method)->toBe(VerificationMethod::OfficeCall)
        ->and($change->phone_previous)->toBe('6281234567001')
        ->and($change->phone_target)->toBe('6281234567999')
        ->and($change->status)->toBe(VerificationStatus::Pending)
        ->and($request->document_type)->toBe('customer_phone_change')
        ->and($request->steps->pluck('permission_name')->all())->toBe(['customers.change_phone_approve'])
        ->and($request->context['HP lama'])->toBe('6281•••••001')
        ->and($request->context['HP baru'])->toBe('6281•••••999')
        ->and(json_encode($request->context))->not->toContain('6281234567999');
    // HP belum berubah sebelum disetujui.
    expect($this->customer->fresh()->owner_phone)->toBe('6281234567001');

    // Maker-checker: pengaju tidak bisa menyetujui; Super Admin bukan calon (PA-37).
    expect(fn () => decidePhone($this->admin, $request))->toThrow(ApiError::class);
    decidePhone($this->owner, $request);

    $customer = $this->customer->fresh();
    expect($customer->owner_phone)->toBe('6281234567999')
        ->and($customer->owner_phone_verified_at)->not->toBeNull()
        ->and($change->fresh()->status)->toBe(VerificationStatus::Approved)
        ->and($request->fresh()->status)->toBe(ApprovalStatus::Approved);

    $audit = AuditLog::query()->where('event', 'customer.phone_changed')->sole();
    expect($audit->old_values['owner_phone'])->toBe('6281234567001')
        ->and($audit->new_values['owner_phone'])->toBe('6281234567999')
        ->and($audit->auditable_id)->toBe($customer->id);

    Notification::assertSentTo($this->owner, CustomerPhoneChangedNotification::class, function (CustomerPhoneChangedNotification $n) {
        $text = $n->title().' '.$n->body();

        return $n->kind() === NotificationKind::CustomerPhoneChanged
            && str_contains($text, '6281•••••001') && str_contains($text, '6281•••••999')
            && ! str_contains($text, '6281234567001') && ! str_contains($text, '6281234567999');
    });
    Notification::assertNotSentTo($this->vendor, CustomerPhoneChangedNotification::class);
    Notification::assertNotSentTo($this->admin, CustomerPhoneChangedNotification::class);
});

test('BR-166(4) SEC-105 OTP ganti HP dikirim ke HP LAMA (WhatsApp saja); kode benar → pengajuan method otp, kode habis', function () {
    $channel = CustomerVerificationWorld::enableOtp();
    $this->actingAs($this->admin)->postJson("/pelanggan/{$this->customer->id}/ganti-hp/otp")->assertOk()
        ->assertJsonPath('data.phone', '6281•••••001');
    expect(array_column($channel->sent, 'address'))->toBe(['6281234567001']);
    $code = (string) $channel->lastCode();

    $this->actingAs($this->admin)->postJson("/pelanggan/{$this->customer->id}/ganti-hp/otp/cek", ['code' => $code])->assertOk()
        ->assertJsonPath('data.valid', true);
    changePhone($this->admin, $this->customer, ['method' => 'otp', 'otp_code' => $code, 'office_call_note' => null])->assertSessionHasNoErrors();

    $change = CustomerPhoneChange::query()->sole();
    expect($change->method)->toBe(VerificationMethod::Otp)
        ->and($change->otp_verified_at)->not->toBeNull()
        ->and(CustomerOtpCode::query()->sole()->consumed_at)->not->toBeNull()
        ->and(CustomerOtpCode::query()->sole()->purpose->value)->toBe('phone_change');

    // OTP mati → 503 (jalur telepon kantor tetap ada).
    setSetting('customer.otp_enabled', false);
    $this->actingAs($this->admin)->postJson("/pelanggan/{$this->customer->id}/ganti-hp/otp")->assertStatus(503)
        ->assertJsonPath('error.code', 'CHANNEL_UNAVAILABLE');
});

test('BR-126 BR-166(3) ganti HP ditolak: HP baru milik pegawai aktif, sama dengan HP sekarang, sudah ada ganti HP menunggu; prospek belum terverifikasi lewat form', function () {
    Employee::factory()->create(['phone' => '6281234567888']);
    changePhone($this->admin, $this->customer, ['new_phone' => '081234567888'])->assertSessionHasErrors('new_phone');
    changePhone($this->admin, $this->customer, ['new_phone' => '081234567001'])->assertSessionHasErrors('new_phone');

    changePhone($this->admin, $this->customer, [])->assertSessionHasNoErrors();
    changePhone($this->admin, $this->customer, ['new_phone' => '081234567777'])->assertSessionHasErrors('form');
    expect(CustomerPhoneChange::query()->count())->toBe(1);

    $prospect = CustomerWorld::customer($this->area, 'Kios Prospek');
    changePhone($this->admin, $prospect, ['new_phone' => '081234567666'])->assertSessionHasErrors('form');
});

test('BR-166(4) hanya customers.change_phone: pengelola kios (customers.manage) tanpa change_phone → 403', function () {
    $role = Role::query()->create(['name' => 'uji_kelola', 'label' => 'Uji', 'guard_name' => 'web', 'data_scope' => Scope::All]);
    $role->givePermissionTo(['customers.view', 'customers.manage', 'dashboard.view']);
    $manager = User::factory()->create()->assignRole($role);

    changePhone($manager, $this->customer, [])->assertForbidden();
    $this->actingAs($manager)->postJson("/pelanggan/{$this->customer->id}/ganti-hp/otp")->assertForbidden();
    expect(CustomerPhoneChange::query()->count())->toBe(0);
});

test('BR-166(3) SEC-105 HP baru dipakai kios lain setelah diajukan → persetujuan ditolak, HP tetap lama', function () {
    changePhone($this->admin, $this->customer, [])->assertSessionHasNoErrors();
    CustomerWorld::customer($this->area, 'Kios Penyerobot')->forceFill(['owner_phone' => '6281234567999'])->save();

    expect(fn () => decidePhone($this->owner, ApprovalRequest::query()->sole()))->toThrow(ApiError::class, 'CUSTOMER_PHONE_DUPLICATE');
    expect($this->customer->fresh()->owner_phone)->toBe('6281234567001')
        ->and(CustomerPhoneChange::query()->sole()->status)->toBe(VerificationStatus::Pending);
    Notification::assertNotSentTo($this->owner, CustomerPhoneChangedNotification::class);
});

test('BR-153(8) ganti HP ditolak Owner → HP tetap, verifikasi rejected; detail kios menampilkan ganti HP menunggu termasking', function () {
    changePhone($this->admin, $this->customer, [])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get("/pelanggan/{$this->customer->id}")->assertInertia(fn (Assert $p) => $p
        ->where('changes.phone_change.pending.phone_target', '6281•••••999')
        ->where('changes.phone_change.can_request', false)
        ->where('can.change_phone', true));

    decidePhone($this->owner, ApprovalRequest::query()->sole(), approve: false);
    expect($this->customer->fresh()->owner_phone)->toBe('6281234567001')
        ->and(CustomerPhoneChange::query()->sole()->status)->toBe(VerificationStatus::Rejected);
});

test('SEC-033 IDOR ganti HP: kios di luar scope → 404 (ganti-hp, otp, otp/cek)', function () {
    $role = Role::query()->create(['name' => 'uji_ganti_hp_self', 'label' => 'Uji', 'guard_name' => 'web', 'data_scope' => Scope::Self]);
    $role->givePermissionTo(['customers.view', 'customers.manage', 'customers.change_phone', 'dashboard.view']);
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole($role);

    $this->assertOutOfScope404($user, 'POST', "/pelanggan/{$this->customer->id}/ganti-hp", ['new_phone' => '081234567999', 'method' => 'office_call', 'office_call_note' => 'Catatan telepon uji', 'reason' => 'Uji coba']);
    $this->assertOutOfScope404($user, 'POST', "/pelanggan/{$this->customer->id}/ganti-hp/otp");
    $this->assertOutOfScope404($user, 'POST', "/pelanggan/{$this->customer->id}/ganti-hp/otp/cek", ['code' => '123456']);
});
