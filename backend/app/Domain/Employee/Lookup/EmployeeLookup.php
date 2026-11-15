<?php

namespace App\Domain\Employee\Lookup;

use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `employees` (05 §4.11): pilihan pegawai di form & filter (atasan penugasan, kepala
 * lokasi, penautan akun di form Pengguna, filter penyetuju). Hanya pegawai AKTIF dan dalam scope
 * data pemanggil (`scopeColumn`). Param:
 * - `without_account` : hanya pegawai yang belum punya akun (termasuk akun terhapus, karena
 *                       `users.employee_id` unik juga atas baris terhapus);
 * - `exclude`         : sembunyikan satu pegawai (mis. pegawai itu sendiri dari pilihan atasannya).
 */
final class EmployeeLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        // `schedules.manage`: pemilih pegawai di pengecualian jadwal (T-035); hasil tetap ber-scope.
        return $user->canAny(['employees.view', 'users.create', 'users.update', 'locations.manage', 'schedules.manage']);
    }

    public function rules(): array
    {
        return [
            'without_account' => ['nullable', 'boolean'],
            'exclude' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function query(array $params): Builder
    {
        return Employee::query()
            ->with('primaryAssignment.position:id,name')
            ->where('status', 'active')
            ->when(filter_var($params['without_account'] ?? false, FILTER_VALIDATE_BOOLEAN), fn (Builder $q) => $q->whereNotExists(
                fn ($sub) => $sub->selectRaw('1')->from('users')->whereColumn('users.employee_id', 'employees.id'),
            ))
            ->when(! empty($params['exclude']), fn (Builder $q) => $q->whereKeyNot((int) $params['exclude']));
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->contains('full_name')->prefix('employee_number');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('full_name');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof Employee) {
            throw new LogicException('Lookup menerima model selain Employee.');
        }

        $position = $model->primaryAssignment?->position?->name;

        return [
            'value' => $model->id,
            'label' => $model->full_name,
            'hint' => $position !== null ? "{$model->employee_number} · {$position}" : $model->employee_number,
        ];
    }

    public function scopeColumn(): string
    {
        return 'employees.id';
    }
}
