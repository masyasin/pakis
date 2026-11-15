<?php

namespace App\Domain\Organization\Lookup;

use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Support\LocationScope;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `locations` (05 §4.11): pilihan lokasi di filter & form (penugasan, jadwal, hari libur).
 * Hanya lokasi AKTIF (BR-041: lokasi nonaktif tidak bisa dipilih) dan dalam scope pengguna.
 * Semua pengguna login boleh memakai: daftar yang terlihat sudah dibatasi scope.
 */
final class LocationLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return true;
    }

    public function query(array $params): Builder
    {
        return Location::query()->where('is_active', true);
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->contains('name')->prefix('code');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('name');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof Location) {
            throw new LogicException('Lookup menerima model selain Location.');
        }

        return ['value' => $model->id, 'label' => $model->name, 'hint' => $model->code];
    }

    /** Scope berbasis ID lokasi (06 §5), bukan kolom pegawai. */
    public function scope(Builder $query, User $user): void
    {
        app(LocationScope::class)->apply($query, $user, $query->qualifyColumn('id'));
    }
}
