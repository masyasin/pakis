<?php

namespace App\Domain\Access\Actions;

use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Keluarkan semua sesi lain" (FR-003). Baris sesi dihapus langsung; `Auth::logoutOtherDevices`
 * sengaja tidak dipakai karena ia meng-hash ulang password (menulis kolom password tanpa perlu).
 * Token mobile tidak tersentuh: itu perangkat, dikelola terpisah (T-031).
 */
final class RevokeOtherSessions
{
    public function __construct(private readonly SecurityLogger $security) {}

    /** @return int jumlah sesi yang dicabut */
    public function handle(User $user, string $currentSessionId): int
    {
        return DB::transaction(function () use ($user, $currentSessionId) {
            $count = ListSessions::query($user)->where('id', '!=', $currentSessionId)->delete();

            if ($count > 0) {
                $this->security->record(SecurityEventType::SessionRevoked, SecurityChannel::Web, $user, $user->username, ['count' => $count]);
            }

            return $count;
        });
    }
}
