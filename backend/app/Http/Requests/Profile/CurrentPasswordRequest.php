<?php

namespace App\Http\Requests\Profile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Aksi akun yang wajib password saat ini (05 §4.1): cabut sesi, keluarkan sesi lain, tautkan
 * Telegram. Konfirmasi ulang memastikan yang melakukannya pemilik akun, bukan sesi yang tertinggal.
 */
class CurrentPasswordRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Masukkan password saat ini.',
            'current_password.current_password' => 'Password saat ini salah.',
        ];
    }
}
