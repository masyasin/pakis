<?php

namespace App\Domain\Purchasing\Support;

use App\Domain\Inventory\Models\SerialNumber;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pemeriksaan bersama saat periksa DAN posting (SEC-104): PO masih terbuka, over-receipt kumulatif
 * per baris PO, dan jumlah nomor seri produk `tracks_serial`. Dipanggil di dalam transaksi pemanggil
 * (baris PO sudah dikunci oleh pemanggil bila perlu).
 */
final class ReceiptChecks
{
    /** @throws ApiError PO_NOT_APPROVED bila PO bukan `approved`/`partially_received` (BR-158(1)) */
    public static function assertPurchaseOrderOpen(?PurchaseOrder $order): void
    {
        if ($order !== null && ! $order->status->isOpen()) {
            throw new ApiError(ErrorCode::PO_NOT_APPROVED, ['purchase_order_id' => ErrorCode::PO_NOT_APPROVED->message(), 'status' => $order->status->value]);
        }
    }

    /**
     * Qty diterima kumulatif per baris PO (GR `posted` lain + GR ini) ≤ `qty_base × (1 + toleransi%)`.
     * `details.lines.<i>.qty_received` = sisa yang masih boleh (satuan dasar).
     *
     * @param  Collection<int, GoodsReceiptLine>  $lines
     *
     * @throws ApiError OVER_RECEIPT
     */
    public static function assertWithinTolerance(GoodsReceipt $receipt, Collection $lines): void
    {
        if ($receipt->purchase_order_id === null) {
            return;
        }
        $tolerance = BigDecimal::of((string) setting('purchasing.over_receipt_tolerance_pct'));
        $poLines = PurchaseOrderLine::query()->whereIn('id', $lines->pluck('purchase_order_line_id')->filter()->unique()->all())->get()->keyBy('id');

        // Jumlah GR terposting lain per baris PO (kolom `qty_received_base` PO baru naik saat posting).
        $posted = GoodsReceiptLine::query()
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id')
            ->where('goods_receipts.status', GoodsReceiptStatus::Posted->value)
            ->where('goods_receipts.id', '!=', $receipt->id)
            ->whereIn('goods_receipt_lines.purchase_order_line_id', $poLines->keys()->all())
            ->groupBy('goods_receipt_lines.purchase_order_line_id')
            ->selectRaw('goods_receipt_lines.purchase_order_line_id AS pol, SUM(goods_receipt_lines.qty_received_base) AS total')
            ->pluck('total', 'pol');

        $pending = [];
        foreach ($lines->values() as $i => $line) {
            if ($line->purchase_order_line_id === null) {
                continue;
            }
            $poLine = $poLines->get($line->purchase_order_line_id);
            if ($poLine === null) {
                continue;
            }
            $pending[$poLine->id] = ($pending[$poLine->id] ?? BigDecimal::zero())->plus($line->qty_received_base);
            $allowed = BigDecimal::of($poLine->qty_base)
                ->multipliedBy(BigDecimal::of('100')->plus($tolerance))
                ->dividedBy(100, 3, RoundingMode::HalfUp);
            $already = BigDecimal::of((string) ($posted[$poLine->id] ?? '0'));
            if ($already->plus($pending[$poLine->id])->isGreaterThan($allowed)) {
                $remaining = $allowed->minus($already)->minus($pending[$poLine->id]->minus($line->qty_received_base));
                throw new ApiError(ErrorCode::OVER_RECEIPT, [
                    'lines' => [$i => ['qty_received' => (string) ($remaining->isNegative() ? BigDecimal::zero()->toScale(3) : $remaining->toScale(3))]],
                ]);
            }
        }
    }

    /**
     * Produk `tracks_serial`: qty dasar bilangan bulat dan jumlah nomor seri baris = qty (BR-144).
     *
     * @param  Collection<int, GoodsReceiptLine>  $lines
     *
     * @throws ApiError SERIAL_COUNT_MISMATCH
     */
    public static function assertSerialCounts(Collection $lines): void
    {
        $lines->loadMissing('product:id,tracks_serial');
        $serialLines = $lines->values()->filter(fn (GoodsReceiptLine $l) => $l->product->tracks_serial);
        if ($serialLines->isEmpty()) {
            return;
        }
        $counts = SerialNumber::query()->whereIn('goods_receipt_line_id', $serialLines->pluck('id')->all())
            ->groupBy('goods_receipt_line_id')->selectRaw('goods_receipt_line_id AS line, COUNT(*) AS total')->pluck('total', 'line');

        foreach ($serialLines as $i => $line) {
            $qty = Qty::of($line->qty_received_base);
            $expected = $qty->isInteger() ? $qty->toBigDecimal()->toInt() : null;
            if ($expected === null || (int) ($counts[$line->id] ?? 0) !== $expected) {
                throw new ApiError(ErrorCode::SERIAL_COUNT_MISMATCH, ['lines' => [$i => ['serials' => $expected]]]);
            }
        }
    }
}
