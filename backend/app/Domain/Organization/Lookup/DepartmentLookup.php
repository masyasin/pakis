<?php

namespace App\Domain\Organization\Lookup;

use App\Domain\Organization\Models\Department;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `departments` (05 §4.11): pilihan departemen di filter & form (induk departemen, jabatan,
 * pegawai). Hanya departemen aktif. Tidak ber-scope (06 §5), jadi semua pengguna login boleh memakai.
 * `exclude` menyembunyikan departemen yang sedang diubah dari pilihan induknya (server tetap
 * menolak siklus di DepartmentRequest/UpdateDepartment).
 */
final class DepartmentLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['exclude' => ['nullable', 'integer', 'min:1']];
    }

    public function query(array $params): Builder
    {
        return Department::query()
            ->where('is_active', true)
            ->when(! empty($params['exclude']), fn (Builder $q) => $q->whereKeyNot((int) $params['exclude']));
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
        if (! $model instanceof Department) {
            throw new LogicException('Lookup menerima model selain Department.');
        }

        return ['value' => $model->id, 'label' => $model->name, 'hint' => $model->code];
    }
}
