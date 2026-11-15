<?php

namespace App\Domain\Organization\Lookup;

use App\Domain\Organization\Models\Position;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `positions` (05 §4.11): pilihan jabatan di filter & form (atasan jabatan, penugasan).
 * Hanya jabatan aktif; tidak ber-scope (06 §5). `department` menyaring per departemen, `exclude`
 * menyembunyikan jabatan yang sedang diubah dari pilihan atasannya (server tetap menolak siklus).
 */
final class PositionLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department' => ['nullable', 'integer', 'min:1'],
            'exclude' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function query(array $params): Builder
    {
        return Position::query()
            ->with('department:id,name')
            ->where('is_active', true)
            ->when(! empty($params['department']), fn (Builder $q) => $q->where('department_id', (int) $params['department']))
            ->when(! empty($params['exclude']), fn (Builder $q) => $q->whereKeyNot((int) $params['exclude']));
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->contains('name')->prefix('code');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('level')->orderBy('name');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof Position) {
            throw new LogicException('Lookup menerima model selain Position.');
        }

        return ['value' => $model->id, 'label' => $model->name, 'hint' => $model->department?->name];
    }
}
