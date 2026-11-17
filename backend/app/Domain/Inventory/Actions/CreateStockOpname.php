<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\StockOpnameScope;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Numbering\NumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Buat opname draf (FR-A28, BR-162). Lokasi, cakupan (`full`/`bin`/`category`/`product` +
 * `scope_ids`), dan hitung buta (bawaan `inventory.opname_blind_count_default`) sudah divalidasi
 * StockOpnameRequest. Belum ada baris: snapshot dibuat saat mulai (StartStockOpname).
 */
final class CreateStockOpname
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
    ) {}

    /** @param  array{stock_location_id: int, scope: StockOpnameScope, scope_ids: list<int>|null, blind_count: bool, note: string|null}  $data */
    public function handle(array $data, int $userId): StockOpname
    {
        return DB::transaction(function () use ($data, $userId) {
            $opname = new StockOpname;
            $opname->forceFill([
                ...$data,
                'number' => $this->numbers->next(StockOpname::SOURCE_TYPE, isTaken: fn (string $n) => StockOpname::query()->where('number', $n)->exists()),
                'status' => StockOpnameStatus::Draft,
                'created_by' => $userId,
                'updated_by' => $userId,
            ])->save();
            $this->audit->record('stock_opname.created', $opname, null, $opname->audited());

            return $opname;
        });
    }
}
