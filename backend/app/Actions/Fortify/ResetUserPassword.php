<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Domain\Access\Rules\PasswordPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        // BR-011: kebijakan yang sama dengan ubah password. Efek lengkap reset (cabut sesi/token,
        // event, email) ditambahkan di T-015.
        Validator::make($input, [
            'password' => app(PasswordPolicy::class)->rules($user),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();
    }
}
