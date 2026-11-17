<?php

namespace App\Domain\Inventory\Ledger;

/**
 * Jenis mutasi stok (`stock_movements.movement_type`, 15 §4.8). Nilai identik dengan ENUM DB.
 *
 * Arah dipakai StockLedger untuk menolak pemakaian yang keliru (mis. `receipt` sebagai pengurang).
 * `canvas_load`/`canvas_unload` dua arah: muat canvas = keluar dari gudang + masuk ke kendaraan
 * dalam satu `move()` (Paket C).
 */
enum MovementType: string
{
    case OpeningBalance = 'opening_balance';
    case Receipt = 'receipt';
    case PurchaseReturn = 'purchase_return';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case Disposal = 'disposal';
    case BinMoveOut = 'bin_move_out';
    case BinMoveIn = 'bin_move_in';
    case Sale = 'sale';
    case SalesReturn = 'sales_return';
    case PosSale = 'pos_sale';
    case PosReturn = 'pos_return';
    case CanvasLoad = 'canvas_load';
    case CanvasUnload = 'canvas_unload';
    case PromoIssue = 'promo_issue';

    /** Boleh menambah saldo. */
    public function isInbound(): bool
    {
        return match ($this) {
            self::OpeningBalance, self::Receipt, self::TransferIn, self::AdjustmentIn, self::BinMoveIn,
            self::SalesReturn, self::PosReturn, self::CanvasLoad, self::CanvasUnload => true,
            default => false,
        };
    }

    /** Boleh mengurangi saldo. */
    public function isOutbound(): bool
    {
        return match ($this) {
            self::PurchaseReturn, self::TransferOut, self::AdjustmentOut, self::Disposal, self::BinMoveOut,
            self::Sale, self::PosSale, self::PromoIssue, self::CanvasLoad, self::CanvasUnload => true,
            default => false,
        };
    }

    /**
     * Jenis penerimaan yang menghitung ulang HPP (BR-159) lewat `StockLedger::receive()`.
     * Mutasi masuk lain (transfer, pindah rak, penyesuaian) tidak mengubah total nilai produk.
     */
    public function recalculatesCost(): bool
    {
        return in_array($this, [self::Receipt, self::OpeningBalance, self::SalesReturn, self::PosReturn], true);
    }

    /**
     * Keluar yang hanya sah dari batch `available` (BR-102: karantina/ED tidak dialokasikan untuk
     * jual/kirim). Retur prinsipal, pemusnahan, penyesuaian opname, dan pindah rak (termasuk lepas
     * karantina, BR-164) justru dipakai untuk batch karantina/kedaluwarsa.
     */
    public function requiresAvailableBatch(): bool
    {
        return in_array($this, [self::Sale, self::PosSale, self::PromoIssue, self::CanvasLoad, self::TransferOut], true);
    }
}
