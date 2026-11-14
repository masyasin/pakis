<?php

namespace App\Http\Requests\Auth;

use Laravel\Fortify\Http\Requests\LoginRequest as FortifyLoginRequest;

/**
 * LoginRequest Fortify tanpa "ingat saya" (SEC-005).
 *
 * Menghapus checkbox di UI saja tidak cukup: klien bisa tetap mengirim `remember=1`, dan
 * SessionGuard akan menerbitkan cookie recaller walau model tidak punya remember token.
 * Input dibuang di sini agar AttemptToAuthenticate dan alur 2FA (login.remember di sesi)
 * selalu menerima false. Validasi login lengkap (username/email, lockout) menyusul di T-013.
 */
class LoginRequest extends FortifyLoginRequest
{
    protected function prepareForValidation(): void
    {
        $this->request->remove('remember');
        $this->query->remove('remember');
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['remember']);

        return $rules;
    }
}
