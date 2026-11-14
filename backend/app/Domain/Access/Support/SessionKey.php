<?php

namespace App\Domain\Access\Support;

/**
 * Kunci buram untuk satu sesi web (05 §4.1 `admin.profile.sessions.destroy`).
 *
 * ID sesi asli adalah kredensial: siapa pun yang memegangnya bisa memakai sesi itu (cukup ditaruh
 * di cookie). Jadi ID asli TIDAK PERNAH dikirim ke browser; daftar sesi & URL pencabutan memakai
 * HMAC-nya. Kunci tidak bisa dibalik jadi ID, dan tanpa APP_KEY tidak bisa dibuat dari ID tebakan.
 */
final class SessionKey
{
    public static function of(string $sessionId): string
    {
        return hash_hmac('sha256', 'session:'.$sessionId, (string) config('app.key'));
    }

    public static function isWellFormed(string $key): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $key) === 1;
    }
}
