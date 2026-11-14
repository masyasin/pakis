<?php

namespace App\Providers;

use App\Domain\Access\Actions\AttemptLogin;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\Http\ClientNetwork;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\LoginRequest as FortifyLoginRequest;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Controller Fortify meminta LoginRequest miliknya; arahkan ke versi tanpa "ingat saya" (SEC-005).
        $this->app->bind(FortifyLoginRequest::class, LoginRequest::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        // Login username/email + lockout + gerbang web (BR-010, BR-016, BR-017, SEC-001).
        Fortify::authenticateUsing(fn (Request $request) => app(AttemptLogin::class)->handle($request));

        // Field login Fortify bernama `login` (bukan kolom DB): konfirmasi password cukup mencocokkan
        // hash milik pengguna yang sedang masuk. Tanpa ini Fortify mencari kolom `login` → galat 500.
        Fortify::confirmPasswordsUsing(fn (User $user, ?string $password): bool => is_string($password) && Hash::check($password, $user->password));
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => true,
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // BR-016: maksimal 30 percobaan login per menit per IP (lapisan kasar). Lockout per
        // identifier + IP (`security.login_max_attempts`) ada di RecordLoginFailure.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(30)->by(ClientNetwork::key($request->ip())));
    }
}
