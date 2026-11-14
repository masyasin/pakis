<?php

namespace App\Support\Api;

use RuntimeException;
use Throwable;

/**
 * Galat domain yang sengaja dikirim ke klien API dengan kode kontrak (05 §2.3).
 *
 * Contoh di Action: `throw new ApiError(ErrorCode::ATTENDANCE_OUT_OF_RADIUS, details: [...])`.
 * `details` dan `publicMessage` dikirim apa adanya, jadi jangan isi dengan data sensitif.
 */
final class ApiError extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        public readonly array $details = [],
        public readonly ?string $publicMessage = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode->value, 0, $previous);
    }

    public function status(): int
    {
        return $this->errorCode->status();
    }

    public function publicMessage(): string
    {
        return $this->publicMessage ?? $this->errorCode->message();
    }
}
