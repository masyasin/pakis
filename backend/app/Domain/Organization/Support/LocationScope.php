<?php

namespace App\Domain\Organization\Support;

use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Support\OrgStructure;
use App\Domain\Access\Support\ScopeResolver;
use App\Domain\Organization\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;

/**
 * Scope data untuk tabel `locations` (06 §5: "locations — hanya lokasinya"). DataScope (T-019)
 * menyaring per ID PEGAWAI; lokasi butuh ID LOKASI: lokasi penugasan utama pegawai pengguna +
 * lokasi yang ia kepalai (`head_employee_id`). Scope `all` = semua; pengguna tanpa pegawai dengan
 * scope selain `all` tidak melihat lokasi apa pun (sama dengan DataScope).
 */
final class LocationScope
{
    /** @var array<int, list<int>|null> */
    private array $memo = [];

    public function __construct(private readonly OrgStructure $org) {}

    /** @return list<int>|null null = semua lokasi */
    public function visibleIds(User $user): ?array
    {
        if (array_key_exists($user->id, $this->memo)) {
            return $this->memo[$user->id];
        }

        return $this->memo[$user->id] = $this->compute($user);
    }

    /**
     * @template TBuilder of QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function apply(QueryBuilder $query, User $user, string $column = 'locations.id'): QueryBuilder
    {
        $ids = $this->visibleIds($user);

        if ($ids === null) {
            return $query;
        }

        if ($ids === []) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        $query->whereIn($column, $ids);

        return $query;
    }

    public function allows(User $user, Location $location): bool
    {
        $ids = $this->visibleIds($user);

        return $ids === null || in_array($location->id, $ids, true);
    }

    /** @return list<int>|null */
    private function compute(User $user): ?array
    {
        if (ScopeResolver::for($user) === Scope::All) {
            return null;
        }

        $self = $user->employee_id;
        if ($self === null) {
            return [];
        }

        // Lokasi tugas utama + tambahan (rangkap, BR-051) + lokasi yang dikepalai.
        return array_values(array_unique([...$this->org->locationsHeadedBy($self), ...$this->org->assignedLocationsOf($self)]));
    }
}
