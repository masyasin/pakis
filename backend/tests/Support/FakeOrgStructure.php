<?php

namespace Tests\Support;

use App\Domain\Access\Support\EmployeeStatus;
use App\Domain\Access\Support\OrgStructure;
use App\Models\User;

/**
 * Struktur organisasi palsu untuk test scope & IDOR (T-019), selama tabel pegawai/penugasan
 * belum ada. Pemakaian:
 *
 *     $org = FakeOrgStructure::bind();
 *     $org->employee(1)->at(10);              // pegawai 1 bertugas di lokasi 10
 *     $org->employee(2)->at(10)->reportsTo(1); // atasan pegawai 2 = pegawai 1
 *     $org->employee(1)->heads(20);           // pegawai 1 mengepalai lokasi 20
 */
final class FakeOrgStructure implements OrgStructure
{
    /** @var array<int, int> pegawai → lokasi utama */
    private array $locations = [];

    /** @var array<int, int> pegawai → atasan */
    private array $supervisors = [];

    /** @var array<int, list<int>> pegawai → lokasi yang dikepalai */
    private array $heads = [];

    private ?int $current = null;

    public static function bind(): self
    {
        $fake = new self;
        app()->instance(OrgStructure::class, $fake);

        // Tabel `employees` belum ada (T-024): status pegawai fail-closed akan mengeluarkan akun
        // yang menunjuk pegawai. Di test scope, anggap semua pegawai aktif.
        app()->instance(EmployeeStatus::class, new class implements EmployeeStatus
        {
            public function isActive(User $user): bool
            {
                return true;
            }
        });

        return $fake;
    }

    public function employee(int $id): self
    {
        $this->current = $id;

        return $this;
    }

    public function at(int $locationId): self
    {
        $this->locations[(int) $this->current] = $locationId;

        return $this;
    }

    public function reportsTo(int $supervisorId): self
    {
        $this->supervisors[(int) $this->current] = $supervisorId;

        return $this;
    }

    public function heads(int $locationId): self
    {
        $this->heads[(int) $this->current][] = $locationId;

        return $this;
    }

    public function primaryLocationOf(int $employeeId): ?int
    {
        return $this->locations[$employeeId] ?? null;
    }

    public function locationsHeadedBy(int $employeeId): array
    {
        return $this->heads[$employeeId] ?? [];
    }

    public function employeesAtLocations(array $locationIds): array
    {
        return array_values(array_keys(array_filter($this->locations, fn (int $loc) => in_array($loc, $locationIds, true))));
    }

    public function directReportsOf(array $employeeIds): array
    {
        return array_values(array_keys(array_filter($this->supervisors, fn (int $sup) => in_array($sup, $employeeIds, true))));
    }
}
