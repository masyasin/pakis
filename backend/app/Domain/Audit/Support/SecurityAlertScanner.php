<?php

namespace App\Domain\Audit\Support;

use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\Models\SecurityEvent;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Illuminate\Support\Facades\Cache;

/**
 * Pemindai alert log keamanan (SEC-041, T-045), dijalankan `security:scan-alerts` tiap 5 menit:
 *   1. ≥ `monitoring.login_burst_count` `login_failed` dari satu IP dalam
 *      `monitoring.login_burst_minutes` menit terakhir (jendela geser);
 *   2. `lockout` akun berperan admin (Super Admin, Owner, Administrator);
 *   3. `attendance_mock_detected`;
 *   4. `mass_export` di luar jam kerja `monitoring.work_hour_start`–`monitoring.work_hour_end`.
 * Jenis 2–4 dibaca per event SETELAH penanda id terakhir (cache), jadi tiap event dilaporkan sekali
 * walau job berjalan tumpang tindih. Jenis 1 per IP diredam `monitoring.alert_mute_minutes` agar
 * serangan yang terus berjalan tidak membanjiri kotak masuk.
 *
 * Ambang dibaca dari setting saat berjalan (keputusan Mas 16 Nov 2026, 08 §2.5); daftar role admin
 * tetap di kode karena itu definisi kontrol, bukan angka.
 */
final class SecurityAlertScanner
{
    /** Role yang dianggap "admin" untuk alert lockout. */
    public const ADMIN_ROLES = [RoleCatalog::SUPER_ADMIN, RoleCatalog::OWNER, 'administrator'];

    /** Batas event per putaran: lonjakan besar tetap terbaca di putaran berikutnya. */
    private const BATCH = 500;

    private const CURSOR_KEY = 'security:scan-alerts:last_id';

    public function __construct(private readonly MonitoringAlert $alert) {}

    /** @return int jumlah alert yang dikirim */
    public function scan(): int
    {
        return $this->loginBursts() + $this->eventAlerts();
    }

    private function loginBursts(): int
    {
        $count = (int) setting('monitoring.login_burst_count');
        $minutes = (int) setting('monitoring.login_burst_minutes');
        $rows = SecurityEvent::query()
            ->where('event', SecurityEventType::LoginFailed)
            ->where('occurred_at', '>=', now()->subMinutes($minutes))
            ->whereNotNull('ip')
            ->groupBy('ip')
            ->havingRaw('COUNT(*) >= ?', [$count])
            ->selectRaw('ip, COUNT(*) AS attempts, COUNT(DISTINCT identifier) AS identifiers')
            ->toBase()
            ->get();

        $sent = 0;
        foreach ($rows as $row) {
            $ip = (string) $row->ip;
            if (! Cache::add('security:alert:login_burst:'.sha1($ip), true, (int) setting('monitoring.alert_mute_minutes') * 60)) {
                continue;
            }
            $this->alert->send('Login gagal beruntun', [
                "{$row->attempts} login gagal dari IP {$ip} dalam {$minutes} menit terakhir ({$row->identifiers} akun berbeda dicoba).",
                'Kemungkinan tebak password. Pertimbangkan memblokir IP ini di firewall.',
            ]);
            $sent++;
        }

        return $sent;
    }

    private function eventAlerts(): int
    {
        $cursor = Cache::get(self::CURSOR_KEY);
        $query = SecurityEvent::query()
            ->whereIn('event', [SecurityEventType::Lockout, SecurityEventType::AttendanceMockDetected, SecurityEventType::MassExport])
            ->orderBy('id')
            ->limit(self::BATCH);
        // Putaran pertama (atau cache hilang): mulai dari jendela terakhir, bukan seluruh riwayat.
        is_numeric($cursor)
            ? $query->where('id', '>', (int) $cursor)
            : $query->where('occurred_at', '>=', now()->subMinutes((int) setting('monitoring.login_burst_minutes')));

        $events = $query->get();
        if ($events->isEmpty()) {
            return 0;
        }

        $users = User::query()->with('roles:id,name')->findMany($events->pluck('user_id')->filter()->unique()->values())->keyBy('id');
        $sent = 0;
        foreach ($events as $event) {
            $user = $event->user_id === null ? null : $users->get($event->user_id);
            $lines = $this->linesFor($event, $user);
            if ($lines !== null) {
                $this->alert->send($event->event->label(), $lines);
                $sent++;
            }
        }

        Cache::forever(self::CURSOR_KEY, (int) $events->max('id'));

        return $sent;
    }

    /** @return list<string>|null null = event ini tidak perlu alert */
    private function linesFor(SecurityEvent $event, ?User $user): ?array
    {
        $at = DisplayTime::toLocal($event->occurred_at)->format('d-m-Y H:i');
        $who = $user !== null ? $user->username : ($event->identifier ?? 'tidak dikenal');

        return match ($event->event) {
            SecurityEventType::Lockout => $user !== null && $user->hasAnyRole(self::ADMIN_ROLES)
                ? ["Akun admin {$who} terkunci karena login gagal berulang ({$at}, IP {$event->ip}).", 'Bila bukan pemilik akun yang mencoba, ganti password dan periksa sesi aktifnya.']
                : null,
            SecurityEventType::AttendanceMockDetected => ["Presensi dengan lokasi palsu terdeteksi dari akun {$who} ({$at}). Presensi ditolak."],
            SecurityEventType::MassExport => $this->outsideWorkHours($event)
                ? ['Ekspor massal di luar jam kerja ('.self::workHours().') oleh '.$who." ({$at}, ".((int) ($event->context['row_count'] ?? 0)).' baris).']
                : null,
            default => null,
        };
    }

    /**
     * Jam kerja = [mulai, selesai) jam lokal. Selesai ≤ mulai berarti jam kerja melewati tengah
     * malam (mis. 22–6 untuk gudang shift malam), bukan konfigurasi rusak.
     */
    private function outsideWorkHours(SecurityEvent $event): bool
    {
        $hour = (int) DisplayTime::toLocal($event->occurred_at)->format('G');
        $start = (int) setting('monitoring.work_hour_start');
        $end = (int) setting('monitoring.work_hour_end');
        $inside = $start < $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);

        return ! $inside;
    }

    private static function workHours(): string
    {
        return sprintf('%02d.00–%02d.00', (int) setting('monitoring.work_hour_start'), (int) setting('monitoring.work_hour_end'));
    }
}
