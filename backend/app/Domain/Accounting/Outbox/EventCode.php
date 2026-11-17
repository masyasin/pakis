<?php

namespace App\Domain\Accounting\Outbox;

/**
 * Katalog event akuntansi yang boleh ditulis ke outbox (14 §7, kontrak ADR-104). Saat ini hanya
 * event stok Paket A (15 §11.4); Paket B dst. menambah case di sini + 14 §7 + test kontrak payload.
 *
 * Aturan per event (peran akun, kualifier wajib) sengaja ada di enum ini, bukan di Action
 * pemancar, agar AccountingOutbox menolak payload yang tidak lengkap sejak event pertama:
 * event yang sudah terpancar tidak pernah diperbaiki (14 §7 aturan 2).
 */
enum EventCode: string
{
    case InventoryGoodsReceived = 'inventory.goods_received';
    case InventoryPurchaseReturned = 'inventory.purchase_returned';
    case InventoryAdjusted = 'inventory.adjusted';
    case InventoryWrittenOff = 'inventory.written_off';
    case InventoryTransferred = 'inventory.transferred';
    case InventoryOpeningBalance = 'inventory.opening_balance';
    case InventoryTransferVariance = 'inventory.transfer_variance';

    /**
     * Peran akun yang boleh muncul di `lines[].role` (katalog `20` §4.2.1; Paket A tidak
     * memperkenalkan peran baru). Daftar kosong = event tanpa jurnal, `lines` wajib `[]`.
     *
     * @return list<string>
     */
    public function roles(): array
    {
        return match ($this) {
            self::InventoryGoodsReceived => ['inventory', 'goods_received_not_invoiced'],
            // Dua nilai BR-159 (biaya batch vs HPP rata-rata): selisihnya ke inventory_variance.
            self::InventoryPurchaseReturned => ['goods_received_not_invoiced', 'payable', 'inventory', 'inventory_variance'],
            self::InventoryAdjusted => ['inventory', 'inventory_variance'],
            self::InventoryWrittenOff, self::InventoryTransferVariance => ['inventory_loss', 'inventory'],
            self::InventoryOpeningBalance => ['inventory', 'opening_balance_equity'],
            // Mutasi antar lokasi internal tidak berjurnal; rincian untuk jejak di `refs`.
            self::InventoryTransferred => [],
        };
    }

    /** Event berjurnal wajib punya baris; event tanpa jurnal wajib `lines: []`. */
    public function journaled(): bool
    {
        return $this->roles() !== [];
    }

    /**
     * Kualifier di `refs` yang wajib berupa ID positif (15 §11.4).
     *
     * @return list<string>
     */
    public function requiredRefs(): array
    {
        return match ($this) {
            self::InventoryGoodsReceived, self::InventoryPurchaseReturned => ['supplier_id'],
            self::InventoryTransferred, self::InventoryTransferVariance => ['from_stock_location_id', 'to_stock_location_id'],
            default => [],
        };
    }

    /**
     * Kualifier wajib di setiap baris `lines[]`: semua `inventory.*` membawa lokasi stok & produk
     * (dimensi ADR-125) agar backfill Paket F tidak perlu membaca ulang dokumen.
     *
     * @return list<string>
     */
    public function requiredLineKeys(): array
    {
        return str_starts_with($this->value, 'inventory.') ? ['stock_location_id', 'product_id'] : [];
    }
}
