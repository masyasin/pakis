<?php

namespace App\Http\Requests\Profile;

use App\Domain\Access\Support\AvatarStore;
use App\Models\User;
use App\Support\Phone\PhoneNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Profil saya (FR-010, 05 §4.1). Username tidak bisa diubah sendiri (dikelola admin, T-020).
 * Email & HP adalah kanal pemulihan akun → mengubahnya wajib password saat ini, agar sesi yang
 * dibajak tidak bisa langsung memindahkan jalur reset password ke milik penyerang.
 */
class ProfileUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $phone = $this->input('phone');

        $this->merge([
            'email' => is_string($email) && trim($email) !== '' ? mb_strtolower(trim($email)) : null,
            // Disimpan `62…` lewat normalizer bersama (BR-166(1), T-073), sama dengan form Pengguna.
            'phone' => PhoneNormalizer::forInput($phone),
            'remove_avatar' => $this->boolean('remove_avatar'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'string', 'email', 'max:150', Rule::unique(User::class)->ignore($user->id)],
            // HP lama tak sah yang tidak diubah tetap diterima (lihat UserRequest).
            'phone' => ['nullable', 'string', Rule::when($this->input('phone') !== $user->phone, ['regex:'.PhoneNormalizer::STORED_PATTERN])],
            // Isi berkas diperiksa lagi oleh AvatarStore (getimagesize + encode ulang).
            'avatar' => ['nullable', 'file', 'max:'.AvatarStore::MAX_KB, 'mimes:png,jpg,jpeg,webp'],
            'remove_avatar' => ['boolean'],
            'current_password' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => PhoneNormalizer::INVALID_MESSAGE,
            'avatar.max' => 'Ukuran foto maksimal 2 MB.',
            'avatar.mimes' => 'Foto harus PNG, JPG, atau WebP. SVG tidak diterima.',
            'email.unique' => 'Email ini sudah dipakai akun lain.',
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var User $user */
                $user = $this->user();
                // HP lama `08…` yang belum ternormalisasi tidak dianggap berubah hanya karena formatnya.
                $storedPhone = PhoneNormalizer::normalize($user->phone) ?? $user->phone;
                $recoveryChanged = $this->input('email') !== $user->email || $this->input('phone') !== $storedPhone;

                if (! $recoveryChanged) {
                    return;
                }

                $password = $this->input('current_password');
                if (! is_string($password) || $password === '') {
                    $validator->errors()->add('current_password', 'Masukkan password saat ini untuk mengganti email atau nomor HP.');
                } elseif (! $this->container->make('hash')->check($password, $user->password)) {
                    $validator->errors()->add('current_password', 'Password saat ini salah.');
                }
            },
        ];
    }

    /**
     * @return array{name: string, email: string|null, phone: string|null}
     */
    public function profileData(): array
    {
        $email = $this->validated('email');
        $phone = $this->validated('phone');

        return [
            'name' => (string) $this->validated('name'),
            'email' => is_string($email) ? $email : null,
            'phone' => is_string($phone) ? $phone : null,
        ];
    }
}
