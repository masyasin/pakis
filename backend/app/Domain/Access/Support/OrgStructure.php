<?php

namespace App\Domain\Access\Support;

/**
 * Struktur organisasi yang dibutuhkan DataScope (06 §5). Dipisah dari query tabel agar aturan
 * scope bisa diuji tanpa tabel pegawai (T-019); implementasi tabel: DatabaseOrgStructure.
 * Semua "penugasan" di sini = penugasan AKTIF hari ini (tanggal lokal).
 */
interface OrgStructure
{
    /** Lokasi penugasan utama aktif pegawai, atau null. */
    public function primaryLocationOf(int $employeeId): ?int;

    /**
     * Lokasi yang dikepalai pegawai (`locations.head_employee_id`).
     *
     * @return list<int>
     */
    public function locationsHeadedBy(int $employeeId): array;

    /**
     * Pegawai yang penugasan utama aktifnya di salah satu lokasi ini.
     *
     * @param  list<int>  $locationIds
     * @return list<int>
     */
    public function employeesAtLocations(array $locationIds): array;

    /**
     * Bawahan langsung (lewat `assignments.supervisor_employee_id` aktif) dari pegawai-pegawai ini.
     *
     * @param  list<int>  $employeeIds
     * @return list<int>
     */
    public function directReportsOf(array $employeeIds): array;
}
