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
 * - `location` : pegawai bertugas (utama/tambahan) di lokasi tugas pengguna (utama/tambahan) + lokasi
 *                 yang ia kepalai (BR-051: rangkap berlaku dua arah).
 * - `team`     : diri + bawahan langsung & tidak langsung, maksimal 5 tingkat.
 * - `self`     : diri sendiri.
 * Pengguna tanpa pegawai dengan scope selain `all` tidak melihat data apa pun.
 *
 * `team` dihitung per tingkat (≤ 5 query kecil) alih-alih CTE rekursif: hasilnya sama, aman dari
 * struktur atasan melingkar, dan bisa diuji tanpa tabel (keputusan teknis T-019, dicatat di 12).
 * Hasil di-memo per pengguna (instance scoped per request).
 *
 * Dimensi **stok** (15 §6.2, ADR-106, T-068) memakai definisi lokasi yang sama (PA-13) tetapi pada
 * ID LOKASI, bukan ID pegawai: lihat `forStock()` dan `forStockByLocation()`.
 */
final class DataScope
{
    public const TEAM_MAX_DEPTH = 5;

    /** @var array<int, list<int>|null> */
    private array $memo = [];

    /** @var array<int, array{0: Scope, 1: list<int>|null}> scope efektif + lokasi dasar dimensi stok */
    private array $stockMemo = [];

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

    /**
     * Batasi query ke lokasi stok dalam scope pengguna (dimensi Stok, 15 §6.2). `$stockLocationColumn`
     * = kolom ID lokasi stok dari KODE, mis. `stock_locations.id` atau `stock_batches.stock_location_id`.
     *
     * - `all` & `team`: semua lokasi stok. `team` sengaja disamakan dengan `all` sampai Paket C
     *   memberi arti "kendaraan anggota tim" (keputusan Mas, PA-43): supervisor hanya MELIHAT master.
     * - `location`: lokasi stok yang `location_id`-nya termasuk lokasi pengguna menurut Fase 0
     *   (utama + rangkap + dikepalai), ditambah lokasi sistem TRANSIT.
     * - `self`: tidak ada (Paket C: kendaraan yang dipegang).
     *
     * @template TBuilder of QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function forStock(QueryBuilder $query, User $user, string $stockLocationColumn): QueryBuilder
    {
        self::assertColumn($stockLocationColumn);
        [$scope, $locations] = $this->stockBase($user);

        if ($locations === null) {
            return $query;
        }

        if ($scope === Scope::Self) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        // Subquery (bukan daftar ID ter-memo): lokasi stok baru yang dibuat di request yang sama
        // langsung ikut, dan lokasi stok kendaraan mengikuti `location_id` = lokasi asal kendaraannya.
        $query->whereIn($stockLocationColumn, fn ($sub) => $sub->select('id')->from('stock_locations')
            ->where(fn ($w) => $w->where('type', 'in_transit')
                ->when($locations !== [], fn ($o) => $o->orWhereIn('location_id', $locations))));

        return $query;
    }

    /**
     * Batasi query berdasarkan kolom LOKASI Fase 0 memakai definisi dimensi Stok, mis. kendaraan
     * menurut `vehicles.home_location_id` (15 §7.3 `vehicles`). Baris tanpa lokasi hanya terlihat
     * oleh scope `all`/`team`.
     *
     * @template TBuilder of QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function forStockByLocation(QueryBuilder $query, User $user, string $locationColumn): QueryBuilder
    {
        self::assertColumn($locationColumn);
        [$scope, $locations] = $this->stockBase($user);

        if ($locations === null) {
            return $query;
        }

        if ($scope === Scope::Self || $locations === []) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        $query->whereIn($locationColumn, $locations);

        return $query;
    }

    /**
     * Lokasi Fase 0 dasar dimensi Stok; null = semua. Dipakai validasi pilihan lokasi di form
     * (pengguna tidak bisa membuat lokasi stok yang lalu tidak bisa ia lihat).
     *
     * @return list<int>|null
     */
    public function stockLocationIds(User $user): ?array
    {
        [$scope, $locations] = $this->stockBase($user);

        return $scope === Scope::Self ? [] : $locations;
    }

    public function allowsStockLocation(User $user, ?int $locationId): bool
    {
        $ids = $this->stockLocationIds($user);

        return $ids === null || ($locationId !== null && in_array($locationId, $ids, true));
    }

    /** @return array{0: Scope, 1: list<int>|null} */
    private function stockBase(User $user): array
    {
        if (array_key_exists($user->id, $this->stockMemo)) {
            return $this->stockMemo[$user->id];
        }

        $scope = ScopeResolver::for($user);
        $self = $user->employee_id;

        $locations = match (true) {
            $scope === Scope::All, $scope === Scope::Team => null,
            $scope === Scope::Self, $self === null => [],
            default => array_values(array_unique([...$this->org->locationsHeadedBy($self), ...$this->org->assignedLocationsOf($self)])),
        };

        return $this->stockMemo[$user->id] = [$scope, $locations];
    }

    private static function assertColumn(string $column): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $column) !== 1) {
            throw new InvalidArgumentException("Nama kolom scope tidak sah: {$column}");
        }
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
        $locations = [...$this->org->locationsHeadedBy($self), ...$this->org->assignedLocationsOf($self)];

        $ids = $locations === [] ? [] : $this->org->employeesAtLocations(array_values(array_unique($locations)));

        // Diri sendiri selalu terlihat, termasuk saat belum punya penugasan aktif.
        return array_values(array_unique([$self, ...$ids]));
    }
}
