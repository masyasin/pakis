<?php

namespace App\Domain\Inventory\Ledger;

use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * HPP moving average per produk (BR-114, BR-159), hanya dipakai StockLedger di dalam transaksinya.
 *
 *   avg_baru = (qty_basis × avg_lama + qty_terima × biaya_terima) / (qty_basis + qty_terima)
 *
 * `qty_basis` = total `qty_on_hand` produk di SEMUA lokasi stok (termasuk karantina & TRANSIT)
 * sebelum penerimaan; `qty_basis ≤ 0` → avg_baru = biaya_terima. Pembilang dihitung tepat (brick/math),
 * hanya hasil bagi yang dibulatkan half-up ke 4 desimal (`DECIMAL(18,4)`).
 */
final class CostCalculator
{
    /**
     * Kunci baris `product_costs` (dibuat bila belum ada), hitung & simpan HPP baru. Wajib dipanggil
     * SEBELUM saldo batch penerimaan dinaikkan agar `qty_basis` = stok sebelum terima.
     *
     * Urutan kunci: `product_costs` (X) → semua batch produk (S, agar SUM membaca saldo terbaru yang
     * sudah di-commit, bukan snapshot transaksi) → batch tujuan (X, oleh StockLedger). Penerimaan
     * produk yang sama berurutan karena baris `product_costs`; pengurangan stok tidak pernah
     * mengunci `product_costs`, jadi tidak ada siklus dengan jalur keluar.
     */
    public function applyReceipt(int $productId, Qty $qty, UnitCost $cost): UnitCost
    {
        $now = now();
        DB::table('product_costs')->insertOrIgnore([
            'product_id' => $productId, 'avg_cost' => '0.0000', 'qty_basis' => '0.000', 'updated_at' => $now,
        ]);
        $row = DB::table('product_costs')->where('product_id', $productId)->lockForUpdate()->first();
        $oldAvg = UnitCost::of($row === null ? '0' : (string) $row->avg_cost);

        $basis = Qty::of((string) DB::table('stock_batches')->where('product_id', $productId)->sharedLock()->sum('qty_on_hand'));

        $newAvg = self::average($basis, $oldAvg, $qty, $cost);

        DB::table('product_costs')->where('product_id', $productId)->update([
            'avg_cost' => (string) $newAvg,
            'qty_basis' => (string) $basis->plus($qty),
            'last_receipt_at' => $now,
            'updated_at' => $now,
        ]);

        return $newAvg;
    }

    /** HPP berjalan untuk mutasi keluar/pindah/penyesuaian (BR-159). Belum pernah ada penerimaan → 0. */
    public function current(int $productId): UnitCost
    {
        $avg = DB::table('product_costs')->where('product_id', $productId)->value('avg_cost');

        return UnitCost::of($avg === null ? '0' : (string) $avg);
    }

    public static function average(Qty $basis, UnitCost $oldAvg, Qty $qty, UnitCost $cost): UnitCost
    {
        if (! $basis->isPositive()) {
            return $cost;
        }

        $numerator = $basis->toBigDecimal()->multipliedBy($oldAvg->toBigDecimal())
            ->plus($qty->toBigDecimal()->multipliedBy($cost->toBigDecimal()));
        $denominator = $basis->toBigDecimal()->plus($qty->toBigDecimal());

        return UnitCost::of($numerator->dividedBy($denominator, 4, RoundingMode::HalfUp));
    }
}
