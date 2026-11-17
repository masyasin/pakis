<?php

namespace App\Domain\Inventory\Ledger\Exceptions;

use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;

/**
 * Stok tersedia (`qty_on_hand − qty_reserved`) kurang dari yang diminta (BR-101) → 422.
 *
 * `details.available_base` sengaja berupa objek Qty (dikirim sebagai string "1.000" di JSON 05 §2.2),
 * bukan string: renderer Inertia menganggap `details` bertipe string sebagai pesan galat field.
 */
final class InsufficientStock extends ApiError
{
    public function __construct(
        public readonly int $stockBatchId,
        public readonly Qty $available,
        public readonly Qty $requested,
    ) {
        parent::__construct(ErrorCode::INSUFFICIENT_STOCK, ['available_base' => $available, 'stock_batch_id' => $stockBatchId]);
    }
}
