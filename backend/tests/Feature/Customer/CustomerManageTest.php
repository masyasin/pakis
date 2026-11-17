<?php

use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Models\Role;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customer\Approvables\CustomerActivation;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Enums\VerificationMethod;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Events\CustomerActivated;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerContact;
use App\Domain\Customer\Support\CustomerFileUrls;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Api\ApiError;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\CustomerVerificationWorld;
use Tests\Support\CustomerWorld;

/*
 * T-074 bagian 2 — transisi status manual (15 §6.6), kontak kios, ketentuan dagang (tempo panen,
 * pembeli terbatas BR-112), dan berkas privat lewat URL bertanda tangan (SEC-108/109). Data fiktif.
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
    $this->area = CustomerWorld::area('AR-KDS', ['name' => 'Kudus']);
    $this->customer = CustomerWorld::activeCustomer($this->area, 'Toko Sumber Rejeki', ['owner_phone' => '6281234567001']);
});

function changeStatus(User $user, Customer $customer, string $action, string $reason = 'Tunggakan 2 bulan, sesuai rapat')
{
    return test()->actingAs($user)->post("/pelanggan/{$customer->id}/status", ['action' => $action, 'reason' => $reason]);
}

/** Role uji ber-scope `self` (Sales Paket B belum ada) untuk uji IDOR. */
function selfCustomerRole(string $name): Role
{
    $role = Role::query()->create(['name' => $name, 'label' => 'Uji', 'guard_name' => 'web', 'data_scope' => Scope::Self]);
    $role->givePermissionTo(['customers.view', 'customers.manage', 'dashboard.view']);

    return $role;
}

test('15 §6.6 tahan kredit → lepas → nonaktif → aktifkan kembali, alasan wajib, diaudit customer.status_changed', function () {
    changeStatus($this->admin, $this->customer, 'hold', '')->assertSessionHasErrors('reason');
    changeStatus($this->admin, $this->customer, 'release')->assertSessionHasErrors('form');

    changeStatus($this->admin, $this->customer, 'hold')->assertSessionHasNoErrors();
    expect($this->customer->fresh()->status)->toBe(CustomerStatus::CreditHold)
        ->and($this->customer->fresh()->hold_reason)->toBe('Tunggakan 2 bulan, sesuai rapat');
    $this->actingAs($this->admin)->get("/pelanggan/{$this->customer->id}")->assertInertia(fn (Assert $p) => $p
        ->where('changes.status.hold_reason', 'Tunggakan 2 bulan, sesuai rapat')
        ->where('changes.status.actions', [['value' => 'release', 'label' => 'Lepas tahan kredit'], ['value' => 'deactivate', 'label' => 'Nonaktifkan']]));

    changeStatus($this->admin, $this->customer, 'release', 'Tunggakan sudah lunas')->assertSessionHasNoErrors();
    expect($this->customer->fresh()->status)->toBe(CustomerStatus::Active)
        ->and($this->customer->fresh()->hold_reason)->toBeNull();

    changeStatus($this->admin, $this->customer, 'deactivate', 'Toko tutup permanen')->assertSessionHasNoErrors();
    expect($this->customer->fresh()->status)->toBe(CustomerStatus::Inactive);
    changeStatus($this->admin, $this->customer, 'reactivate', 'Toko buka lagi')->assertSessionHasNoErrors();
    expect($this->customer->fresh()->status)->toBe(CustomerStatus::Active);

    $events = AuditLog::query()->where('event', 'customer.status_changed')->orderBy('id')->get();
    expect($events)->toHaveCount(4)
        ->and($events[0]->old_values['status'])->toBe('active')
        ->and($events[0]->new_values['status'])->toBe('credit_hold')
        ->and($events[0]->new_values['reason'])->toBe('Tunggakan 2 bulan, sesuai rapat');
});

test('15 §6.6 BR-153(10) nonaktifkan prospek menarik verifikasi menunggu; aktifkan kembali prospek → tetap prospek & HP dicek ulang (BR-166(2))', function () {
    $prospect = CustomerWorld::customer($this->area, 'Kios Prospek', ['owner_phone' => '6281234567002']);
    $office = User::factory()->withTwoFactor()->create()->assignRole('admin_penjualan');
    $this->actingAs($this->admin)->post("/pelanggan/{$prospect->id}/verifikasi", CustomerVerificationWorld::payload())->assertSessionHasNoErrors();
    $this->actingAs($office)->post("/pelanggan/{$prospect->id}/verifikasi/telepon-kantor", ['office_call_note' => 'Pemilik membenarkan toko ini, 17/11'])->assertSessionHasNoErrors();

    changeStatus($this->admin, $prospect, 'deactivate', 'Prospek batal')->assertSessionHasNoErrors();
    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Cancelled);

    // Selama nonaktif nomornya dipakai kios lain → aktifkan kembali ditolak.
    CustomerWorld::customer($this->area, 'Kios Pengganti')->forceFill(['owner_phone' => '6281234567002'])->save();
    changeStatus($this->admin, $prospect, 'reactivate', 'Prospek lanjut')->assertSessionHasErrors('owner_phone');
    Customer::query()->where('store_name', 'Kios Pengganti')->update(['owner_phone' => '6281234567003']);
    changeStatus($this->admin, $prospect, 'reactivate', 'Prospek lanjut')->assertSessionHasNoErrors();
    expect($prospect->fresh()->status)->toBe(CustomerStatus::Prospect);
});

test('T-074 kontak kios: tambah, ubah, hapus (customers.manage), HP dinormalisasi, diaudit; kontak kios lain 404', function () {
    $this->actingAs($this->admin)->post("/pelanggan/{$this->customer->id}/kontak", ['name' => 'Mbak Sri', 'role' => 'Penjaga toko', 'phone' => '0812 3456 7100'])
        ->assertSessionHasNoErrors()->assertRedirect("/pelanggan/{$this->customer->id}?tab=kontak");
    $contact = CustomerContact::query()->sole();
    expect($contact->phone)->toBe('6281234567100');

    $this->actingAs($this->admin)->put("/pelanggan/{$this->customer->id}/kontak/{$contact->id}", ['name' => 'Mbak Sri', 'role' => 'Bagian bayar', 'phone' => null])
        ->assertSessionHasNoErrors();
    expect($contact->fresh()->role)->toBe('Bagian bayar')->and($contact->fresh()->phone)->toBeNull();

    $this->actingAs($this->admin)->get("/pelanggan/{$this->customer->id}")->assertInertia(fn (Assert $p) => $p
        ->where('changes.contacts.can_manage', true)
        ->where('changes.contacts.rows.0.name', 'Mbak Sri'));

    $other = CustomerWorld::activeCustomer($this->area, 'Kios Lain');
    $this->actingAs($this->admin)->delete("/pelanggan/{$other->id}/kontak/{$contact->id}")->assertNotFound();
    $this->actingAs($this->admin)->delete("/pelanggan/{$this->customer->id}/kontak/{$contact->id}")->assertSessionHasNoErrors();
    expect(CustomerContact::query()->count())->toBe(0);

    expect(AuditLog::query()->whereIn('event', ['customer.contact_added', 'customer.contact_updated', 'customer.contact_removed'])
        ->where('auditable_id', $this->customer->id)->orderBy('id')->pluck('event')->all())
        ->toBe(['customer.contact_added', 'customer.contact_updated', 'customer.contact_removed']);
});

test('BR-112 SEC-109 ketentuan dagang lewat ubah kios: tempo panen & pembeli terbatas wajib dokumen (ditolak menurut isi), diaudit', function () {
    $base = CustomerWorld::payload($this->area, ['store_name' => 'Toko Sumber Rejeki', 'owner_phone' => '6281234567001']);

    $this->actingAs($this->admin)->put("/pelanggan/{$this->customer->id}", [...$base, 'harvest_term_allowed' => true])
        ->assertSessionHasErrors('harvest_term_max_days');
    $this->actingAs($this->admin)->put("/pelanggan/{$this->customer->id}", [...$base, 'restricted_buyer_eligible' => true, 'restricted_buyer_valid_until' => '2027-12-31'])
        ->assertSessionHasErrors('restricted_buyer_document');
    $fake = UploadedFile::fake()->createWithContent('izin.pdf', '<?php echo "bukan pdf"; ?>');
    $this->actingAs($this->admin)->put("/pelanggan/{$this->customer->id}", [...$base, 'restricted_buyer_eligible' => true, 'restricted_buyer_valid_until' => '2027-12-31', 'restricted_buyer_document' => $fake])
        ->assertSessionHasErrors('restricted_buyer_document');
    expect(Storage::disk('local')->allFiles())->toBe([]);

    $pdf = UploadedFile::fake()->createWithContent('izin.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    $this->actingAs($this->admin)->put("/pelanggan/{$this->customer->id}", [
        ...$base, 'harvest_term_allowed' => true, 'harvest_term_max_days' => 120,
        'restricted_buyer_eligible' => true, 'restricted_buyer_note' => 'Kartu kelompok tani', 'restricted_buyer_valid_until' => '2027-12-31', 'restricted_buyer_document' => $pdf,
    ])->assertSessionHasNoErrors();

    $customer = $this->customer->fresh();
    expect($customer->harvest_term_allowed)->toBeTrue()
        ->and($customer->harvest_term_max_days)->toBe(120)
        ->and($customer->restricted_buyer_eligible)->toBeTrue()
        ->and($customer->restricted_buyer_valid_until?->toDateString())->toBe('2027-12-31');
    Storage::disk('local')->assertExists((string) $customer->restricted_buyer_document_path);
    $audit = AuditLog::query()->where('event', 'customer.updated')->latest('id')->first();
    expect($audit?->new_values)->toMatchArray(['harvest_term_max_days' => 120, 'restricted_buyer_eligible' => true, 'restricted_buyer_document_replaced' => true]);

    // Form lama tanpa kolom ini tidak mengubah ketentuan dagang.
    $this->actingAs($this->admin)->put("/pelanggan/{$this->customer->id}", [...$base, 'address' => 'Alamat baru'])->assertSessionHasNoErrors();
    expect($this->customer->fresh()->harvest_term_max_days)->toBe(120);
});

test('SEC-108 SEC-109 foto toko & foto verifikasi hanya lewat URL bertanda tangan 5 menit; tanpa tanda tangan 403, kedaluwarsa 403', function () {
    $photo = CustomerVerificationWorld::photo();
    Storage::disk('local')->put('customer-verifications/toko.jpg', (string) $photo->get());
    $this->customer->forceFill(['photo_path' => 'customer-verifications/toko.jpg'])->save();

    $url = $this->actingAs($this->admin)->get("/pelanggan/{$this->customer->id}")->viewData('page')['props']['customer']['photo_url'];
    expect($url)->toStartWith("/pelanggan/{$this->customer->id}/foto?expires=");
    $this->actingAs($this->admin)->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($this->admin)->get("/pelanggan/{$this->customer->id}/foto")->assertForbidden();

    $this->travel(CustomerFileUrls::MINUTES + 1)->minutes();
    $this->actingAs($this->admin)->get($url)->assertForbidden();
});

test('SEC-033 IDOR route baru T-074 bagian 2: kios di luar scope → 404 (status, kontak, berkas bertanda tangan)', function () {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole(selfCustomerRole('uji_kelola_self'));
    $contact = CustomerContact::query()->create(['customer_id' => $this->customer->id, 'name' => 'Kontak Uji']);
    Storage::disk('local')->put('customer-verifications/toko.jpg', 'x');
    $this->customer->forceFill(['photo_path' => 'customer-verifications/toko.jpg', 'restricted_buyer_document_path' => 'customer-verifications/toko.jpg'])->save();

    $this->assertOutOfScope404($user, 'POST', "/pelanggan/{$this->customer->id}/status", ['action' => 'hold', 'reason' => 'Uji coba']);
    $this->assertOutOfScope404($user, 'POST', "/pelanggan/{$this->customer->id}/kontak", ['name' => 'Kontak Baru']);
    $this->assertOutOfScope404($user, 'PUT', "/pelanggan/{$this->customer->id}/kontak/{$contact->id}", ['name' => 'Kontak Baru']);
    $this->assertOutOfScope404($user, 'DELETE', "/pelanggan/{$this->customer->id}/kontak/{$contact->id}");
    // URL bertanda tangan SAH milik pengguna lain tetap 404 bagi pengguna di luar scope.
    $this->assertOutOfScope404($user, 'GET', (string) CustomerFileUrls::photo($this->customer->fresh()));
    $this->assertOutOfScope404($user, 'GET', (string) CustomerFileUrls::restrictedDocument($this->customer->fresh()));

    $verification = new CustomerActivation;
    $verification->forceFill([
        'customer_id' => $this->customer->id, 'method' => VerificationMethod::OfficeCall, 'phone_target' => $this->customer->owner_phone,
        'office_call_note' => 'Catatan uji', 'photo_path' => 'customer-verifications/toko.jpg', 'requested_credit_limit' => '0',
        'status' => VerificationStatus::Rejected, 'submitted_by' => $this->admin->id,
    ])->save();
    $verificationUrl = (string) CustomerFileUrls::verificationPhoto($verification);
    expect($verificationUrl)->toStartWith("/pelanggan/verifikasi/{$verification->id}/foto?expires=");
    $this->assertOutOfScope404($user, 'GET', $verificationUrl);
});

test('15 §11.2 event CustomerActivated dipancarkan setelah aktivasi disetujui (setelah commit)', function () {
    Event::fake([CustomerActivated::class]);
    $prospect = CustomerWorld::customer($this->area, 'Kios Prospek Event');
    $office = User::factory()->withTwoFactor()->create()->assignRole('admin_penjualan');
    $this->actingAs($this->admin)->post("/pelanggan/{$prospect->id}/verifikasi", CustomerVerificationWorld::payload())->assertSessionHasNoErrors();
    $this->actingAs($office)->post("/pelanggan/{$prospect->id}/verifikasi/telepon-kantor", ['office_call_note' => 'Pemilik membenarkan toko ini, 17/11'])->assertSessionHasNoErrors();
    Event::assertNotDispatched(CustomerActivated::class);

    $request = ApprovalRequest::query()->sole();
    app(ApprovalEngine::class)->decide($office, $request->id, $request->current_step, true, null, true);

    Event::assertDispatched(CustomerActivated::class, fn (CustomerActivated $e) => $e->customerId === $prospect->id && $e->approvedBy === $office->id);
});

test('15 §5 §6.4 otorisasi pengajuan: tanpa customers.manage → 403 untuk limit, gabung, status, kontak; izin khusus hanya di langkah persetujuan', function () {
    $viewer = Role::query()->create(['name' => 'uji_lihat_kios', 'label' => 'Uji', 'guard_name' => 'web', 'data_scope' => Scope::All]);
    $viewer->givePermissionTo(['customers.view', 'dashboard.view', 'credit_limits.set', 'customers.merge']);
    $user = User::factory()->create()->assignRole($viewer);
    $target = CustomerWorld::activeCustomer($this->area, 'Kios Tujuan');

    $this->actingAs($user)->post("/pelanggan/{$this->customer->id}/limit", ['credit_limit' => '1000000', 'reason' => 'Uji coba'])->assertForbidden();
    $this->actingAs($user)->post("/pelanggan/{$this->customer->id}/gabung", ['target_customer_id' => $target->id, 'reason' => 'Uji coba'])->assertForbidden();
    $this->actingAs($user)->post("/pelanggan/{$this->customer->id}/status", ['action' => 'hold', 'reason' => 'Uji coba'])->assertForbidden();
    $this->actingAs($user)->post("/pelanggan/{$this->customer->id}/kontak", ['name' => 'Kontak Uji'])->assertForbidden();
    expect(ApprovalRequest::query()->count())->toBe(0);

    // Pengaju (customers.manage, tanpa credit_limits.set/customers.merge) boleh mengajukan, tetapi tidak
    // pernah memutuskan: langkahnya dipegang izin khusus + maker-checker (BR-153(4)).
    expect($this->admin->can('credit_limits.set'))->toBeFalse()
        ->and($this->admin->can('customers.merge'))->toBeFalse();
    $this->actingAs($this->admin)->post("/pelanggan/{$this->customer->id}/limit", ['credit_limit' => '1000000', 'reason' => 'Uji coba'])->assertSessionHasNoErrors();
    $request = ApprovalRequest::query()->with('steps')->sole();
    expect($request->steps->pluck('permission_name')->all())->toBe(['credit_limits.set'])
        ->and(fn () => app(ApprovalEngine::class)->decide($this->admin, $request->id, 1, true, null, true))->toThrow(ApiError::class);
});
