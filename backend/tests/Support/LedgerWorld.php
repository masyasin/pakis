<?php

namespace Tests\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Inventory\Actions\SaveStockBin;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\LedgerResult;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Organization\Models\Location;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;

/**
 * Data uji buku stok (T-077). Butuh ReferenceSeeder (satuan, tipe lokasi). Lokasi stok & rak dibuat
 * lewat Action T-068 (Q-01 otomatis); saldo selalu lewat StockLedger, tidak pernah insert langsung.
 */
final class LedgerWorld
{
    public const DATE = '2026-11-16';

    public static function product(string $sku = 'P-01', bool $tracksBatch = true): Product
    {
        $category = ProductCategory::query()->firstOrCreate(['code' => 'LDG'], [
            'name' => 'Kategori buku stok', 'kind' => 'insecticide', 'requires_batch' => true, 'requires_expiry' => true,
            'requires_serial' => false, 'requires_registration' => false, 'default_bin_zone' => 'pesticide', 'sort_order' => 1, 'is_active' => true,
        ]);
        $unit = ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('code', 'bottle')->value('id');
        $product = (new Product)->forceFill([
            'sku' => $sku, 'name' => "Produk {$sku}", 'product_category_id' => $category->id, 'base_unit_id' => $unit,
            'tracks_batch' => $tracksBatch, 'tracks_expiry' => $tracksBatch, 'tracks_serial' => false, 'requires_registration' => false,
            'is_restricted' => false, 'is_sellable' => true, 'is_purchasable' => true, 'status' => 'active',
        ]);
        $product->save();
        (new ProductUnit)->forceFill(['product_id' => $product->id, 'unit_id' => $unit, 'factor' => '1', 'is_base' => true,
            'is_default_sales' => true, 'is_default_purchase' => true, 'is_active' => true])->save();

        return $product;
    }

    /** Gudang baru (dengan rak karantina Q-01 otomatis) di lokasi Fase 0 baru. */
    public static function warehouse(string $code = 'GD-A', ?Location $location = null): StockLocation
    {
        return StockWorld::stockLocation($location ?? StockWorld::location('L-'.$code), $code);
    }

    public static function bin(StockLocation $location, string $code, string $zone = 'general'): StockBin
    {
        return app(SaveStockBin::class)->handle(null, [
            'stock_location_id' => $location->id, 'code' => $code, 'name' => "Rak {$code}", 'zone' => $zone, 'is_active' => true,
        ], null);
    }

    public static function quarantineBin(StockLocation $location): StockBin
    {
        return $location->bins()->where('code', StockBin::QUARANTINE_CODE)->sole();
    }

    public static function source(string $type = 'goods_receipt', int $id = 1, ?int $line = null): Source
    {
        return new Source($type, $id, $line);
    }

    public static function ledger(): StockLedger
    {
        return app(StockLedger::class);
    }

    /** Terima stok lewat ledger (transaksi sendiri), kembalikan batch hasilnya. */
    public static function receive(StockLocation $location, Product $product, string $qty, string $cost = '1000', ?BatchKey $key = null, string $date = self::DATE): StockBatch
    {
        $result = StockLedger::transaction(fn (): LedgerResult => self::ledger()->receive(
            $location, $product, $key ?? new BatchKey('B-001', '2027-12-31'), Qty::of($qty), UnitCost::of($cost), self::source(), $date,
        ));

        return $result->batch();
    }
}
