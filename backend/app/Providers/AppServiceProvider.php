<?php

namespace App\Providers;

use App\Domain\Access\DataScope;
use App\Domain\Access\Listeners\ConfirmPasswordForMfaSetup;
use App\Domain\Access\Listeners\RecordLogout;
use App\Domain\Access\Listeners\RecordSuccessfulLogin;
use App\Domain\Access\Listeners\RecordTwoFactorEvents;
use App\Domain\Access\Lookup\RoleLookup;
use App\Domain\Access\Lookup\UserLookup;
use App\Domain\Access\Policies\UserPolicy;
use App\Domain\Access\Rules\PasswordPolicy;
use App\Domain\Access\Support\ActiveEmployeeStatus;
use App\Domain\Access\Support\DatabaseOrgStructure;
use App\Domain\Access\Support\EmployeeStatus;
use App\Domain\Access\Support\OrgStructure;
use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Audit\Listeners\RecordExportCreated;
use App\Domain\Notification\Otp\OtpManager;
use App\Domain\Reference\Lookup\ReferenceLookup;
use App\Domain\Region\Lookup\RegionLookup;
use App\Domain\Settings\Branding\BrandTheme;
use App\Domain\Settings\SettingStore;
use App\Models\User;
use App\Support\Auth\PasswordConfirmation;
use App\Support\Export\Events\ExportCreated;
use App\Support\Lookup\LookupRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
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

        // Scope data (06 §5, T-019). DataScope di-memo per request (scoped), jadi struktur
        // organisasi dihitung sekali per pengguna per request.
        $this->app->bind(OrgStructure::class, DatabaseOrgStructure::class);
        $this->app->scoped(DataScope::class);

        // Status pegawai untuk login (BR-010); TODO(T-024): implementasi berbasis model Employee.
        $this->app->bind(EmployeeStatus::class, ActiveEmployeeStatus::class);

        // Sumber lookup (05 §4.11). Modul baru menambahkan sumbernya di sini.
        $this->app->singleton(LookupRegistry::class, function () {
            $registry = new LookupRegistry;
            $registry->register('references', ReferenceLookup::class);
            $registry->register('users', UserLookup::class);
            $registry->register('roles', RoleLookup::class);
            $registry->register('regions', RegionLookup::class);

            return $registry;
        });

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
        $this->configureAuthorization();

        // Branding di dokumen HTML awal (judul, favicon, override warna ber-nonce) — T-008.
        View::composer('app', fn ($view) => $view->with('brand', app(BrandTheme::class)->forView()));

        // Alur konfirmasi Fortify (status "masih terkonfirmasi") membaca config ini.
        config(['auth.password_timeout' => PasswordConfirmation::timeoutSeconds()]);

        // Listener di app/Domain tidak ikut auto-discovery (hanya app/Listeners).
        Event::listen(ExportCreated::class, RecordExportCreated::class);
        Event::listen(Login::class, RecordSuccessfulLogin::class);
        Event::listen(Login::class, ConfirmPasswordForMfaSetup::class);
        Event::listen(Logout::class, RecordLogout::class);
        // Event MFA Fortify → security_events + izin sekali-lihat kode pemulihan (T-016).
        Event::subscribe(RecordTwoFactorEvents::class);

        // Driver OTP `log` di produksi = kode OTP tertulis di log → gagal start, jangan diam-diam (T-015).
        OtpManager::assertSafeForEnvironment();
        $this->app->singleton(OtpManager::class);

        // Cookie sesi ikut batas idle dari setting (BR-018); penegakan di EnforceSessionLifetime.
        config(['session.lifetime' => (int) setting('security.session_idle_minutes')]);
    }

    /**
     * Ability atas akun pengguna lain yang dilindungi Owner (SEC-009): TIDAK ikut "Super Admin
     * lolos semua" — UserPolicy yang memutuskan, sehingga Super Admin tidak bisa mengubah Owner.
     */
    public const OWNER_PROTECTED = ['users.assign-roles', 'users.reset-mfa-of'];

    /**
     * Super Admin (vendor) lolos semua permission (06 §2), kecuali OWNER_PROTECTED. Pengguna lain:
     * Spatie menjawab dari permission role-nya (mengembalikan null bila tidak punya, sehingga
     * ability yang didefinisikan biasa tetap berlaku). Role `owner`/`super_admin` sendiri terkunci
     * penuh di SaveRole.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(fn (User $user, string $ability): ?bool => ! in_array($ability, self::OWNER_PROTECTED, true)
            && $user->hasRole(RoleCatalog::SUPER_ADMIN) ? true : null);

        Gate::define('users.assign-roles', [UserPolicy::class, 'assignRoles']);
        Gate::define('users.reset-mfa-of', [UserPolicy::class, 'resetMfaOf']);
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

        // BR-011: tanpa aturan komposisi; panjang dari setting. Aturan lengkap (username, password
        // lama, cek bocor) ada di PasswordPolicy — pakai itu untuk setiap form password.
        Password::defaults(fn (): Password => Password::min(PasswordPolicy::minLength())->max(PasswordPolicy::MAX_LENGTH));
    }
}
