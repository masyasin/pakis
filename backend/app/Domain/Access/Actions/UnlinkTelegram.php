<?php

namespace App\Domain\Access\Actions;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Putuskan tautan Telegram (BR-013): kanal OTP Telegram tidak lagi bisa dipakai untuk akun ini.
 * chat_id tidak dicatat di audit (pengenal pribadi); cukup status terhubung → diputus.
 */
final class UnlinkTelegram
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user): void
    {
        if ($user->telegram_chat_id === null) {
            return;
        }

        DB::transaction(function () use ($user) {
            $user->forceFill(['telegram_chat_id' => null, 'telegram_linked_at' => null])->save();

            $this->audit->record('user.updated', $user, ['telegram' => 'terhubung'], ['telegram' => 'diputus']);
        });
    }
}
