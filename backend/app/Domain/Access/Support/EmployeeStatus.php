<?php

namespace App\Domain\Access\Support;

use App\Models\User;

/**
 * Apakah pegawai terkait akun masih aktif (BR-010: login sah bila tidak punya pegawai ATAU
 * pegawainya `active`). Kontrak terpisah karena tabel pegawai baru ada di T-024.
 */
interface EmployeeStatus
{
    public function isActive(User $user): bool;
}
