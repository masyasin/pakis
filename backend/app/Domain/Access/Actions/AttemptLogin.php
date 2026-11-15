<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Support\EmployeeStatus;
use App\Domain\Access\Support\WebAccess;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * Verifikasi kredensial login (BR-010, BR-016, BR-017, SEC-001). Web memanggil `handle()` lewat
 * `Fortify::authenticateUsing` (sesi, regenerasi ID, dan 2FA tetap diurus Fortify); API Pakis Pro
 * (T-031) memanggil `verify()` agar aturan kredensial web & API tidak bercabang.
 *
 * Semua kegagalan (akun tidak ada, password salah, akun nonaktif/terhapus/terkunci, pegawai
 * nonaktif) memberi HASIL YANG SAMA, dan password selalu di-hash-cek (dummy bila akun tidak ada)
 * agar waktu respons tidak membedakan akun yang ada.
 */
final class AttemptLogin
{
    /**
     * Hash bcrypt (cost 12 = bawaan password pengguna) dari nilai acak yang tidak disimpan di mana
     * pun: bukan rahasia, hanya agar pengecekan untuk akun yang tidak ada memakan waktu yang sama.
     */
    private const DUMMY_HASH = '$2y$12$ceSXIGXmbvBcWOQJaLwgc.aKD/13JvZusmtbrcdiEBJKYhKfbJMRi';

    public function __construct(
        private readonly RecordLoginFailure $failures,
        private readonly EmployeeStatus $employees,
        private readonly WebAccess $webAccess,
    ) {}

    public function handle(Request $request): User
    {
        $result = $this->verify($request->input(Fortify::username()), (string) $request->input('password'), (string) $request->ip(), SecurityChannel::Web);

        if ($result->lockedSeconds !== null) {
            throw ValidationException::withMessages([
                Fortify::username() => 'Terlalu banyak percobaan. Coba lagi dalam '.max(1, (int) ceil($result->lockedSeconds / 60)).' menit.',
            ]);
        }

        if ($result->user === null) {
            throw ValidationException::withMessages([Fortify::username() => __('auth.failed')]);
        }

        // Kredensial benar tetapi akun hanya untuk Pakis Pro: bukan percobaan menebak, jadi tidak dihitung.
        if (! $this->webAccess->allows($result->user)) {
            throw ValidationException::withMessages([Fortify::username() => WebAccess::MESSAGE]);
        }

        return $result->user;
    }

    /**
     * Pemeriksaan kredensial bersama web & API: lockout dulu, hash selalu dicek, satu hasil gagal
     * untuk semua alasan. Gagal dicatat (+ lockout); berhasil mengosongkan penghitung.
     */
    public function verify(mixed $rawIdentifier, string $password, string $ip, SecurityChannel $channel): LoginAttempt
    {
        $identifier = self::normalize($rawIdentifier);

        // Dikelompokkan agar `OR` tidak lolos dari syarat soft delete (`deleted_at IS NULL`).
        $user = $identifier === '' ? null : User::query()
            ->where(fn ($q) => $q->where('username', $identifier)->orWhere('email', $identifier))
            ->first();

        // Penghitung berbasis AKUN yang ditemukan, bukan teks yang diketik: variasi huruf/aksen
        // yang menemukan akun sama tidak mendapat jatah percobaan baru (lihat RecordLoginFailure).
        $subject = RecordLoginFailure::subject($user, $identifier);
        if (RecordLoginFailure::tooManyAttempts($subject, $ip)) {
            return new LoginAttempt(null, RecordLoginFailure::secondsLeft($subject, $ip));
        }

        $passwordOk = Hash::check($password, $user->password ?? self::DUMMY_HASH);
        $reason = match (true) {
            $user === null => 'unknown_identifier',
            ! $passwordOk => 'wrong_password',
            ! $user->is_active => 'inactive_account',
            $user->isLocked() => 'locked_account',
            ! $this->employees->isActive($user) => 'inactive_employee',
            default => null,
        };

        if ($reason !== null) {
            $this->failures->handle($identifier, $ip, $user, $reason, $channel);

            return new LoginAttempt(null, null);
        }

        RecordLoginFailure::clear($subject, $ip, $user);

        return new LoginAttempt($user, null);
    }

    public static function normalize(mixed $raw): string
    {
        return is_string($raw) ? mb_strtolower(trim($raw)) : '';
    }
}
