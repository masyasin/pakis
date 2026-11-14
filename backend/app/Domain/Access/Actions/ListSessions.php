<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Support\SessionKey;
use App\Domain\Access\Support\UserAgentLabel;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sesi web aktif milik pengguna (FR-003, SEC-005). Sesi produksi memakai driver `database`
 * (keputusan Mas 15 Nov 2026), jadi tabel `sessions` adalah sumber kebenaran. Sesi yang
 * melewati batas idle (BR-018) tidak ditampilkan: sesi itu sudah tidak bisa dipakai lagi.
 */
final class ListSessions
{
    /**
     * @return list<array{key: string, browser: string, platform: string, ip: string|null, last_active_at: string, is_current: bool}>
     */
    public function handle(User $user, string $currentSessionId): array
    {
        $idleSince = now()->subMinutes((int) setting('security.session_idle_minutes'))->getTimestamp();

        return array_values(self::query($user)
            ->where('last_activity', '>=', $idleSince)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row) => [
                'key' => SessionKey::of((string) $row->id),
                ...UserAgentLabel::parse($row->user_agent),
                'ip' => is_string($row->ip_address) ? $row->ip_address : null,
                'last_active_at' => DisplayTime::toLocal(Carbon::createFromTimestamp((int) $row->last_activity))->toIso8601String(),
                'is_current' => hash_equals((string) $row->id, $currentSessionId),
            ])
            // Sesi yang sedang dipakai selalu di atas.
            ->sortByDesc('is_current')
            ->all());
    }

    /**
     * Semua baris sesi milik pengguna (tanpa filter idle) — dipakai juga oleh aksi pencabutan.
     *
     * @return Builder
     */
    public static function query(User $user)
    {
        return DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id);
    }
}
