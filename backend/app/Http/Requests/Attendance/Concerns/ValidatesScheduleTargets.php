<?php

namespace App\Http\Requests\Attendance\Concerns;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Support\ShiftScope;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Illuminate\Validation\Validator;

/**
 * Validasi bersama pola & pengecualian jadwal (T-035): pegawai sasaran harus aktif dan dalam scope
 * data pelaku (06 §5), shift harus aktif dan terlihat oleh pelaku (ShiftScope). Satu pegawai di
 * luar scope membatalkan seluruh permintaan dengan pesan umum yang tidak menyebut ID mana (SEC-033):
 * pesan per-ID akan membocorkan keberadaan pegawai di luar wewenang.
 */
trait ValidatesScheduleTargets
{
    /**
     * Batas teknis jumlah pegawai per permintaan (ukuran payload & lama transaksi), bukan parameter
     * bisnis. Sama dengan batas pilihan "semua hasil filter" di halaman Jadwal.
     */
    public const MAX_EMPLOYEES = 500;

    /** @return array<string, list<string>> */
    protected function employeeRules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_EMPLOYEES],
            'employee_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    protected function employeeMessages(): array
    {
        return [
            'employee_ids.required' => 'Pilih minimal satu pegawai.',
            'employee_ids.min' => 'Pilih minimal satu pegawai.',
            'employee_ids.max' => 'Paling banyak '.self::MAX_EMPLOYEES.' pegawai sekali simpan. Persempit filter lalu ulangi.',
        ];
    }

    /** @return list<int> */
    public function employeeIds(): array
    {
        $ids = $this->input('employee_ids', []);

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }

    protected function checkEmployees(Validator $validator): void
    {
        $ids = $this->employeeIds();
        $query = Employee::query()->whereKey($ids)->where('status', EmployeeStatus::Active->value);
        $visible = app(DataScope::class)->apply($query, $this->actor(), 'employees.id')->count();

        if ($visible !== count($ids)) {
            $validator->errors()->add('employee_ids', 'Ada pegawai yang tidak ditemukan, sudah nonaktif, atau di luar wewenang Anda.');
        }
    }

    /**
     * @param  list<int>  $shiftIds  shift terpilih (tanpa null/libur)
     */
    protected function checkShifts(Validator $validator, array $shiftIds, string $attribute): void
    {
        $shiftIds = array_values(array_unique($shiftIds));
        if ($shiftIds === []) {
            return;
        }

        $query = Shift::query()->whereKey($shiftIds)->where('is_active', true);
        $found = app(ShiftScope::class)->apply($query, $this->actor(), 'shifts.id')->count();

        if ($found !== count($shiftIds)) {
            $validator->errors()->add($attribute, 'Shift tidak ditemukan atau sudah nonaktif.');
        }
    }

    protected function actor(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
