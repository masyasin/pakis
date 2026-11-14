<?php

namespace Tests\Support;

use App\Models\User;
use App\Support\Authorization\ScopedRouteBinding;

/**
 * Model uji untuk ScopedRouteBinding selama tabel pegawai belum ada (T-019): baris `users`
 * dianggap milik pegawai `users.employee_id`.
 */
class ScopedUserProbe extends User
{
    use ScopedRouteBinding;

    protected $table = 'users';

    public static function scopedEmployeeColumn(): string
    {
        return 'employee_id';
    }
}
