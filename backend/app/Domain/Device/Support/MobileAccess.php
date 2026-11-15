<?php

namespace App\Domain\Device\Support;

use App\Models\User;

/**
 * Permission yang dipakai endpoint Pakis Pro (05 §5.1). Token API hanya membawa ability dari daftar
 * ini (SEC-020); akun yang tidak memegang satu pun tidak memakai Pakis Pro.
 */
final class MobileAccess
{
    public const PERMISSIONS = [
        'attendance.self',
        'attendance_corrections.create',
        'attendance_corrections.approve',
    ];

    /** @return list<string> */
    public static function abilities(User $user): array
    {
        return array_values(array_filter(self::PERMISSIONS, fn (string $p) => $user->can($p)));
    }

    public static function allows(User $user): bool
    {
        return self::abilities($user) !== [];
    }
}
