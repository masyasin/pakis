<?php

namespace App\Domain\Access\Support;

use App\Models\User;
use App\Support\Menu\MenuBuilder;

/**
 * Gerbang web admin (BR-017, 06 §8): pengguna boleh masuk web bila punya SALAH SATU permission
 * yang membuka menu. Akun dengan role `pegawai` saja hanya untuk aplikasi Pakis Pro.
 * Daftar permission diturunkan dari MenuBuilder agar menu dan gerbang tidak pernah berbeda.
 */
final class WebAccess
{
    public const MESSAGE = 'Akun Anda hanya untuk aplikasi Pakis Pro.';

    /** @return list<string> */
    public static function menuPermissions(): array
    {
        return MenuBuilder::permissions();
    }

    public function allows(User $user): bool
    {
        return $user->canAny(self::menuPermissions());
    }
}
