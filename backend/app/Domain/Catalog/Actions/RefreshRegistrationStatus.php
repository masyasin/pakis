<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\RegistrationStatus;
use App\Domain\Catalog\Models\ProductRegistration;
use App\Domain\Catalog\Notifications\RegistrationExpiringNotification;
use App\Domain\Catalog\Queries\ActiveRegistration;
use App\Domain\Catalog\Support\RegistrationState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Job harian izin edar (BR-152, 15 §11.1 `RefreshRegistrationStatus`, 00:30 lokal):
 * 1. izin `active` dengan `valid_until` < hari ini → `expired` (+ audit);
 * 2. pengingat H-{ambang}/30/7/0 ke pemegang `product_registrations.manage`.
 *
 * Idempoten lewat `reminder_logs` (UNIQUE subjek+ambang). Ambang dihitung "sisa hari ≤ ambang",
 * bukan "sama persis": job yang terlewat (server mati) tetap mengirim, tetapi hanya SATU notifikasi
 * untuk ambang terdekat; ambang yang lebih jauh ikut dicatat agar tidak menyusul belakangan.
 */
final class RefreshRegistrationStatus
{
    public const SUBJECT = 'product_registration';

    /** Ambang tetap BR-152 di bawah ambang pertama (setting). */
    private const FOLLOW_UP_DAYS = [30, 7, 0];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{expired: int, reminded: int} */
    public function handle(): array
    {
        $today = ActiveRegistration::localDate();

        return ['expired' => $this->expire($today), 'reminded' => $this->remind($today)];
    }

    private function expire(string $today): int
    {
        $count = 0;
        ProductRegistration::query()->where('status', RegistrationStatus::Active)->where('valid_until', '<', $today)
            ->orderBy('id')->each(function (ProductRegistration $registration) use (&$count) {
                DB::transaction(function () use ($registration, &$count) {
                    $before = $registration->audited();
                    $registration->forceFill(['status' => RegistrationStatus::Expired])->save();
                    $this->audit->record('product_registration.expired', $registration, $before, $registration->audited());
                    $count++;
                });
            });

        return $count;
    }

    private function remind(string $today): int
    {
        $thresholds = self::thresholds();
        $until = CarbonImmutable::parse($today)->addDays($thresholds[0])->toDateString();
        $recipients = null;
        $sent = 0;

        ProductRegistration::query()->with('product:id,name')
            ->where('status', RegistrationStatus::Active)
            ->whereBetween('valid_until', [$today, $until])
            ->orderBy('id')->each(function (ProductRegistration $registration) use ($today, $thresholds, &$recipients, &$sent) {
                $daysLeft = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($registration->valid_until->toDateString()));
                $due = array_values(array_filter($thresholds, fn (int $t) => $daysLeft <= $t));
                if ($due === []) {
                    return;
                }

                $keys = array_map(fn (int $t) => "h{$t}", $due);
                $logged = DB::table('reminder_logs')->where('subject_type', self::SUBJECT)
                    ->where('subject_id', $registration->id)->whereIn('reminder_key', $keys)->pluck('reminder_key')->all();
                $missing = array_values(array_diff($keys, $logged));
                if ($missing === []) {
                    return;
                }

                // Catat dulu, kirim kemudian: insertOrIgnore 0 baris = job lain sudah mengirim.
                $inserted = DB::table('reminder_logs')->insertOrIgnore(array_map(fn (string $key) => [
                    'subject_type' => self::SUBJECT, 'subject_id' => $registration->id, 'reminder_key' => $key, 'sent_at' => now(),
                ], $missing));
                if ($inserted === 0) {
                    return;
                }

                $recipients ??= self::recipients();
                Notification::send($recipients, new RegistrationExpiringNotification(
                    $registration->product_id,
                    $registration->product->name,
                    $registration->registration_no,
                    $registration->valid_until->translatedFormat('j M Y'),
                    $daysLeft,
                ));
                $sent++;
            });

        return $sent;
    }

    /** @return list<int> ambang menurun, mis. [60, 30, 7, 0]; ambang di atas setting dilewati. */
    public static function thresholds(): array
    {
        $first = RegistrationState::thresholdDays();
        $all = array_unique([$first, ...array_filter(self::FOLLOW_UP_DAYS, fn (int $d) => $d <= $first)]);
        rsort($all);

        return $all;
    }

    /** @return Collection<int, User> */
    private static function recipients(): Collection
    {
        return User::permission('product_registrations.manage')->where('is_active', true)->get();
    }
}
