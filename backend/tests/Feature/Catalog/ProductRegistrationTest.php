<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Enums\RegistrationStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductRegistration;
use App\Domain\Catalog\Notifications\RegistrationExpiringNotification;
use App\Domain\Catalog\Queries\ActiveRegistration;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * T-071 — izin edar produk (FR-A10, BR-152, SEC-109, 15 §4.4/§5/§11.1). Data dummy. Waktu dibekukan
 * pada siang hari lokal agar "hari ini" UTC dan lokal sama (zona diuji terpisah di ActiveRegistration).
 */

beforeEach(function () {
    $this->seed([ReferenceSeeder::class, PermissionSeeder::class, RoleSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 05:00:00', 'UTC')); // 12.00 WIB
    $this->user = User::factory()->create();
    Gate::define('products.view', fn () => true);
    Gate::define('product_registrations.manage', fn () => true);

    $category = ProductCategory::query()->create([
        'code' => 'INS', 'name' => 'Insektisida', 'kind' => 'insecticide',
        'requires_batch' => true, 'requires_expiry' => true, 'requires_serial' => false,
        'requires_registration' => true, 'default_bin_zone' => 'pesticide', 'sort_order' => 10, 'is_active' => true,
    ]);
    $this->product = regProduct($category, 'INS-001', 'Insektisida Uji');
    $this->category = $category;
});

afterEach(fn () => CarbonImmutable::setTestNow());

function regProduct(ProductCategory $category, string $sku, string $name): Product
{
    $unit = ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('code', 'bottle')->value('id');
    $product = (new Product)->forceFill([
        'sku' => $sku, 'name' => $name, 'product_category_id' => $category->id, 'base_unit_id' => $unit,
        'tracks_batch' => true, 'tracks_expiry' => true, 'tracks_serial' => false, 'requires_registration' => true,
        'is_restricted' => false, 'is_sellable' => true, 'is_purchasable' => true, 'status' => 'active',
    ]);
    $product->save();

    return $product;
}

function registration(Product $product, string $validUntil, string $no = 'RI-0001', string $status = 'active'): ProductRegistration
{
    $r = (new ProductRegistration)->forceFill([
        'product_id' => $product->id, 'registration_no' => $no, 'valid_until' => $validUntil, 'status' => $status,
    ]);
    $r->save();

    return $r;
}

/** @return array<string, mixed> */
function registrationPayload(array $overrides = []): array
{
    return ['registration_no' => 'RI. 01020120231234', 'holder_name' => 'PT Prinsipal Demo', 'issued_at' => '2025-01-10', 'valid_until' => '2030-01-09', 'note' => null, ...$overrides];
}

test('BR-152 izin valid_until kemarin → job → expired; produk wajib izin tanpa izin active → ActiveRegistration null', function () {
    $r = registration($this->product, '2026-11-15');
    // Sebelum job berjalan pun sudah tidak berlaku (tanggal dibandingkan langsung).
    expect(ActiveRegistration::for($this->product))->toBeNull();

    $this->artisan('catalog:refresh-registrations')->assertSuccessful();

    expect($r->fresh()->status)->toBe(RegistrationStatus::Expired)
        ->and(ActiveRegistration::for($this->product))->toBeNull()
        ->and(AuditLog::query()->where('event', 'product_registration.expired')->count())->toBe(1);

    // Izin yang habis HARI INI masih berlaku sampai akhir hari.
    $today = registration($this->product, '2026-11-16', 'RI-0002');
    $this->artisan('catalog:refresh-registrations');
    expect($today->fresh()->status)->toBe(RegistrationStatus::Active)
        ->and(ActiveRegistration::for($this->product)?->id)->toBe($today->id);
});

test('BR-152 perpanjangan (izin baru) membuat produk berlaku lagi tanpa mengubah izin lama; berlaku = valid_until terjauh', function () {
    $old = registration($this->product, '2026-11-01', 'RI-LAMA', 'expired');
    expect(ActiveRegistration::for($this->product))->toBeNull();

    $this->actingAs($this->user)->post("/produk/{$this->product->id}/izin-edar", registrationPayload(['registration_no' => 'ri-baru-01']))
        ->assertSessionHasNoErrors()->assertRedirect("/produk/{$this->product->id}?tab=izin");

    $new = ProductRegistration::query()->where('registration_no', 'RI-BARU-01')->sole();
    expect(ActiveRegistration::for($this->product)?->id)->toBe($new->id)
        ->and($old->fresh()->only(['registration_no', 'status', 'valid_until']))->toEqual($old->only(['registration_no', 'status', 'valid_until']))
        ->and(AuditLog::query()->where('event', 'product_registration.created')->count())->toBe(1);

    // Dua izin berlaku: yang habisnya terjauh dipakai.
    $shorter = registration($this->product, '2027-06-30', 'RI-PENDEK');
    expect(ActiveRegistration::for($this->product)?->id)->toBe($new->id);
    expect(ActiveRegistration::for($this->product, CarbonImmutable::parse('2031-01-01'))?->id)->toBeNull();
    expect($shorter->id)->toBeInt();
});

test('BR-152 pengingat H-60/30/7/0 tidak terkirim dua kali walau job diulang; penerima = pemegang product_registrations.manage', function () {
    Notification::fake();
    $manager = User::factory()->create(['is_active' => true])->givePermissionTo('product_registrations.manage');
    $other = User::factory()->create(['is_active' => true]);
    $r = registration($this->product, '2027-01-15'); // tepat 60 hari lagi

    $this->artisan('catalog:refresh-registrations');
    $this->artisan('catalog:refresh-registrations');
    Notification::assertSentToTimes($manager, RegistrationExpiringNotification::class, 1);
    Notification::assertNotSentTo($other, RegistrationExpiringNotification::class);
    expect(DB::table('reminder_logs')->where('subject_id', $r->id)->pluck('reminder_key')->all())->toBe(['h60']);

    // H-30, lalu job terlewat beberapa hari: H-7 tetap terkirim sekali saja.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-16 05:00:00', 'UTC'));
    $this->artisan('catalog:refresh-registrations');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-10 05:00:00', 'UTC')); // sisa 5 hari
    $this->artisan('catalog:refresh-registrations');
    $this->artisan('catalog:refresh-registrations');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-15 05:00:00', 'UTC')); // H-0
    $this->artisan('catalog:refresh-registrations');

    Notification::assertSentToTimes($manager, RegistrationExpiringNotification::class, 4);
    expect(DB::table('reminder_logs')->where('subject_id', $r->id)->orderBy('reminder_key')->pluck('reminder_key')->all())
        ->toBe(['h0', 'h30', 'h60', 'h7']);
});

test('BR-152 izin baru yang sisa 20 hari hanya memicu satu pengingat (h30), h60 ikut tercatat', function () {
    Notification::fake();
    $manager = User::factory()->create(['is_active' => true])->givePermissionTo('product_registrations.manage');
    $r = registration($this->product, '2026-12-06');

    $this->artisan('catalog:refresh-registrations');
    Notification::assertSentToTimes($manager, RegistrationExpiringNotification::class, 1);
    expect(DB::table('reminder_logs')->where('subject_id', $r->id)->orderBy('reminder_key')->pluck('reminder_key')->all())->toBe(['h30', 'h60']);
});

test('BR-152 filter registration_status=expiring benar di batas hari ke-60', function () {
    $edge = $this->product; // habis tepat hari ke-60 → akan habis
    registration($edge, '2027-01-15', 'RI-60');
    $after = regProduct($this->category, 'INS-061', 'Produk 61 Hari');
    registration($after, '2027-01-16', 'RI-61'); // hari ke-61 → masih berlaku normal
    $expired = regProduct($this->category, 'INS-EXP', 'Produk Habis');
    registration($expired, '2026-10-01', 'RI-EXP', 'expired');
    $none = regProduct($this->category, 'INS-NON', 'Produk Tanpa Izin');

    $skus = fn (string $state) => collect($this->actingAs($this->user)->get("/produk?filter[registration_status]={$state}")
        ->assertOk()->inertiaProps('rows'))->pluck('sku')->all();

    expect($skus('expiring'))->toBe(['INS-001'])
        ->and($skus('active'))->toBe(['INS-061'])
        ->and($skus('expired'))->toBe(['INS-EXP'])
        ->and($skus('none'))->toBe(['INS-NON']);
    expect($none->id)->toBeInt();

    // Ambang dari setting (bukan konstanta).
    setSetting('inventory.registration_reminder_days', 61);
    expect($skus('expiring'))->toEqualCanonicalizing(['INS-001', 'INS-061']);
});

test('BR-152 cabut wajib alasan + audit; izin habis/dicabut tidak bisa diubah; nomor ganda per produk ditolak', function () {
    $r = registration($this->product, '2030-01-01', 'RI-AKTIF');
    $url = "/produk/{$this->product->id}/izin-edar/{$r->id}";

    $this->actingAs($this->user)->post("{$url}/cabut", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->user)->post("{$url}/cabut", ['reason' => 'Dicabut Kementan (uji)'])->assertSessionHasNoErrors();
    expect($r->fresh()->status)->toBe(RegistrationStatus::Revoked)
        ->and($r->fresh()->note)->toBe('Dicabut Kementan (uji)')
        ->and(AuditLog::query()->where('event', 'product_registration.revoked')->count())->toBe(1);

    $this->actingAs($this->user)->put($url, registrationPayload(['registration_no' => 'RI-AKTIF']))->assertSessionHasErrors('registration');
    $this->actingAs($this->user)->post("{$url}/cabut", ['reason' => 'Ulang dicabut'])->assertSessionHasErrors('reason');

    $this->actingAs($this->user)->post("/produk/{$this->product->id}/izin-edar", registrationPayload(['registration_no' => 'RI-AKTIF']))
        ->assertSessionHasErrors('registration_no');
    // Tanggal terbit setelah berlaku sampai ditolak.
    $this->actingAs($this->user)->post("/produk/{$this->product->id}/izin-edar", registrationPayload(['registration_no' => 'RI-X', 'issued_at' => '2031-01-01']))
        ->assertSessionHasErrors('issued_at');
});

test('BR-152 izin yang disimpan dengan valid_until lampau langsung expired', function () {
    $this->actingAs($this->user)->post("/produk/{$this->product->id}/izin-edar", registrationPayload(['valid_until' => '2026-11-15', 'issued_at' => null]))
        ->assertSessionHasNoErrors();
    expect(ProductRegistration::query()->sole()->status)->toBe(RegistrationStatus::Expired);
});

test('SEC-109 berkas izin di disk privat; unduh tanpa tanda tangan/kedaluwarsa 403, bertanda tangan OK; berkas palsu ditolak', function () {
    Storage::fake('local');
    $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    $this->actingAs($this->user)->post("/produk/{$this->product->id}/izin-edar", [
        ...registrationPayload(), 'document' => UploadedFile::fake()->createWithContent('izin.pdf', $pdf),
    ])->assertSessionHasNoErrors();
    $r = ProductRegistration::query()->sole();
    Storage::disk('local')->assertExists($r->document_path);
    expect($r->document_path)->toStartWith('product-registrations/');

    $path = "/izin-edar/{$r->id}/berkas";
    $this->actingAs($this->user)->get($path)->assertForbidden();
    $signed = URL::temporarySignedRoute('admin.product-registrations.document', now()->addMinutes(5), ['registration' => $r->id], false);
    $this->actingAs($this->user)->get($signed)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    CarbonImmutable::setTestNow(now()->addMinutes(6));
    $this->actingAs($this->user)->get($signed)->assertForbidden();

    $fake = UploadedFile::fake()->createWithContent('izin.pdf', '<html><script>alert(1)</script></html>');
    $this->actingAs($this->user)->post("/produk/{$this->product->id}/izin-edar", [...registrationPayload(['registration_no' => 'RI-PALSU']), 'document' => $fake])
        ->assertSessionHasErrors('document');
});

test('06 izin: products.view saja tidak bisa tambah/ubah/cabut; izin produk lain → 404 (IDOR)', function () {
    $r = registration($this->product, '2030-01-01');
    $other = regProduct($this->category, 'INS-002', 'Produk Lain');

    $this->actingAs($this->user)->put("/produk/{$other->id}/izin-edar/{$r->id}", registrationPayload())->assertNotFound();
    $this->actingAs($this->user)->post("/produk/{$other->id}/izin-edar/{$r->id}/cabut", ['reason' => 'Salah produk'])->assertNotFound();

    Gate::define('product_registrations.manage', fn () => false);
    $this->actingAs($this->user)->post("/produk/{$this->product->id}/izin-edar", registrationPayload())->assertForbidden();
    $this->actingAs($this->user)->post("/produk/{$this->product->id}/izin-edar/{$r->id}/cabut", ['reason' => 'Coba cabut'])->assertForbidden();
    $this->actingAs($this->user)->get("/produk/{$this->product->id}?tab=izin")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('can.manage_registrations', false)->has('registrations', 1)->where('registrationState', 'active'));

    Gate::define('products.view', fn () => false);
    $this->actingAs($this->user)->get('/izin-edar')->assertForbidden();
});

test('15 §7.3 daftar izin edar: bawaan izin berlaku ≤ 60 hari, cari nomor/produk, filter status, sort berlaku sampai', function () {
    registration($this->product, '2026-12-01', 'RI-DEKAT');
    registration($this->product, '2027-12-01', 'RI-JAUH');
    $b = regProduct($this->category, 'FUN-001', 'Fungisida Uji');
    registration($b, '2026-11-20', 'RI-FUN');
    registration($b, '2026-10-01', 'RI-HABIS', 'expired');

    $nos = fn (string $query = '') => collect($this->actingAs($this->user)->get("/izin-edar{$query}")->assertOk()->inertiaProps('rows'))
        ->pluck('registration_no')->all();

    expect($nos())->toBe(['RI-FUN', 'RI-DEKAT']);
    expect($nos('?q=fungisida&filter[valid_until]=&filter[status]='))->toBe(['RI-HABIS', 'RI-FUN']);
    expect($nos('?q=RI-J&filter[valid_until]='))->toBe(['RI-JAUH']);
    expect($nos('?filter[status][]=expired&filter[valid_until]='))->toBe(['RI-HABIS']);
    expect($nos('?filter[valid_until]=&sort=-valid_until'))->toBe(['RI-JAUH', 'RI-DEKAT', 'RI-FUN']);
});

test('15 §7.4 dasbor: jumlah produk izin akan habis untuk pemegang products.view', function () {
    registration($this->product, '2026-12-01');
    $user = User::factory()->withTwoFactor()->create()->assignRole('owner');

    $this->actingAs($user)->get('/dasbor')->assertOk()->assertInertia(function (Assert $page) {
        $item = collect($page->toArray()['props']['pending'])->firstWhere('key', 'registrations_expiring');
        expect($item['count'])->toBe(1)->and($item['href'])->toContain('registration_status');
    });
});
