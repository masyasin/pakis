<?php

namespace App\Domain\Attendance\Support;

use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Carbon\CarbonImmutable;

/**
 * Waktu efektif absen (BR-066, BR-067, SEC-024).
 *
 * - Online: waktu SERVER; jam HP tidak dipercaya untuk menghitung telat.
 * - Offline: `client_time` (absen terjadi saat tidak ada sinyal), diterima bila tidak lebih tua dari
 *   `attendance.offline_max_hours` dan tidak lebih dari `attendance.max_clock_skew_minutes` di masa
 *   depan; selain itu `ATTENDANCE_OFFLINE_TOO_OLD`.
 * - Jam HP meleset > batas skew (dilaporkan `device_clock_offset_s`, atau selisih client–server
 *   untuk absen online) → tetap diterima, `flags.clock_skew_s` diisi (anomali, BR-068).
 */
final class ClockGuard
{
    /**
     * @return array{0: CarbonImmutable, 1: array<string, int>} [recorded_at UTC, flags]
     */
    public function resolve(bool $isOffline, CarbonImmutable $clientTime, ?int $deviceClockOffsetS, CarbonImmutable $serverNow): array
    {
        $skewLimitS = (int) setting('attendance.max_clock_skew_minutes') * 60;
        $client = $clientTime->utc();
        $server = $serverNow->utc();

        if ($isOffline) {
            $ageS = $server->getTimestamp() - $client->getTimestamp();
            if ($ageS > (int) setting('attendance.offline_max_hours') * 3600 || -$ageS > $skewLimitS) {
                throw new ApiError(ErrorCode::ATTENDANCE_OFFLINE_TOO_OLD, ['offline_max_hours' => (int) setting('attendance.offline_max_hours')]);
            }
        }

        // Online: selisih client–server terukur langsung (latensi jaringan beberapa detik saja).
        $measured = $isOffline ? 0 : abs($server->getTimestamp() - $client->getTimestamp());
        $skew = max(abs($deviceClockOffsetS ?? 0), $measured);
        $flags = $skew > $skewLimitS ? ['clock_skew_s' => $skew] : [];

        return [$isOffline ? $client : $server, $flags];
    }
}
