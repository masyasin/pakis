<?php

namespace App\Domain\Access\Actions;

use App\Models\User;

/**
 * Hasil AttemptLogin::verify. `user` terisi = kredensial sah. `lockedSeconds` terisi = sedang
 * dikunci karena terlalu banyak percobaan (BR-016). Keduanya null = gagal (alasan sengaja tidak
 * dibedakan, SEC-001).
 */
final class LoginAttempt
{
    public function __construct(
        public readonly ?User $user,
        public readonly ?int $lockedSeconds,
    ) {}
}
