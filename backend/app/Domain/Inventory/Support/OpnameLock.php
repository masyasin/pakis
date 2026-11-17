<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Models\StockOpnameLine;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;

/**
 * Batch yang sedang dihitung di opname aktif (`counting`/`review`/`pending_approval`, BR-162(8))
 * tidak boleh dipindah rak, dikarantina, dilepas, atau dimasukkan berita acara: snapshot & rak
 * lembar hitung jadi tidak cocok dengan fisik (T-084, PA-69).
 */
final class OpnameLock
{
    /** @param  list<int>  $batchIds */
    public static function assertFree(array $batchIds): void
    {
        if ($batchIds === []) {
            return;
        }
        $busy = StockOpnameLine::query()
            ->join('stock_opnames', 'stock_opnames.id', '=', 'stock_opname_lines.stock_opname_id')
            ->whereIn('stock_opnames.status', StockOpnameStatus::locking())
            ->whereIn('stock_opname_lines.stock_batch_id', $batchIds)
            ->distinct()->pluck('stock_opnames.number')->all();
        if ($busy !== []) {
            throw new ApiError(ErrorCode::OPNAME_BATCH_LOCKED, ['opnames' => $busy]);
        }
    }
}
