<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Models\StockOpname;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Batalkan opname (15 §6.6): draf, sedang dihitung, atau tinjau. Yang menunggu persetujuan ditarik
 * lewat persetujuannya; yang sudah diposting final. Batch langsung bebas untuk opname lain.
 */
final class CancelStockOpname
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(StockOpname $opname, int $userId): StockOpname
    {
        return DB::transaction(function () use ($opname, $userId) {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);
            if (! in_array($locked->status, [StockOpnameStatus::Draft, StockOpnameStatus::Counting, StockOpnameStatus::Review], true)) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Opname ini tidak bisa dibatalkan lagi.');
            }
            $before = $locked->audited();
            $locked->forceFill(['status' => StockOpnameStatus::Cancelled, 'updated_by' => $userId])->save();
            $this->audit->record('stock_opname.cancelled', $locked, $before, $locked->audited());

            return $locked;
        });
    }
}
