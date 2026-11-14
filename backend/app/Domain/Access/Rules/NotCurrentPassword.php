<?php

namespace App\Domain\Access\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

/** BR-011: password baru tidak boleh sama dengan password saat ini. */
final class NotCurrentPassword implements ValidationRule
{
    public function __construct(private readonly User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && Hash::check($value, $this->user->password)) {
            $fail('Password baru tidak boleh sama dengan password saat ini.');
        }
    }
}
