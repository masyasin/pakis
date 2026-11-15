<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Access\DataScope;
use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Support\ScopeResolver;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scope data untuk `shifts` (06 §5: "shifts — hanya yang dipakai pegawai di lokasinya"). Scope
 * `all` = semua shift. Scope lain = shift yang dipakai pola jadwal pegawai dalam DataScope
 * pengguna. Sebelum tabel pola ada (T-035) tidak ada shift yang "dipakai", jadi scope sempit tidak
 * melihat shift apa pun (fail-closed, bukan terbuka).
 * TODO(T-035): test dengan tabel `employee_shift_patterns` nyata.
 */
final class ShiftScope
{
    /** @var array<int, list<int>|null> */
    private array $memo = [];

    public function __construct(private readonly DataScope $dataScope) {}

    /** @return list<int>|null null = semua shift */
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
    public function apply(QueryBuilder $query, User $user, string $column = 'shifts.id'): QueryBuilder
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

    /** @return list<int>|null */
    private function compute(User $user): ?array
    {
        if (ScopeResolver::for($user) === Scope::All) {
            return null;
        }

        $employees = $this->dataScope->visibleEmployeeIds($user) ?? [];
        if ($employees === [] || ! Schema::hasTable(ShiftUsage::PATTERN_TABLE)) {
            return [];
        }

        $ids = [];
        foreach (ShiftUsage::PATTERN_COLUMNS as $column) {
            foreach (DB::table(ShiftUsage::PATTERN_TABLE)->whereIn('employee_id', $employees)->whereNotNull($column)->distinct()->pluck($column) as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
