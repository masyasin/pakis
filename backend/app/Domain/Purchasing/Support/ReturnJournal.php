<?php

namespace App\Domain\Purchasing\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Baris jurnal `inventory.purchase_returned` per baris retur (15 §11.4, BR-159), murni tanpa DB:
 *
 * - D `goods_received_not_invoiced` = qty × biaya beli batch (`unit_cost` baris retur);
 * - K `inventory` = nilai mutasi keluar (qty × HPP rata-rata saat kirim, `LedgerResult::value()`);
 * - selisihnya ke `inventory_variance`: D bila HPP > biaya batch, K bila sebaliknya.
 *
 * Total debit = total kredit per baris. Nominal nol tidak ditulis (payload hanya angka positif).
 */
final class ReturnJournal
{
    /**
     * @param  array{stock_location_id: int, product_id: int}  $dims
     * @return list<array<string, int|string>>
     */
    public static function lines(string $qtyBase, string $batchUnitCost, string $inventoryValue, array $dims): array
    {
        $batchValue = BigDecimal::of($qtyBase)->multipliedBy($batchUnitCost)->toScale(2, RoundingMode::HalfUp);
        $inventory = BigDecimal::of($inventoryValue)->toScale(2, RoundingMode::HalfUp);
        $variance = $inventory->minus($batchValue);

        $out = [];
        if ($batchValue->isPositive()) {
            $out[] = ['role' => 'goods_received_not_invoiced', 'debit' => (string) $batchValue, ...$dims];
        }
        if ($variance->isPositive()) {
            $out[] = ['role' => 'inventory_variance', 'debit' => (string) $variance, ...$dims];
        }
        if ($inventory->isPositive()) {
            $out[] = ['role' => 'inventory', 'credit' => (string) $inventory, ...$dims];
        }
        if ($variance->isNegative()) {
            $out[] = ['role' => 'inventory_variance', 'credit' => (string) $variance->abs(), ...$dims];
        }

        return $out;
    }
}
