<?php

namespace App\Domain\Inventory\Resources;

use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameLine;
use App\Domain\Inventory\Support\InventoryFileUrls;
use App\Support\Money\Qty;

/**
 * Baris lembar hitung (15 §7.1). SEC-103: saat hitung buta & `counting`, `qty_system_base`,
 * ekspektasi, selisih, dan penanda wajib hitung ulang TIDAK ada di array sama sekali (bukan null
 * atau disembunyikan di UI), sehingga respons JSON/Inertia tidak membawa angka sistem.
 * Nilai rupiah selisih hanya untuk `stock.view_cost` (15 §6.3).
 */
final class StockOpnameLineResource
{
    /**
     * @param  array{expected: Qty, final: Qty|null, variance: Qty|null, value: mixed, recount_required: bool}|null  $variance
     * @return array<string, mixed>
     */
    public static function make(StockOpnameLine $line, StockOpname $opname, ?array $variance, bool $canCost): array
    {
        $row = [
            'id' => $line->id,
            'product_id' => $line->product_id,
            'sku' => $line->product->sku,
            'name' => $line->product->name,
            'unit' => $line->product->baseUnit->name,
            'batch_no' => $line->batch_no,
            'expiry_date' => $line->expiry_date?->toDateString(),
            'bin' => $line->bin === null ? null : ['id' => $line->bin->id, 'code' => $line->bin->code],
            'found' => $line->isFound(),
            'qty_counted_base' => $line->qty_counted_base,
            'counted_by' => $line->counter?->name,
            'counted_by_id' => $line->counted_by,
            'qty_recount_base' => $line->qty_recount_base,
            'recounted_by' => $line->recounter?->name,
            'variance_reason_id' => $line->variance_reason_id,
            'variance_reason' => $line->varianceReason?->name,
            'note' => $line->note,
            'has_photo' => $line->photo_path !== null,
            'photo_url' => InventoryFileUrls::opnamePhoto($line),
        ];
        if ($opname->hidesSystemQty() || $variance === null) {
            return $row;
        }

        return [
            ...$row,
            'qty_system_base' => $line->qty_system_base,
            'qty_expected_base' => (string) $variance['expected'],
            'variance_base' => $variance['variance'] === null ? null : (string) $variance['variance'],
            'variance_value' => $canCost ? (string) $variance['value'] : null,
            'recount_required' => $variance['recount_required'],
        ];
    }
}
