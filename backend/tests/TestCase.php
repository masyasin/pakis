<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Test HTTP tidak boleh bergantung pada aset hasil `npm run build`: di CI job Pest tidak
        // membangun frontend, dan di lokal manifest bisa usang. Build diuji terpisah di job frontend.
        $this->withoutVite();

        // Test tidak boleh menghubungi internet (mis. cek password bocor): permintaan HTTP yang
        // tidak di-fake gagal → kode yang fail-open melewatinya, kode lain gagal dengan jelas.
        Http::preventStrayRequests();

        // Kemudahan untuk test yang TIDAK menguji otorisasi: setiap pengguna uji lolos gerbang web
        // (BR-017) tanpa harus diberi role. Test otorisasi (harness matriks, BR-017, scope) memulihkan
        // pengecekan permission sesungguhnya dengan `$this->useRealPermissions()`.
        Gate::define('dashboard.view', fn () => true);

        // Kunci HMAC NIK (SEC-010) khusus test, diturunkan saat runtime: tidak ada literal kunci
        // di repo (gitleaks `pakis-nik-hash-key`) dan CI tidak perlu secret tambahan.
        config(['pakis.nik_hash_key' => hash('sha256', 'pakis-one-test-nik-hash-key')]);
    }

    /** Kembalikan `dashboard.view` ke permission Spatie sesungguhnya (test otorisasi, T-019). */
    protected function useRealPermissions(): void
    {
        Gate::define('dashboard.view', fn ($user) => method_exists($user, 'hasPermissionTo')
            && $user->getAllPermissions()->contains('name', 'dashboard.view'));
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
