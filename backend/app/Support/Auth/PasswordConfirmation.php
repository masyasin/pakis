<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;

/**
 * Masa berlaku konfirmasi ulang password (06 §1.6) dari setting `security.password_confirm_minutes`.
 * Satu sumber untuk controller, middleware RequirePassword, dan alur konfirmasi Fortify.
 */
final class PasswordConfirmation
{
    public static function timeoutSeconds(): int
    {
        return (int) setting('security.password_confirm_minutes') * 60;
    }

    public static function isRecent(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return time() - $confirmedAt <= self::timeoutSeconds();
    }
}
