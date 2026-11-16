<?php

namespace App\Console\Commands;

use App\Domain\Access\Actions\ListSessions;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Device\Support\DeviceTokens;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Isolasi insiden (09 §6 langkah 2, T-045): cabut sesi web & token Pakis Pro satu akun
 * (`--user=<username|email|id>`) atau SEMUA akun (`--all`). Perangkat tetap `active`; pemilik cukup
 * login ulang. Setiap pencabutan dicatat di log keamanan (kanal `system`).
 */
#[Signature('security:revoke {--user= : username, email, atau id akun} {--all : semua akun} {--force : tanpa konfirmasi (untuk --all)}')]
#[Description('Cabut sesi web & token API satu akun atau semua akun (insiden)')]
class RevokeSecurity extends Command
{
    public function handle(SecurityLogger $security, DeviceTokens $tokens): int
    {
        $all = (bool) $this->option('all');
        $login = $this->option('user');

        if ($all === (is_string($login) && $login !== '')) {
            $this->error('Pilih salah satu: --user=<username|email|id> atau --all.');

            return self::INVALID;
        }

        if ($all) {
            if (! $this->option('force') && ! $this->confirm('Semua pengguna akan keluar dari web dan Pakis Pro. Lanjutkan?')) {
                return self::FAILURE;
            }

            $sessions = DB::table((string) config('session.table', 'sessions'))->delete();
            $tokenCount = 0;
            User::query()->whereHas('tokens')->chunkById(200, function ($users) use ($tokens, &$tokenCount) {
                foreach ($users as $user) {
                    $tokenCount += $tokens->revokeForUser($user, 'security_revoke_all');
                }
            });
            $security->record(SecurityEventType::SessionRevoked, SecurityChannel::System, context: ['all' => true, 'count' => $sessions, 'tokens' => $tokenCount]);
            $this->info("Dicabut: {$sessions} sesi web, {$tokenCount} token API.");

            return self::SUCCESS;
        }

        $user = User::query()
            ->where(fn ($q) => $q->where('username', $login)->orWhere('email', $login)->when(ctype_digit((string) $login), fn ($w) => $w->orWhere('id', (int) $login)))
            ->first();
        if ($user === null) {
            $this->error('Akun tidak ditemukan.');

            return self::FAILURE;
        }

        $sessions = DB::transaction(function () use ($user, $tokens, $security) {
            $count = ListSessions::query($user)->delete();
            $tokens->revokeForUser($user, 'security_revoke');
            $security->record(SecurityEventType::SessionRevoked, SecurityChannel::System, $user, $user->username, ['count' => $count, 'by' => 'cli']);

            return $count;
        });
        $this->info("Akun {$user->username}: {$sessions} sesi web dicabut, token API dicabut.");

        return self::SUCCESS;
    }
}
