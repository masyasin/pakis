<?php

namespace App\Providers;

use App\Domain\Audit\Listeners\RecordExportCreated;
use App\Domain\Settings\Branding\BrandTheme;
use App\Domain\Settings\SettingStore;
use App\Models\User;
use App\Support\Auth\PasswordConfirmation;
use App\Support\Export\Events\ExportCreated;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: direset tiap request & tiap job antrean, jadi nilai setting tidak basi di worker.
        $this->app->scoped(SettingStore::class);

        // RequirePassword dibuat per request dengan masa berlaku dari setting (bukan 3 jam bawaan).
        $this->app->bind(RequirePassword::class, fn ($app) => new RequirePassword(
            $app->make(ResponseFactory::class),
            $app->make(UrlGenerator::class),
            PasswordConfirmation::timeoutSeconds(),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTemporaryAbilities();

        // Branding di dokumen HTML awal (judul, favicon, override warna ber-nonce) — T-008.
        View::composer('app', fn ($view) => $view->with('brand', app(BrandTheme::class)->forView()));

        // Alur konfirmasi Fortify (status "masih terkonfirmasi") membaca config ini.
        config(['auth.password_timeout' => PasswordConfirmation::timeoutSeconds()]);

        // Listener di app/Domain tidak ikut auto-discovery (hanya app/Listeners).
        Event::listen(ExportCreated::class, RecordExportCreated::class);
    }

    /**
     * Ability sementara sampai permission Spatie ada (T-018). DENY-BY-DEFAULT: hanya environment
     * `local` yang diizinkan, untuk mencoba halaman di mesin developer. Staging, produksi, dan test
     * ditolak; test memberi izin eksplisit dengan Gate::define.
     */
    protected function configureTemporaryAbilities(): void
    {
        // TODO(T-018): hapus; `can:audit.view` dijawab permission Spatie lewat Gate::before.
        foreach (['audit.view', 'audit.export', 'settings.view', 'settings.update', 'sequences.view', 'sequences.update'] as $ability) {
            Gate::define($ability, fn (User $user): bool => app()->isLocal());
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
