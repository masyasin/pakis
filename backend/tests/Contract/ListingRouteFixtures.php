<?php

namespace Tests\Contract;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Data pendukung uji HTTP ListingContractTest per route (10 §3.3).
 */
final class ListingRouteFixtures
{
    /**
     * Parameter untuk route berparameter, mis. `admin.imports.rows.index` butuh `import`.
     *
     * @return array<string, mixed>
     */
    public static function parameters(string $routeName): array
    {
        return match ($routeName) {
            default => [],
        };
    }

    /**
     * Pengguna yang membuka daftar. Web: super_admin; API: pegawai/supervisor dengan perangkat aktif.
     */
    public static function actor(string $routeName): Authenticatable
    {
        // TODO(T-018): super_admin untuk admin.*; TODO(T-031): pengguna + perangkat aktif untuk api.v1.*.
        if (in_array($routeName, ['admin.audit.index', 'admin.security-events.index'], true)) {
            // Ability sementara (deny-by-default) dibuka hanya untuk aktor uji ini.
            Gate::define('audit.view', fn () => true);
        }
        if ($routeName === 'admin.sequences.index') {
            Gate::define('sequences.view', fn () => true);
        }

        return match ($routeName) {
            default => User::factory()->create(),
        };
    }

    /**
     * Nilai contoh yang PASTI valid untuk filter yang tidak bisa ditebak dari spec (filter relasi).
     *
     * @return array<string, mixed> filterKey => nilai
     */
    public static function filterSamples(string $routeName): array
    {
        return match ($routeName) {
            default => [],
        };
    }
}
