<?php

namespace App\Domain\Inventory\Ledger\Exceptions;

use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;

/**
 * Batch karantina/kedaluwarsa/dimusnahkan dipakai untuk jual, kirim, atau reservasi (BR-102) → 422.
 * Status tidak ditaruh di `details` (string di sana dianggap pesan galat field oleh renderer Inertia).
 */
final class BatchNotAvailable extends ApiError
{
    public function __construct(
        public readonly int $stockBatchId,
        public readonly StockBatchStatus $status,
    ) {
        parent::__construct(ErrorCode::BATCH_NOT_AVAILABLE, ['stock_batch_id' => $stockBatchId]);
    }
}
