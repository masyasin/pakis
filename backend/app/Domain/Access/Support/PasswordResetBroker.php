<?php

namespace App\Domain\Access\Support;

use Illuminate\Auth\Passwords\PasswordBroker;
use LogicException;

/**
 * Broker token reset password (BR-012) dengan masa berlaku dari setting
 * `security.reset_token_minutes`. Token disimpan sebagai hash dan dihapus setelah dipakai.
 */
final class PasswordResetBroker
{
    public static function make(): PasswordBroker
    {
        config(['auth.passwords.users.expire' => (int) setting('security.reset_token_minutes')]);

        $broker = app('auth.password')->broker('users');
        if (! $broker instanceof PasswordBroker) {
            throw new LogicException('Broker reset password tidak dikenal.');
        }

        return $broker;
    }
}
