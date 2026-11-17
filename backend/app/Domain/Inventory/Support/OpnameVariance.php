<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameLine;
use App\Support\Money\Money;
use App\Support\Money\Qty;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Selisih opname per baris (BR-162(2)(4)), satu tempat untuk selesai hitung, ajukan, posting, dan
 * tampilan tinjau:
 *
 *   ekspektasi = qty_system (snapshot `frozen_at`) + Σ mutasi batch itu SETELAH `frozen_at`
 *                (tanpa mutasi opname ini sendiri), sehingga jual/mutasi selama hitung bukan selisih
 *   selisih    = hitung final (hitung ulang bila ada) − ekspektasi
 *
 * Wajib hitung ulang oleh orang lain bila selisih ≠ 0 dan (|selisih| / ekspektasi ≥
 * `inventory.opname_recount_threshold_pct` %, ekspektasi 0 dianggap 100%) atau
 * (|selisih| × HPP ≥ `inventory.opname_recount_threshold_amount`). HPP dibaca dari `product_costs`
 * (CostCalculator khusus Ledger, BR-159); belum ada = 0.
 */
final class OpnameVariance
{
    /**
     * @return array<int, array{expected: Qty, final: Qty|null, variance: Qty|null, value: Money, recount_required: bool}>
     *                                                                                                                     id baris =>
     */
    public static function for(StockOpname $opname): array
    {
        $lines = $opname->relationLoaded('lines') ? $opname->lines : $opname->lines()->get();
        $moved = self::movedSinceFreeze($opname);
        $costs = ProductCost::query()->whereIn('product_id', $lines->pluck('product_id')->unique())->get()->keyBy('product_id');
        $pct = BigDecimal::of((string) setting('inventory.opname_recount_threshold_pct'));
        $amount = BigDecimal::of((string) setting('inventory.opname_recount_threshold_amount'));

        $result = [];
        foreach ($lines as $line) {
            $expected = Qty::of($line->qty_system_base)->plus($line->stock_batch_id === null ? '0' : ($moved[$line->stock_batch_id] ?? '0'));
            $final = $line->finalQty();
            $variance = $final?->minus($expected);
            $cost = $costs->get($line->product_id)?->avgCost()->toBigDecimal() ?? BigDecimal::zero();
            $abs = $variance === null ? BigDecimal::zero() : $variance->toBigDecimal()->abs();
            $value = Money::of($abs->multipliedBy($cost));

            $result[$line->id] = [
                'expected' => $expected,
                'final' => $final,
                'variance' => $variance,
                'value' => $value,
                'recount_required' => $variance !== null && ! $variance->isZero() && self::overThreshold($abs, $expected, $value, $pct, $amount),
            ];
        }

        return $result;
    }

    /** Σ |selisih| × HPP seluruh baris (nominal approval & `variance_value`). */
    public static function totalValue(StockOpname $opname): Money
    {
        $total = Money::zero();
        foreach (self::for($opname) as $row) {
            $total = $total->plus($row['value']);
        }

        return $total;
    }

    /** @param  array{variance: Qty|null}  $row */
    public static function hasVariance(array $row): bool
    {
        return $row['variance'] !== null && ! $row['variance']->isZero();
    }

    private static function overThreshold(BigDecimal $abs, Qty $expected, Money $value, BigDecimal $pct, BigDecimal $amount): bool
    {
        if ($amount->isPositive() && $value->toBigDecimal()->isGreaterThanOrEqualTo($amount)) {
            return true;
        }
        if ($expected->toBigDecimal()->isZero()) {
            return true;
        }
        $share = $abs->multipliedBy(100)->dividedBy($expected->toBigDecimal()->abs(), 6, RoundingMode::HalfUp);

        return $share->isGreaterThanOrEqualTo($pct);
    }

    /**
     * Σ qty mutasi per batch setelah `frozen_at`, di luar mutasi opname ini (posting).
     *
     * @return array<int, string>
     */
    private static function movedSinceFreeze(StockOpname $opname): array
    {
        $frozen = $opname->frozenAtSql();
        if ($frozen === null) {
            return [];
        }
        $batchIds = StockOpnameLine::query()->where('stock_opname_id', $opname->id)->whereNotNull('stock_batch_id')->pluck('stock_batch_id');

        return StockMovement::query()
            ->whereIn('stock_batch_id', $batchIds)
            ->where('moved_at', '>', $frozen)
            ->where(fn ($q) => $q->where('source_type', '<>', StockOpname::SOURCE_TYPE)->orWhere('source_id', '<>', $opname->id))
            ->groupBy('stock_batch_id')
            ->selectRaw('stock_batch_id, SUM(qty) AS moved')
            ->pluck('moved', 'stock_batch_id')
            ->map(fn ($v) => (string) $v)
            ->all();
    }
}
