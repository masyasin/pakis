<?php

namespace App\Domain\Pricing;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Customer\Models\Customer;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Pricing\Data\ResolvedPrice;
use App\Domain\Pricing\Enums\PriceListKind;
use App\Domain\Pricing\Enums\PriceVersionStatus;
use App\Domain\Pricing\Models\PriceList;
use App\Domain\Pricing\Models\PriceListItem;
use App\Domain\Pricing\Models\PriceListVersion;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Money;
use App\Support\Money\Qty;
use Carbon\CarbonInterface;
use LogicException;

/**
 * Penentuan harga (BR-168, ADR-108). Urutan daftar harga:
 * 1. `store` lokasi stok toko — hanya channel `pos` (Paket B);
 * 2. daftar harga kios (`customers.price_list_id`);
 * 3. daftar `area` untuk area kios;
 * 4. `default`.
 * Di tiap list dipakai versi `published`/`superseded` yang rentangnya memuat `at`
 * (`effective_from ≤ at < effective_to`). Item: satuan sama, `min_qty` terbesar ≤ qty. Tidak ada
 * item → lanjut ke list berikutnya (fallback PER PRODUK); tidak ada sama sekali → `PRICE_NOT_FOUND`.
 * List nonaktif dilewati.
 */
final class PriceResolver
{
    public const CHANNEL_POS = 'pos';

    public function resolve(?Customer $customer, Product $product, ProductUnit $unit, Qty $qty, ?CarbonInterface $at = null, string $channel = 'office', ?StockLocation $store = null): ResolvedPrice
    {
        if ($unit->product_id !== $product->id) {
            throw new LogicException('Satuan bukan milik produk ini.');
        }
        $at ??= now();

        foreach ($this->candidateLists($customer, $channel, $store) as $list) {
            $found = $this->fromList($list, $unit, $qty, $at);
            if ($found !== null) {
                return $found;
            }
        }

        throw new ApiError(ErrorCode::PRICE_NOT_FOUND, ['product_id' => $product->id, 'product_unit_id' => $unit->id]);
    }

    /** @return list<PriceList> sesuai urutan BR-168, tanpa duplikat & hanya yang aktif */
    private function candidateLists(?Customer $customer, string $channel, ?StockLocation $store): array
    {
        $lists = [];
        if ($channel === self::CHANNEL_POS && $store !== null) {
            $lists[] = PriceList::query()->where('kind', PriceListKind::Store)->where('stock_location_id', $store->id)->where('is_active', true)->first();
        }
        if ($customer?->price_list_id !== null) {
            $lists[] = PriceList::query()->whereKey($customer->price_list_id)->where('is_active', true)->first();
        }
        if ($customer !== null) {
            $lists[] = PriceList::query()->where('kind', PriceListKind::Area)->where('sales_area_id', $customer->sales_area_id)->where('is_active', true)->orderBy('id')->first();
        }
        $lists[] = PriceList::query()->where('kind', PriceListKind::Default)->where('is_active', true)->first();

        $unique = [];
        foreach ($lists as $list) {
            if ($list !== null) {
                $unique[$list->id] = $list;
            }
        }

        return array_values($unique);
    }

    private function fromList(PriceList $list, ProductUnit $unit, Qty $qty, CarbonInterface $at): ?ResolvedPrice
    {
        $version = self::effectiveVersion($list->id, $at);
        if ($version === null) {
            return null;
        }

        $item = PriceListItem::query()
            ->where('price_list_version_id', $version->id)
            ->where('product_unit_id', $unit->id)
            ->where('min_qty', '<=', (string) $qty)
            ->orderByDesc('min_qty')
            ->first();

        return $item === null ? null : new ResolvedPrice(Money::of($item->price), $version->id, $list->kind, Qty::of($item->min_qty));
    }

    /** Versi yang berlaku pada `at` untuk satu list (dipakai juga halaman daftar harga). */
    public static function effectiveVersion(int $listId, CarbonInterface $at): ?PriceListVersion
    {
        return PriceListVersion::query()
            ->where('price_list_id', $listId)
            ->whereIn('status', [PriceVersionStatus::Published, PriceVersionStatus::Superseded])
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->orderByDesc('effective_from')
            ->first();
    }
}
