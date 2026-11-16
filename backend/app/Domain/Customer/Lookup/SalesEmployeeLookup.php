<?php

namespace App\Domain\Customer\Lookup;

use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `sales_employees` (05 §4.11, T-073): pemilih sales/supervisor di form kios, pengalihan,
 * dialog area, dan filter daftarnya. Pegawai AKTIF dalam scope data pemanggil (`scopeColumn`).
 * Sumber terpisah agar `employees` (pemegang `employees.view` dkk.) tidak dilonggarkan; izin `.view`
 * ikut dibuka untuk filter daftar (PA-47) — hasilnya tetap ber-scope.
 */
final class SalesEmployeeLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->canAny(['customers.view', 'customers.manage', 'sales_areas.view', 'sales_areas.manage']);
    }

    public function query(array $params): Builder
    {
        return Employee::query()->with('primaryAssignment.position:id,name')->where('status', 'active');
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
