<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Apakah produk/satuannya sudah dipakai dokumen (BR-150, BR-151). Tabel dokumen dibuat task Paket
 * A/B berikutnya (harga T-075, stok T-077, PO T-079, penjualan B); pemeriksaan hanya berjalan bila
 * tabel & kolomnya sudah ada, jadi aturan "terkunci" otomatis aktif saat dokumen pertama tersedia
 * tanpa mengubah kode ini. Nama tabel/kolom selalu dari konstanta, bukan input.
 */
final class ProductUsage
{
    /** Tabel baris dokumen yang menyimpan `product_unit_id` (15 §4.7–§4.10, 16). */
    private const UNIT_TABLES = [
        'price_list_items', 'purchase_order_lines', 'goods_receipt_lines', 'purchase_return_lines',
        'stock_transfer_lines', 'stock_opname_lines', 'sales_order_lines', 'delivery_lines', 'invoice_lines', 'pos_sale_lines',
    ];

    /** Faktor satuan ini sudah dipakai dokumen → tidak boleh diubah (`UNIT_FACTOR_LOCKED`). */
    public static function unitUsed(ProductUnit $unit): bool
    {
        foreach (self::UNIT_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'product_unit_id')
                && DB::table($table)->where('product_unit_id', $unit->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Produk sudah punya mutasi stok → satuan dasar & `tracks_batch`/`tracks_serial` terkunci. */
    public static function hasMovements(Product $product): bool
    {
        return Schema::hasTable('stock_movements')
            && DB::table('stock_movements')->where('product_id', $product->id)->exists();
    }
}
