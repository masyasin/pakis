<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Support\EmployeeStatus;
use App\Domain\Access\Support\WebAccess;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * Verifikasi kredensial login web (BR-010, BR-016, BR-017, SEC-001). Dipanggil
 * `Fortify::authenticateUsing`; sesi, regenerasi ID, dan 2FA tetap diurus Fortify.
 *
 * Semua kegagalan (akun tidak ada, password salah, akun nonaktif/terhapus/terkunci, pegawai
 * nonaktif) memberi PESAN YANG SAMA, dan password selalu di-hash-cek (dummy bila akun tidak ada)
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
        $identifier = self::normalize($request->input(Fortify::username()));
        $password = (string) $request->input('password');
        $ip = (string) $request->ip();

        if (RecordLoginFailure::tooManyAttempts($identifier, $ip)) {
            throw ValidationException::withMessages([
                Fortify::username() => 'Terlalu banyak percobaan. Coba lagi dalam '.RecordLoginFailure::minutesLeft($identifier, $ip).' menit.',
            ]);
        }

        // Dikelompokkan agar `OR` tidak lolos dari syarat soft delete (`deleted_at IS NULL`).
        $user = $identifier === '' ? null : User::query()
            ->where(fn ($q) => $q->where('username', $identifier)->orWhere('email', $identifier))
            ->first();

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
            $this->failures->handle($identifier, $ip, $user, $reason);

            throw ValidationException::withMessages([Fortify::username() => __('auth.failed')]);
        }

        // Kredensial benar tetapi akun hanya untuk Pakis Pro: bukan percobaan menebak, jadi tidak dihitung.
        if (! $this->webAccess->allows($user)) {
            throw ValidationException::withMessages([Fortify::username() => WebAccess::MESSAGE]);
        }

        RecordLoginFailure::clear($identifier, $ip);

        return $user;
    }

    public static function normalize(mixed $raw): string
    {
        return is_string($raw) ? mb_strtolower(trim($raw)) : '';
    }
}
