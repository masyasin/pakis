<?php

namespace App\Support\Listing\Filters;

/**
 * Hasil membaca satu nilai filter dari request.
 *
 * - empty: tidak ada filter (tidak diterapkan, tidak dilaporkan)
 * - valid: diterapkan
 * - invalid: dilaporkan di state.invalid_filters; bila ada `value` (sebagian nilai sah,
 *   mis. multi select), bagian sah tetap diterapkan.
 */
final class FilterResult
{
    private function __construct(
        public readonly mixed $value,
        public readonly ?string $error,
    ) {}

    public static function empty(): self
    {
        return new self(null, null);
    }

    public static function valid(mixed $value): self
    {
        return new self($value, null);
    }

    public static function invalid(string $error, mixed $usableValue = null): self
    {
        return new self($usableValue, $error);
    }

    public function hasValue(): bool
    {
        return $this->value !== null && $this->value !== [];
    }

    public function isInvalid(): bool
    {
        return $this->error !== null;
    }
}
