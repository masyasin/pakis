<?php

namespace App\Domain\Access\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * BR-011: password tidak boleh memuat username atau nomor pegawai pemiliknya (tanpa beda huruf).
 * Penanda identitas yang terlalu pendek (< 3 karakter) diabaikan agar tidak menolak secara acak.
 */
final class NotContainsIdentity implements ValidationRule
{
    /**
     * @param  array<string, string|null>  $identities  label → nilai, mis. ['username' => 'siti.aminah']
     */
    public function __construct(private readonly array $identities) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = mb_strtolower((string) $value);

        foreach ($this->identities as $label => $identity) {
            $identity = mb_strtolower(trim((string) $identity));
            if (mb_strlen($identity) >= 3 && str_contains($password, $identity)) {
                $fail("Password tidak boleh memuat {$label} Anda.");

                return;
            }
        }
    }
}
