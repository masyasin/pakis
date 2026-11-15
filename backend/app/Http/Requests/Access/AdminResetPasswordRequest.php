<?php

namespace App\Http\Requests\Access;

use App\Http\Requests\Profile\CurrentPasswordRequest;

/**
 * Reset password pengguna lain (05 §4.4, FR-007): admin mengonfirmasi dengan password-nya SENDIRI
 * di request yang sama, agar sesi admin yang tertinggal terbuka tidak bisa dipakai mengambil alih
 * akun lain. Pesan galat sama dengan konfirmasi di Profil.
 */
class AdminResetPasswordRequest extends CurrentPasswordRequest {}
