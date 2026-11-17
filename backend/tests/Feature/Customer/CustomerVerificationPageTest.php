<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CustomerWorld;

/*
 * T-074 bagian 3a — halaman web verifikasi kios: antrean `customer-verifications/index` dan tab
 * Verifikasi detail kios benar-benar ada (ensure_pages_exist aktif) dengan prop yang dipakai UI.
 * Aturan bisnisnya diuji di CustomerVerificationTest. Data fiktif.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Jakarta'));
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, SequenceSeeder::class, ApprovalRuleSeeder::class]);
    $this->useRealPermissions();
    config(['inertia.testing.ensure_pages_exist' => true]);

    $this->area = CustomerWorld::area('AR-KDS', ['name' => 'Kudus']);
    $this->customer = CustomerWorld::customer($this->area, 'Toko Tani Makmur', ['owner_phone' => '6281234567001']);
});

test('BR-165 antrean verifikasi kios merender halaman customer-verifications/index untuk pemegang customers.verify', function () {
    $user = User::factory()->withTwoFactor()->create()->assignRole('admin_penjualan');

    $this->actingAs($user)->get('/pelanggan/verifikasi')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('customer-verifications/index')
            ->has('rows')
            ->has('listing.spec')
            ->has('phoneMasked')
            ->where('listing.spec.key', 'customer_verifications'));
});

test('BR-165 tab Verifikasi detail kios menerima keadaan verifikasi untuk form kirim', function () {
    $user = User::factory()->withTwoFactor()->create()->assignRole('admin_penjualan');

    $this->actingAs($user)->get("/pelanggan/{$this->customer->id}?tab=verifikasi")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('customers/show')
            ->where('verification.can_submit', true)
            ->where('verification.pending', null)
            ->where('verification.office_call_draft', null)
            ->has('verification.otp_available')
            ->has('verification.otp_length')
            ->has('verification.max_accuracy_m')
            ->has('verification.history', 0));
});
