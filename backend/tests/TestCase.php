<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Test HTTP tidak boleh bergantung pada aset hasil `npm run build`: di CI job Pest tidak
        // membangun frontend, dan di lokal manifest bisa usang. Build diuji terpisah di job frontend.
        $this->withoutVite();

        // BR-017 memeriksa permission menu web. Sampai role/permission nyata ada (T-018), semua
        // pengguna uji dianggap punya menu Dasbor; test BR-017 mencabutnya dengan Gate::define.
        // TODO(T-018): ganti dengan role/permission dari seeder.
        Gate::define('dashboard.view', fn () => true);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
