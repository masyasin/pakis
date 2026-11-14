<?php

namespace App\Domain\Access;

use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Support\OrgStructure;
use App\Domain\Access\Support\ScopeResolver;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * Pembatas data per scope (FR-018, BR-032, 06 §5). Dipakai setiap *Listing, Policy view/update,
 * lookup ber-scope, dan route-model binding (ScopedRouteBinding) → data di luar scope = 404.
 *
 * - `all`      : semua pegawai.
 * - `location` : pegawai berpenugasan utama aktif di lokasi tugas pengguna + lokasi yang ia kepalai.
 * - `team`     : diri + bawahan langsung & tidak langsung, maksimal 5 tingkat.
 * - `self`     : diri sendiri.
 * Pengguna tanpa pegawai dengan scope selain `all` tidak melihat data apa pun.
 *
 * `team` dihitung per tingkat (≤ 5 query kecil) alih-alih CTE rekursif: hasilnya sama, aman dari
 * struktur atasan melingkar, dan bisa diuji tanpa tabel (keputusan teknis T-019, dicatat di 12).
 * Hasil di-memo per pengguna (instance scoped per request).
 */
final class DataScope
{
    public const TEAM_MAX_DEPTH = 5;

    /** @var array<int, list<int>|null> */
    private array $memo = [];

    public function __construct(private readonly OrgStructure $org) {}

    /**
     * ID pegawai yang terlihat oleh pengguna; null = semua (scope `all`).
     *
     * @return list<int>|null
     */
    public function visibleEmployeeIds(User $user): ?array
    {
        if (array_key_exists($user->id, $this->memo)) {
            return $this->memo[$user->id];
        }

        return $this->memo[$user->id] = $this->compute($user);
    }

    /**
     * Batasi query ke pegawai dalam scope. `$employeeColumn` adalah nama kolom dari KODE
     * (mis. `employees.id`, `attendance_days.employee_id`), bukan dari input pengguna.
     *
     * @template TBuilder of QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function apply(QueryBuilder $query, User $user, string $employeeColumn): QueryBuilder
    {
        if (preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $employeeColumn) !== 1) {
            throw new InvalidArgumentException("Nama kolom scope tidak sah: {$employeeColumn}");
        }

        $ids = $this->visibleEmployeeIds($user);

        if ($ids === null) {
            return $query;
        }

        if ($ids === []) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        $query->whereIn($employeeColumn, $ids);

        return $query;
    }

    /** Untuk Policy: apakah pegawai ini dalam scope pengguna? Pegawai null hanya terlihat oleh `all`. */
    public function allows(User $user, ?int $employeeId): bool
    {
        $ids = $this->visibleEmployeeIds($user);

        return $ids === null || ($employeeId !== null && in_array($employeeId, $ids, true));
    }

    /** @return list<int>|null */
    private function compute(User $user): ?array
    {
        $scope = ScopeResolver::for($user);

        if ($scope === Scope::All) {
            return null;
        }

        $self = $user->employee_id;
        if ($self === null) {
            return [];
        }

        return match ($scope) {
            Scope::Self => [$self],
            Scope::Team => $this->team($self),
            Scope::Location => $this->location($self),
        };
    }

    /** @return list<int> */
    private function team(int $self): array
    {
        $seen = [$self => true];
        $level = [$self];

        for ($depth = 0; $depth < self::TEAM_MAX_DEPTH && $level !== []; $depth++) {
            $next = [];
            foreach ($this->org->directReportsOf($level) as $id) {
                // Struktur atasan melingkar (salah input) tidak boleh membuat loop.
                if (! isset($seen[$id])) {
                    $seen[$id] = true;
                    $next[] = $id;
                }
            }
            $level = $next;
        }

        return array_keys($seen);
    }

    /** @return list<int> */
    private function location(int $self): array
    {
        $locations = $this->org->locationsHeadedBy($self);
        $own = $this->org->primaryLocationOf($self);
        if ($own !== null) {
            $locations[] = $own;
        }

        $ids = $locations === [] ? [] : $this->org->employeesAtLocations(array_values(array_unique($locations)));

        // Diri sendiri selalu terlihat, termasuk saat belum punya penugasan aktif.
        return array_values(array_unique([$self, ...$ids]));
    }
}
