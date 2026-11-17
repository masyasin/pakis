<?php

use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Models\Role;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApproverResolver;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customer\Approvables\CustomerActivation;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Enums\VerificationMethod;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerOtpCode;
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
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\CustomerVerificationWorld;
use Tests\Support\CustomerWorld;

/*
 * T-074 bagian 1 — verifikasi aktivasi kios (BR-125, BR-165, SEC-101, SEC-105, SEC-109): foto + titik
 * + OTP WhatsApp / telepon kantor oleh pengguna lain → approval `customer_activation` bertingkat
 * (PA-51) → kios aktif. Semua data fiktif.
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Jakarta'));
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, SequenceSeeder::class, ApprovalRuleSeeder::class]);
    $this->useRealPermissions();
    Storage::fake('local');
    config(['inertia.testing.ensure_pages_exist' => false]);

    $this->sales = User::factory()->withTwoFactor()->create(['name' => 'Sari Sales'])->assignRole('admin_penjualan');
    $this->office = User::factory()->withTwoFactor()->create(['name' => 'Oki Kantor'])->assignRole('admin_penjualan');
    $this->owner = User::factory()->withTwoFactor()->create(['name' => 'Pak Owner'])->assignRole('owner');
    $this->area = CustomerWorld::area('AR-KDS', ['name' => 'Kudus']);
    $this->customer = CustomerWorld::customer($this->area, 'Toko Sumber Rejeki', ['owner_phone' => '6281234567001']);
});

function submitVerification(User $user, Customer $customer, array $payload)
{
    return test()->actingAs($user)->post("/pelanggan/{$customer->id}/verifikasi", $payload);
}

function recordCall(User $user, Customer $customer, string $note = 'Pemilik membenarkan toko & nomor ini miliknya, 17/11 10.05')
{
    return test()->actingAs($user)->post("/pelanggan/{$customer->id}/verifikasi/telepon-kantor", ['office_call_note' => $note]);
}

function decide(User $actor, ApprovalRequest $request, bool $approve = true): ApprovalRequest
{
    return app(ApprovalEngine::class)->decide($actor, $request->id, $request->fresh()->current_step, $approve, $approve ? null : 'Foto tidak jelas', true);
}

test('BR-165b SEC-105 telepon kantor: pengaju sendiri ditolak; pengguna lain mencatat → approval atas nama pengaju; pencatat boleh menyetujui → kios aktif', function () {
    submitVerification($this->sales, $this->customer, CustomerVerificationWorld::payload())
        ->assertSessionHasNoErrors()->assertRedirect("/pelanggan/{$this->customer->id}?tab=verifikasi");
    expect(CustomerVerification::query()->count())->toBe(0);

    $this->actingAs($this->office)->get("/pelanggan/{$this->customer->id}")->assertInertia(fn (Assert $p) => $p
        ->where('verification.office_call_draft.submitted_by.name', 'Sari Sales')
        ->where('verification.office_call_draft.can_record', true)
        ->where('verification.can_submit', true));

    recordCall($this->sales, $this->customer)->assertSessionHasErrors(['office_call_note' => 'Telepon kantor harus dicatat oleh pengguna selain pengaju verifikasi.']);
    expect(CustomerVerification::query()->count())->toBe(0);

    recordCall($this->office, $this->customer)->assertSessionHasNoErrors();
    $verification = CustomerActivation::query()->sole();
    $request = ApprovalRequest::query()->with('steps')->sole();
    expect($verification->method)->toBe(VerificationMethod::OfficeCall)
        ->and($verification->submitted_by)->toBe($this->sales->id)
        ->and($verification->office_call_note)->toContain('Pemilik membenarkan')
        ->and($verification->approval_request_id)->toBe($request->id)
        ->and($request->document_type)->toBe('customer_activation')
        ->and($request->requester_user_id)->toBe($this->sales->id)
        ->and($request->amount)->toBe('5000000.00')
        ->and($request->steps)->toHaveCount(1)
        ->and($request->context['Ditelepon oleh'])->toBe('Oki Kantor')
        ->and($request->context['HP pemilik'])->toBe('081•••••001');
    expect(AuditLog::query()->where('event', 'customer.office_call_recorded')->where('user_id', $this->office->id)->exists())->toBeTrue();

    // Maker-checker: pengaju tidak bisa menyetujui sendiri.
    expect(fn () => decide($this->sales, $request))->toThrow(ApiError::class, 'APPROVAL_SELF_DECISION');

    decide($this->office, $request);
    $customer = $this->customer->fresh();
    expect($customer->status)->toBe(CustomerStatus::Active)
        ->and($customer->verified_at)->not->toBeNull()
        ->and($customer->verified_by)->toBe($this->office->id)
        ->and($customer->owner_phone_verified_at)->not->toBeNull()
        ->and($customer->credit_limit)->toBe('5000000.00')
        ->and($customer->credit_limit_active)->toBeTrue()
        ->and($customer->photo_path)->toBe($verification->photo_path)
        ->and($customer->latitude)->toBe('-6.8012345')
        ->and($verification->fresh()->status)->toBe(VerificationStatus::Approved);
    Storage::disk('local')->assertExists((string) $customer->photo_path);
    expect(AuditLog::query()->where('event', 'customer.activated')->where('auditable_id', $customer->id)->exists())->toBeTrue();
});

test('BR-165a OTP: verifikasi dengan kode benar → baris otp, kode habis dipakai; kode yang sama tidak bisa dipakai ulang', function () {
    $channel = CustomerVerificationWorld::enableOtp();
    $this->actingAs($this->sales)->postJson("/pelanggan/{$this->customer->id}/otp")->assertOk();
    $code = (string) $channel->lastCode();

    submitVerification($this->sales, $this->customer, CustomerVerificationWorld::payload('otp', ['otp_code' => $code]))->assertSessionHasNoErrors();
    $verification = CustomerActivation::query()->sole();
    expect($verification->method)->toBe(VerificationMethod::Otp)
        ->and($verification->otp_verified_at)->not->toBeNull()
        ->and($verification->phone_target)->toBe('6281234567001')
        ->and(CustomerOtpCode::query()->sole()->consumed_at)->not->toBeNull();

    // Pengajuan menunggu: kirim ulang ditolak (satu verifikasi aktif per kios).
    submitVerification($this->sales, $this->customer, CustomerVerificationWorld::payload('otp', ['otp_code' => $code]))->assertSessionHasErrors('form');
    expect(CustomerActivation::query()->count())->toBe(1);
});

test('BR-165a OTP salah saat kirim verifikasi ditolak & foto tidak tertinggal; OTP mati → metode otp ditolak (CHANNEL_UNAVAILABLE)', function () {
    $channel = CustomerVerificationWorld::enableOtp();
    $this->actingAs($this->sales)->postJson("/pelanggan/{$this->customer->id}/otp")->assertOk();
    $wrong = $channel->lastCode() === '000000' ? '111111' : '000000';

    submitVerification($this->sales, $this->customer, CustomerVerificationWorld::payload('otp', ['otp_code' => $wrong]))
        ->assertSessionHasErrors(['code' => 'Kode OTP salah.']);
    expect(CustomerVerification::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    setSetting('customer.otp_enabled', false);
    submitVerification($this->sales, $this->customer, CustomerVerificationWorld::payload('otp', ['otp_code' => (string) $channel->lastCode()]))
        ->assertSessionHasErrors('form');
    expect(CustomerVerification::query()->count())->toBe(0);
});

test('PA-51 rantai aktivasi bertingkat: < Rp 20 jt satu langkah customers.verify; ≥ Rp 20 jt + credit_limits.set_high, Owner memutus langkah 2 (tidak buntu)', function () {
    $flow = function (Customer $customer, string $limit) {
        submitVerification($this->sales, $customer, CustomerVerificationWorld::payload(override: ['requested_credit_limit' => $limit]))->assertSessionHasNoErrors();
        recordCall($this->office, $customer)->assertSessionHasNoErrors();

        return ApprovalRequest::query()->with('steps')->where('document_id', CustomerActivation::query()->where('customer_id', $customer->id)->value('id'))->sole();
    };

    $small = $flow($this->customer, '19999999.99');
    expect($small->steps->pluck('permission_name')->all())->toBe(['customers.verify']);

    $bigCustomer = CustomerWorld::customer($this->area, 'Kios Besar Sejahtera');
    $big = $flow($bigCustomer, '20000000');
    expect($big->steps->pluck('permission_name')->all())->toBe(['customers.verify', 'credit_limits.set_high'])
        ->and($big->amount)->toBe('20000000.00');

    decide($this->office, $big);
    $big->refresh()->load('steps');
    expect($big->current_step)->toBe(2)
        ->and($bigCustomer->fresh()->status)->toBe(CustomerStatus::Prospect);

    // Langkah 2 punya calon (Owner), pemutus langkah 1 tidak boleh memutus lagi (BR-153(11)).
    $candidates = app(ApproverResolver::class)->directCandidates($big, $big->currentStep());
    expect($candidates->pluck('id')->all())->toBe([$this->owner->id])
        ->and($big->currentStep()->escalated)->toBeFalse();
    expect(fn () => decide($this->office, $big))->toThrow(ApiError::class);

    decide($this->owner, $big);
    expect($big->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($bigCustomer->fresh()->status)->toBe(CustomerStatus::Active)
        ->and($bigCustomer->fresh()->credit_limit)->toBe('20000000.00')
        ->and($bigCustomer->fresh()->verified_by)->toBe($this->owner->id);
});

test('BR-153(8) verifikasi ditolak → kios tetap prospek, bisa diajukan ulang', function () {
    submitVerification($this->sales, $this->customer, CustomerVerificationWorld::payload());
    recordCall($this->office, $this->customer);
    decide($this->office, ApprovalRequest::query()->sole(), approve: false);

    expect($this->customer->fresh()->status)->toBe(CustomerStatus::Prospect)
        ->and(CustomerActivation::query()->sole()->status)->toBe(VerificationStatus::Rejected);
    submitVerification($this->sales, $this->customer, CustomerVerificationWorld::payload())->assertSessionHasNoErrors();
});

test('BR-125 CHECK customers_active_limit_chk: limit aktif tanpa verified_at ditolak DB', function () {
    expect(fn () => DB::table('customers')->where('id', $this->customer->id)->update(['credit_limit_active' => true]))
        ->toThrow(QueryException::class);
});

test('BR-165 akurasi titik > customer.verify_max_accuracy_m ditolak (angka dari setting); titik & foto wajib', function () {
    submitVerification($this->sales, $this->customer, CustomerVerificationWorld::payload(override: ['accuracy_m' => 80]))->assertSessionHasErrors('accuracy_m');
    setSetting('customer.verify_max_accuracy_m', 100);
    submitVerification($this->sales, $this->customer, CustomerVerificationWorld::payload(override: ['accuracy_m' => 80]))->assertSessionHasNoErrors();

    submitVerification($this->sales, $this->customer, [...CustomerVerificationWorld::payload(), 'photo' => null, 'latitude' => null])
        ->assertSessionHasErrors(['photo', 'latitude']);
});

test('SEC-109 foto toko ditolak menurut ISI berkas (bukan ekstensi); PDF juga bukan foto', function () {
    $fake = UploadedFile::fake()->createWithContent('toko.jpg', '<?php echo "bukan foto"; ?>');
    submitVerification($this->sales, $this->customer, CustomerVerificationWorld::payload(override: ['photo' => $fake]))
        ->assertSessionHasErrors('photo');

    $pdf = UploadedFile::fake()->createWithContent('toko.jpg', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    submitVerification($this->sales, $this->customer, CustomerVerificationWorld::payload(override: ['photo' => $pdf]))
        ->assertSessionHasErrors('photo');

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(CustomerVerification::query()->count())->toBe(0);
});

test('BR-153(10) HP prospek diubah saat verifikasi menunggu → permintaan & verifikasi dibatalkan otomatis', function () {
    submitVerification($this->sales, $this->customer, CustomerVerificationWorld::payload());
    recordCall($this->office, $this->customer);

    $this->actingAs($this->sales)->put("/pelanggan/{$this->customer->id}", CustomerWorld::payload($this->area, [
        'store_name' => 'Toko Sumber Rejeki', 'owner_phone' => '081234567999',
    ]))->assertSessionHasNoErrors();

    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Cancelled)
        ->and(CustomerActivation::query()->sole()->status)->toBe(VerificationStatus::Cancelled);
});

test('BR-165b HP berubah setelah draf telepon kantor dikirim → catat telepon ditolak, draf dibuang', function () {
    submitVerification($this->sales, $this->customer, CustomerVerificationWorld::payload());
    $this->actingAs($this->sales)->put("/pelanggan/{$this->customer->id}", CustomerWorld::payload($this->area, [
        'store_name' => 'Toko Sumber Rejeki', 'owner_phone' => '081234567999',
    ]))->assertSessionHasNoErrors();

    recordCall($this->office, $this->customer)->assertSessionHasErrors('form');
    expect(CustomerVerification::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('BR-166(3) kirim verifikasi memeriksa ulang HP: nomor kini milik pegawai aktif → PHONE_BELONGS_TO_EMPLOYEE', function () {
    Employee::factory()->create(['phone' => '6281234567001']);

    submitVerification($this->sales, $this->customer, CustomerVerificationWorld::payload())->assertSessionHasErrors('owner_phone');
});

/** Role uji ber-scope `self` (Sales Paket B belum ada) yang mengelola kios dalam scope-nya. */
function selfManagerRole(string $name): Role
{
    $role = Role::query()->create(['name' => $name, 'label' => 'Uji', 'guard_name' => 'web', 'data_scope' => Scope::Self]);
    $role->givePermissionTo(['customers.view', 'customers.manage', 'customers.verify', 'dashboard.view']);

    return $role;
}

test('SEC-033 IDOR route verifikasi baru: kios di luar scope → 404 (verifikasi, telepon kantor, cek duplikat)', function () {
    $sales = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole(selfManagerRole('uji_sales_ver'));

    $this->assertOutOfScope404($sales, 'POST', "/pelanggan/{$this->customer->id}/verifikasi", CustomerVerificationWorld::payload());
    $this->assertOutOfScope404($sales, 'POST', "/pelanggan/{$this->customer->id}/verifikasi/telepon-kantor", ['office_call_note' => 'Catatan telepon uji coba']);
    $this->assertOutOfScope404($sales, 'POST', "/pelanggan/{$this->customer->id}/otp");
});

test('07 §3.4 customer_verifications: default menunggu, cari nama toko, filter bukti nomor & area, sort terlama dulu, scope Pelanggan', function () {
    $other = CustomerWorld::area('AR-PTI', ['name' => 'Pati']);
    $make = function (Customer $c, VerificationMethod $method, VerificationStatus $status, string $at) {
        $v = new CustomerActivation;
        $v->forceFill([
            'customer_id' => $c->id, 'method' => $method, 'phone_target' => $c->owner_phone,
            'office_call_note' => $method === VerificationMethod::OfficeCall ? 'Catatan uji' : null,
            'otp_verified_at' => $method === VerificationMethod::Otp ? now() : null,
            'requested_credit_limit' => '0', 'status' => $status, 'submitted_by' => $this->sales->id,
        ])->save();
        $v->forceFill(['created_at' => CarbonImmutable::parse($at, 'Asia/Jakarta')])->save();

        return $v;
    };
    $b = CustomerWorld::customer($this->area, 'Kios Bunga Tani');
    $c = CustomerWorld::customer($other, 'Kios Cempaka');
    $make($this->customer, VerificationMethod::OfficeCall, VerificationStatus::Pending, '2026-11-17 08:00');
    $make($b, VerificationMethod::Otp, VerificationStatus::Pending, '2026-11-16 08:00');
    $make($c, VerificationMethod::OfficeCall, VerificationStatus::Pending, '2026-11-15 08:00');
    $make($b, VerificationMethod::OfficeCall, VerificationStatus::Rejected, '2026-11-10 08:00');

    $rows = fn (User $u, string $qs = '') => collect($this->actingAs($u)->get('/pelanggan/verifikasi'.$qs)->assertOk()->viewData('page')['props']['rows']);

    expect($rows($this->office)->pluck('customer.store_name')->all())->toBe(['Kios Cempaka', 'Kios Bunga Tani', 'Toko Sumber Rejeki'])
        ->and($rows($this->office, '?q=bunga')->pluck('customer.store_name')->all())->toBe(['Kios Bunga Tani'])
        ->and($rows($this->office, '?filter[method][]=otp')->pluck('method')->all())->toBe(['otp'])
        ->and($rows($this->office, "?filter[sales_area_id]={$other->id}")->pluck('customer.store_name')->all())->toBe(['Kios Cempaka'])
        ->and($rows($this->office, '?filter[status][]=rejected')->count())->toBe(1)
        ->and($rows($this->office, '?sort=-created_at')->pluck('customer.store_name')->first())->toBe('Toko Sumber Rejeki');

    // Scope `self`: hanya kios yang ditugaskan ke pegawai pengguna.
    $employee = Employee::factory()->create();
    $selfUser = User::factory()->create(['employee_id' => $employee->id])->assignRole(selfManagerRole('uji_sales_list'));
    $c->forceFill(['assigned_sales_employee_id' => $employee->id])->save();
    expect($rows($selfUser)->pluck('customer.store_name')->all())->toBe(['Kios Cempaka']);

    // Tanpa customers.verify → 403; HP termasking untuk non-pengelola.
    $viewer = Role::query()->create(['name' => 'uji_lihat', 'label' => 'Uji', 'guard_name' => 'web', 'data_scope' => Scope::All]);
    $viewer->givePermissionTo(['customers.view', 'dashboard.view']);
    $this->actingAs(User::factory()->create()->assignRole($viewer))->get('/pelanggan/verifikasi')->assertForbidden();
    expect($rows($this->office)->first()['phone_target'])->toStartWith('62');
});
