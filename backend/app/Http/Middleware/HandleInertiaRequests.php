<?php

namespace App\Http\Middleware;

use App\Domain\Access\Support\AvatarStore;
use App\Domain\Access\Support\PermissionCatalog;
use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Settings\Branding\BrandTheme;
use App\Models\User;
use App\Support\Menu\MenuBuilder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // Nama & branding dari Pengaturan (T-008), juga untuk tamu di halaman login.
            'name' => setting('general.app_name'),
            'branding' => app(BrandTheme::class)->shared(),
            // Zona tampilan (`general.timezone`): tanggal yang dibentuk di browser memakai zona ini,
            // bukan zona perangkat pengguna.
            'timezone' => setting('general.timezone'),
            'auth' => [
                'user' => self::sharedUser($request->user()),
                // 06 §10: permission & menu sudah difilter server. Menyembunyikan menu hanya UX;
                // setiap route tetap dijaga middleware `can:`.
                'permissions' => fn () => self::permissions($request->user()),
                'menu' => fn () => $request->user() instanceof User ? app(MenuBuilder::class)->for($request->user()) : [],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Hanya field yang dipakai UI (T-017). Model utuh tidak dikirim: kolom seperti
     * `telegram_chat_id`, `last_login_ip`, atau `locked_until` tidak perlu ada di browser.
     *
     * @return array<string, mixed>|null
     */
    private static function sharedUser(?Authenticatable $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'phone' => $user->phone,
            'avatar' => AvatarStore::url($user->avatar_path),
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
        ];
    }

    /**
     * @return list<string>
     */
    private static function permissions(?Authenticatable $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        // Super Admin lolos lewat Gate::before, bukan lewat daftar permission role-nya.
        return $user->hasRole(RoleCatalog::SUPER_ADMIN)
            ? PermissionCatalog::names()
            : array_values($user->getAllPermissions()->map(fn ($permission): string => (string) $permission->name)->sort()->all());
    }
}
