<?php

namespace App\Domain\Inventory\Ledger\Exceptions;

use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Throwable;

/**
 * Kunci stok tetap bentrok (deadlock) setelah retry maks (BR-101) → `409 STATE_CONFLICT`, kode
 * bersama 15 §11.3: pengguna cukup mengulang aksinya.
 */
final class StockConflict extends ApiError
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(ErrorCode::STATE_CONFLICT, previous: $previous);
    }
}
