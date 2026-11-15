<?php

namespace Database\Factories;

use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Enums\Gender;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\NikCipher;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Pegawai uji: data fiktif saja (CLAUDE.md §6). NIK default kosong; `withNik()` membuat NIK
 * acak saat test berjalan (tidak pernah ditulis literal di kode).
 *
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'full_name' => fake('id_ID')->name(),
            'gender' => fake()->randomElement(Gender::cases()),
            'employment_status_id' => fn () => self::employmentStatusId(),
            'join_date' => now()->subYear()->toDateString(),
        ];
    }

    public function configure(): static
    {
        // Kolom yang tidak fillable (SEC-034) diisi lewat forceFill, seperti CreateEmployee.
        return $this->afterMaking(function (Employee $employee) {
            $employee->forceFill([
                'employee_number' => $employee->employee_number ?? 'TST-'.strtoupper(Str::random(8)),
                'status' => $employee->getAttributes()['status'] ?? EmployeeStatus::Active,
            ]);
        });
    }

    public function withNik(?string $nik = null): static
    {
        return $this->afterMaking(function (Employee $employee) use ($nik) {
            $nik ??= fake()->numerify('99##############');
            $cipher = app(NikCipher::class);
            $employee->forceFill(['nik_enc' => $cipher->encrypt($nik), 'nik_hash' => $cipher->hash($nik)]);
        });
    }

    public function inactive(): static
    {
        return $this->afterMaking(fn (Employee $employee) => $employee->forceFill([
            'status' => EmployeeStatus::Inactive,
            'exit_date' => now()->toDateString(),
        ]));
    }

    /** Penugasan utama aktif di lokasi/jabatan ini (dibuat setelah pegawai disimpan). */
    public function assignedTo(Location $location, Position $position, ?Employee $supervisor = null): static
    {
        return $this->afterCreating(fn (Employee $employee) => Assignment::query()->create([
            'employee_id' => $employee->id,
            'position_id' => $position->id,
            'location_id' => $location->id,
            'supervisor_employee_id' => $supervisor?->id,
            'is_primary' => true,
            'start_date' => $employee->join_date->toDateString(),
        ]));
    }

    private static function employmentStatusId(): int
    {
        return ReferenceItem::query()->forGroup(ReferenceGroup::EmploymentStatus)->where('code', 'permanent')->value('id')
            ?? ReferenceItem::query()->create([
                'group_code' => ReferenceGroup::EmploymentStatus->value,
                'code' => 'permanent',
                'name' => 'Tetap',
                'sort_order' => 1,
                'is_active' => true,
                'is_system' => false,
            ])->id;
    }
}
