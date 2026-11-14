<?php

namespace App\Domain\Access\Rules;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kebijakan password tunggal (BR-011, SEC-002), dipakai di SEMUA tempat password ditetapkan:
 * ubah password, wajib ganti, reset (T-015), reset admin (T-020), API (T-031), impor akun.
 *
 * Tidak ada aturan komposisi (huruf besar/simbol): panjang minimal dari setting, maksimal 128,
 * tidak memuat username/nomor pegawai, tidak sama dengan password saat ini, dan tidak tercatat bocor.
 */
final class PasswordPolicy
{
    public const MAX_LENGTH = 128;

    /**
     * @param  User|null  $user  pemilik password (null = akun baru tanpa identitas tersimpan)
     * @param  string|null  $username  username calon akun, bila $user belum ada
     * @return list<string|ValidationRule>
     */
    public function rules(?User $user = null, ?string $username = null): array
    {
        $rules = [
            'required',
            'string',
            'min:'.self::minLength(),
            'max:'.self::MAX_LENGTH,
            'confirmed',
            new NotContainsIdentity(array_filter([
                'username' => $user->username ?? $username,
                'nomor pegawai' => $user !== null ? self::employeeNumber($user) : null,
            ])),
        ];

        if ($user !== null) {
            $rules[] = new NotCurrentPassword($user);
        }

        if (setting('security.password_check_breached')) {
            $rules[] = new NotBreached;
        }

        return $rules;
    }

    public static function minLength(): int
    {
        return (int) setting('security.password_min_length');
    }

    /** Atribut HTML `passwordrules` agar pengelola password browser membuat password yang sah. */
    public function toPasswordRulesString(): string
    {
        return 'minlength: '.self::minLength().'; maxlength: '.self::MAX_LENGTH.';';
    }

    private static function employeeNumber(User $user): ?string
    {
        // TODO(T-024): pakai relasi Employee; tabel pegawai belum ada sebelum T-024.
        if ($user->employee_id === null || ! Schema::hasTable('employees')) {
            return null;
        }

        $number = DB::table('employees')->where('id', $user->employee_id)->value('employee_number');

        return is_string($number) ? $number : null;
    }
}
