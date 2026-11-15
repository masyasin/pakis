<?php

namespace App\Domain\Attendance\Lookup;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Support\ShiftScope;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `shifts` (05 §4.11): pilihan shift di pola & pengecualian jadwal (T-035). Hanya shift
 * AKTIF dan dalam scope pengguna (06 §5).
 */
final class ShiftLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->can('shifts.view') || $user->can('schedules.view');
    }

    public function query(array $params): Builder
    {
        return Shift::query()->where('is_active', true);
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->prefix('code')->contains('name');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('start_time')->orderBy('name');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof Shift) {
            throw new LogicException('Lookup menerima model selain Shift.');
        }

        return [
            'value' => $model->id,
            'label' => "{$model->code} · {$model->name}",
            'hint' => Shift::hm($model->start_time).'–'.Shift::hm($model->end_time).($model->crosses_midnight ? ' (+1)' : ''),
        ];
    }

    /** Scope berbasis ID shift (06 §5), bukan kolom pegawai. */
    public function scope(Builder $query, User $user): void
    {
        app(ShiftScope::class)->apply($query, $user, $query->qualifyColumn('id'));
    }
}
